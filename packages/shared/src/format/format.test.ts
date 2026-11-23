import { describe, expect, it } from 'vitest';
import { formatDate, formatMoney, formatPhone, parsePhone, type CountryFormat } from './index';

const cm: CountryFormat = {
  currency: 'XAF',
  minorUnit: 0,
  locale: 'fr-CM',
  dialCode: '+237',
  timezone: 'Africa/Douala',
  phoneLength: 9,
};

describe('formatMoney', () => {
  it('affiche les XAF sans décimale avec espace fine', () => {
    expect(formatMoney(53200, cm).replace(/\s/g, ' ')).toBe('53 200 FCFA');
  });
  it('gère une devise à 2 décimales', () => {
    const eur: CountryFormat = { ...cm, currency: 'EUR', minorUnit: 2, locale: 'fr-FR' };
    expect(formatMoney(1250, eur).replace(/\s/g, ' ')).toMatch(/12,50\s?€/);
  });
});
describe('téléphone', () => {
  it('normalise vers le format international', () => {
    expect(parsePhone('690 00 00 00', cm)).toBe('+237690000000');
    expect(parsePhone('+237 690 000 000', cm)).toBe('+237690000000');
    expect(parsePhone('12', cm)).toBeNull();
  });
  it("formate pour l'affichage", () => {
    expect(formatPhone('+237690000000', cm)).toBe('+237 6 90 00 00 00');
  });
});
describe('formatDate', () => {
  it('utilise le fuseau du pays', () => {
    expect(formatDate('2026-11-23T23:30:00Z', cm)).toBe('24/11/2026');
  });
});
