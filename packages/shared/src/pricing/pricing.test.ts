import { describe, expect, it } from 'vitest';
import { computeSale, PricingError } from './index';

const line = (unitPrice: number, qty: number, vatBp = 0, discountBp = 0) => ({
  unitPrice,
  qty,
  vatBp,
  discountBp,
});

describe('computeSale (montants entiers, prix TTC)', () => {
  it('additionne les lignes sans remise ni TVA', () => {
    const r = computeSale({ lines: [line(1500, 21), line(800, 15)], roundTo: 1 });
    expect(r.subtotal).toBe(43500);
    expect(r.total).toBe(43500);
  });

  it('extrait la TVA incluse dans le prix TTC (19,25 %)', () => {
    const r = computeSale({ lines: [line(11925, 1, 1925)], roundTo: 1 });
    expect(r.total).toBe(11925);
    expect(r.vatTotal).toBe(1925);
    expect(r.vatByRate[1925]).toBe(1925);
  });

  it('gère plusieurs taux de TVA', () => {
    const r = computeSale({ lines: [line(1925 + 10000, 1, 1925), line(1000, 1, 0)], roundTo: 1 });
    expect(r.vatByRate[1925]).toBe(1925);
    expect(r.vatByRate[0]).toBe(0);
    expect(r.total).toBe(12925);
  });

  it('applique une remise ligne en pour-dix-mille et la TVA sur le net', () => {
    const r = computeSale({ lines: [line(10000, 1, 1925, 1000)], roundTo: 1 });
    expect(r.lines[0]!.discount).toBe(1000);
    expect(r.lines[0]!.net).toBe(9000);
    expect(r.total).toBe(9000);
    expect(r.vatTotal).toBe(Math.round((9000 * 1925) / 11925));
  });

  it("arrondit le total à 5 unités XAF et trace l'écart", () => {
    const r = computeSale({ lines: [line(1203, 1)], roundTo: 5 });
    expect(r.totalBeforeRounding).toBe(1203);
    expect(r.total).toBe(1205);
    expect(r.rounding).toBe(2);
    expect(computeSale({ lines: [line(1202, 1)], roundTo: 5 }).total).toBe(1200);
  });

  it("plafonne la remise selon le droit de l'utilisateur", () => {
    expect(() =>
      computeSale({ lines: [line(10000, 1, 0, 600)], roundTo: 1, maxDiscountBp: 500 }),
    ).toThrow(PricingError);
    expect(() =>
      computeSale({ lines: [line(10000, 1, 0, 500)], roundTo: 1, maxDiscountBp: 500 }),
    ).not.toThrow();
  });

  it('applique une remise globale répartie sans perdre de franc', () => {
    const r = computeSale({
      lines: [line(3333, 1), line(3333, 1), line(3334, 1)],
      roundTo: 1,
      globalDiscountBp: 1000,
    });
    expect(r.discountTotal).toBe(1000);
    expect(r.lines.reduce((s, l) => s + l.net, 0)).toBe(9000);
  });

  it('refuse quantités et prix invalides ou non entiers', () => {
    expect(() => computeSale({ lines: [line(-1, 1)], roundTo: 1 })).toThrow(PricingError);
    expect(() => computeSale({ lines: [line(100.5, 1)], roundTo: 1 })).toThrow(PricingError);
    expect(() => computeSale({ lines: [line(100, 0)], roundTo: 1 })).toThrow(PricingError);
  });

  it('calcule le rendu de monnaie', () => {
    const r = computeSale({ lines: [line(10750, 1)], roundTo: 1, tendered: 11000 });
    expect(r.change).toBe(250);
    expect(() =>
      computeSale({ lines: [line(10750, 1)], roundTo: 1, tendered: 10000 }),
    ).not.toThrow();
    expect(computeSale({ lines: [line(10750, 1)], roundTo: 1, tendered: 10000 }).change).toBe(-750);
  });
});
