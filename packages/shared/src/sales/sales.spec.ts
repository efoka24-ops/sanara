import { describe, expect, it } from 'vitest';
import cases from '../../fixtures/sales-cases.json';
import { ratio, saleLine, saleNumber, settle, type PaymentInput } from './index';

describe('calculs de vente (mêmes cas que les tests PHP)', () => {
  it.each(cases.lines)('ligne %#', (c) => {
    expect(saleLine(c.qty, c.unitPrice, c.discountBp, c.vatBp)).toEqual(c.expect);
  });
  it.each(cases.settle)('règlement %#', (c) => {
    expect(settle(c.total, c.payments as PaymentInput[])).toEqual(c.expect);
  });
  it('arrondi moitié supérieure sans flottant', () => {
    expect(ratio(5, 1, 10)).toBe(1);
    expect(ratio(4, 1, 10)).toBe(0);
  });
  it('numéros par poste : jamais de collision entre postes', () => {
    const seen = new Set<string>();
    for (const station of ['A', 'B', 'caisse 3'])
      for (let n = 1; n <= 50; n++) seen.add(saleNumber(station, 2026, n));
    expect(seen.size).toBe(150);
    expect(saleNumber('caisse 1', 2026, 7)).toBe('V-CAISSE1-2026-00007');
  });
});
