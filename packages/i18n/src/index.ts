import { createContext, createElement, useContext, useMemo, useState, type ReactNode } from 'react';
import fr from './fr.json';
import en from './en.json';

export type Locale = 'fr' | 'en';
export const DEFAULT_LOCALE: Locale = 'fr';
export const LOCALES: readonly Locale[] = ['fr', 'en'];
export type Messages = Record<string, string>;
export const MESSAGES: Record<Locale, Messages> = { fr, en };

/** Traduit une clé ; remplace {nom} par les variables ; retombe sur le français puis sur la clé. */
export function translate(
  locale: Locale,
  key: string,
  vars: Record<string, string | number> = {},
): string {
  const raw = MESSAGES[locale][key] ?? MESSAGES.fr[key] ?? key;
  return raw.replace(/\{(\w+)\}/g, (_, k: string) => String(vars[k] ?? `{${k}}`));
}

interface Ctx {
  locale: Locale;
  setLocale: (l: Locale) => void;
}
const I18nContext = createContext<Ctx>({ locale: DEFAULT_LOCALE, setLocale: () => undefined });

export function I18nProvider({
  initial = DEFAULT_LOCALE,
  children,
}: {
  initial?: Locale;
  children: ReactNode;
}) {
  const [locale, setLocale] = useState<Locale>(initial);
  const value = useMemo(() => ({ locale, setLocale }), [locale]);
  return createElement(I18nContext.Provider, { value }, children);
}

export function useT() {
  const { locale, setLocale } = useContext(I18nContext);
  return {
    locale,
    setLocale,
    t: (key: string, vars?: Record<string, string | number>) => translate(locale, key, vars),
  };
}
