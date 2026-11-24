/** Calculs de vente en entiers (FCFA, prix TTC). Miroir exact de apps/api/app/Modules/Sales/SaleMath.php. */

/** Arrondi à l'entier le plus proche, moitié vers le haut, sans flottant. */
export function ratio(amount: number, num: number, den: number): number {
  return Math.floor((amount * num * 2 + den) / (2 * den));
}

export interface LineResult {
  gross: number;
  discount: number;
  total: number;
  vat: number;
}

export function saleLine(
  qty: number,
  unitPrice: number,
  discountBp: number,
  vatBp: number,
): LineResult {
  const gross = qty * unitPrice;
  const discount = ratio(gross, discountBp, 10000);
  const total = gross - discount;
  return { gross, discount, total, vat: ratio(total, vatBp, 10000 + vatBp) };
}

export type PayMethod = 'cash' | 'momo_mtn' | 'momo_orange' | 'card' | 'credit';
export interface PaymentInput {
  method: PayMethod;
  amount: number;
}
export interface Settlement {
  applied: { method: PayMethod; amount: number; tendered: number }[];
  change: number;
}

/** Espèces : montant remis par le client, le rendu s'y calcule. Autres modes : montant exact. null si le règlement est incohérent. */
export function settle(total: number, payments: PaymentInput[]): Settlement | null {
  let nonCash = 0;
  let cashGiven = 0;
  for (const p of payments) {
    if (!Number.isInteger(p.amount) || p.amount <= 0) return null;
    if (p.method === 'cash') cashGiven += p.amount;
    else nonCash += p.amount;
  }
  if (nonCash > total || nonCash + cashGiven < total) return null;
  const cashDue = total - nonCash;
  const change = cashGiven - cashDue;
  if (cashGiven === 0 && change !== 0) return null;
  let left = cashDue;
  const applied = payments.map((p) => {
    if (p.method === 'cash') {
      const take = Math.min(p.amount, left);
      left -= take;
      return { method: p.method, amount: take, tendered: p.amount };
    }
    return { method: p.method, amount: p.amount, tendered: p.amount };
  });
  return { applied, change };
}

/** Numéro de ticket : V-{poste}-{année}-{compteur sur 5 chiffres}. Un compteur par poste et par année, donc aucune collision entre postes. */
export function saleNumber(station: string, year: number, counter: number): string {
  const s =
    station
      .replace(/[^A-Za-z0-9]/g, '')
      .toUpperCase()
      .slice(0, 8) || 'CAISSE1';
  return `V-${s}-${year}-${String(counter).padStart(5, '0')}`;
}
