/**
 * Moteur de prix pur (T035). Montants en entiers (plus petite unité de la devise), prix unitaires TTC.
 * Taux en pour-dix-mille (1925 = 19,25 %). Aucune virgule flottante pour les montants.
 */
export class PricingError extends Error {
  constructor(public readonly code: 'invalid_line' | 'discount_over_limit' | 'invalid_discount') {
    super(code);
  }
}

export interface SaleLineInput {
  unitPrice: number;
  qty: number;
  vatBp: number;
  discountBp: number;
}
export interface SaleInput {
  lines: SaleLineInput[];
  roundTo: number;
  maxDiscountBp?: number;
  globalDiscountBp?: number;
  tendered?: number;
}
export interface SaleLineResult {
  gross: number;
  discount: number;
  net: number;
  vat: number;
}
export interface SaleResult {
  lines: SaleLineResult[];
  subtotal: number;
  discountTotal: number;
  vatTotal: number;
  vatByRate: Record<number, number>;
  totalBeforeRounding: number;
  rounding: number;
  total: number;
  change?: number;
}

const bp = (amount: number, rate: number) => Math.round((amount * rate) / 10000);

export function computeSale(input: SaleInput): SaleResult {
  const { lines, roundTo, maxDiscountBp, globalDiscountBp = 0 } = input;
  for (const l of lines) {
    if (
      !Number.isInteger(l.unitPrice) ||
      l.unitPrice < 0 ||
      !(l.qty > 0) ||
      !Number.isInteger(l.vatBp) ||
      l.vatBp < 0
    )
      throw new PricingError('invalid_line');
    if (!Number.isInteger(l.discountBp) || l.discountBp < 0 || l.discountBp > 10000)
      throw new PricingError('invalid_discount');
    if (maxDiscountBp !== undefined && l.discountBp > maxDiscountBp)
      throw new PricingError('discount_over_limit');
  }
  if (globalDiscountBp < 0 || globalDiscountBp > 10000) throw new PricingError('invalid_discount');
  if (maxDiscountBp !== undefined && globalDiscountBp > maxDiscountBp)
    throw new PricingError('discount_over_limit');

  const base = lines.map((l) => {
    const gross = Math.round(l.unitPrice * l.qty);
    const discount = bp(gross, l.discountBp);
    return { gross, discount, net: gross - discount, vatBp: l.vatBp };
  });

  // Remise globale : répartie au prorata, le reliquat d'arrondi est porté par la dernière ligne.
  const netSum = base.reduce((s, l) => s + l.net, 0);
  const globalTotal = bp(netSum, globalDiscountBp);
  let allocated = 0;
  base.forEach((l, i) => {
    const share =
      i === base.length - 1
        ? globalTotal - allocated
        : netSum === 0
          ? 0
          : Math.round((globalTotal * l.net) / netSum);
    allocated += share;
    l.discount += share;
    l.net -= share;
  });

  const vatByRate: Record<number, number> = {};
  const out: SaleLineResult[] = base.map((l) => {
    const vat = Math.round((l.net * l.vatBp) / (10000 + l.vatBp));
    vatByRate[l.vatBp] = (vatByRate[l.vatBp] ?? 0) + vat;
    return { gross: l.gross, discount: l.discount, net: l.net, vat };
  });

  const subtotal = out.reduce((s, l) => s + l.gross, 0);
  const discountTotal = out.reduce((s, l) => s + l.discount, 0);
  const totalBeforeRounding = out.reduce((s, l) => s + l.net, 0);
  const total =
    roundTo > 1 ? Math.round(totalBeforeRounding / roundTo) * roundTo : totalBeforeRounding;
  const result: SaleResult = {
    lines: out,
    subtotal,
    discountTotal,
    vatTotal: out.reduce((s, l) => s + l.vat, 0),
    vatByRate,
    totalBeforeRounding,
    rounding: total - totalBeforeRounding,
    total,
  };
  if (input.tendered !== undefined) result.change = input.tendered - total;
  return result;
}
