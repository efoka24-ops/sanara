/** Formatage piloté par la configuration du pays (T034) : devise, fuseau, téléphone. */
export interface CountryFormat {
  currency: string;
  minorUnit: number;
  locale: string;
  dialCode: string;
  timezone: string;
  phoneLength: number;
}

const SYMBOL: Record<string, string> = { XAF: 'FCFA', XOF: 'FCFA' };

export function formatMoney(amountMinor: number, c: CountryFormat): string {
  const value = amountMinor / 10 ** c.minorUnit;
  const sym = SYMBOL[c.currency];
  if (sym) {
    return `${new Intl.NumberFormat('fr-FR', { maximumFractionDigits: c.minorUnit, minimumFractionDigits: c.minorUnit }).format(value)} ${sym}`;
  }
  return new Intl.NumberFormat(c.locale, {
    style: 'currency',
    currency: c.currency,
    minimumFractionDigits: c.minorUnit,
    maximumFractionDigits: c.minorUnit,
  }).format(value);
}

/** Retourne le numéro au format international sans espace, ou null s'il est invalide. */
export function parsePhone(input: string, c: CountryFormat): string | null {
  const digits = input.replace(/\D+/g, '');
  const dial = c.dialCode.replace('+', '');
  const national =
    digits.startsWith(dial) && digits.length === dial.length + c.phoneLength
      ? digits.slice(dial.length)
      : digits;
  return national.length === c.phoneLength ? `${c.dialCode}${national}` : null;
}

export function formatPhone(e164: string, c: CountryFormat): string {
  const n = e164.replace(c.dialCode, '');
  return n.length === 9
    ? `${c.dialCode} ${n[0]} ${n.slice(1, 3)} ${n.slice(3, 5)} ${n.slice(5, 7)} ${n.slice(7, 9)}`
    : e164;
}

export function formatDate(iso: string, c: CountryFormat): string {
  return new Intl.DateTimeFormat('fr-FR', {
    timeZone: c.timezone,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
  }).format(new Date(iso));
}
