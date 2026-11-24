import { useEffect, useRef, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Button, Modal } from '@sanara/ui';
import { accessHeader, api, ApiError } from '../api/client';

export const fmt = (n: number) => new Intl.NumberFormat('fr-FR').format(n);
export const money = (n: number) => `${fmt(n)} FCFA`;

export interface ProductLite {
  id: string;
  name: string;
  dci: string | null;
  strength: string | null;
  form: string | null;
  sale_price: number;
  vat_bp: number;
  regulatory_status: string;
}

/** Message utilisateur pour une erreur d'API : texte traduit si le code est connu. */
export function useErrorText() {
  const { t } = useT();
  return (e: unknown): string => {
    if (e instanceof ApiError) {
      const k = `err.${e.code}`;
      const msg = t(k);
      if (msg !== k) return msg;
      if (e.code === 'forbidden') return t('errors.forbidden');
    }
    return t('errors.server_error');
  };
}

/** Affiche une page imprimable de l'API (ticket, facture, rapport Z, procès-verbal) sans ouvrir de fenêtre. */
export function DocModal({
  title,
  path,
  onClose,
}: {
  title: string;
  path: string;
  onClose: () => void;
}) {
  const { t } = useT();
  const [html, setHtml] = useState<string | null>(null);
  const frame = useRef<HTMLIFrameElement>(null);
  useEffect(() => {
    const base = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api';
    fetch(`${base}${path}`, { headers: accessHeader() })
      .then((r) => r.text())
      .then(setHtml)
      .catch(() => setHtml(`<p>${t('errors.server_error')}</p>`));
  }, [path, t]);
  return (
    <Modal title={title} onClose={onClose}>
      <iframe
        ref={frame}
        title={title}
        srcDoc={html ?? ''}
        style={{
          width: '100%',
          height: '60vh',
          border: '1px solid var(--sa-line)',
          borderRadius: 10,
          background: '#fff',
        }}
      />
      <div className="actions">
        <Button variant="secondary" onClick={onClose}>
          {t('common.close')}
        </Button>
        <Button onClick={() => frame.current?.contentWindow?.print()} disabled={html === null}>
          {t('pay.print')}
        </Button>
      </div>
    </Modal>
  );
}

/** Recherche de produit par nom, DCI ou code-barres ; Entrée choisit le premier résultat. */
export function ProductSearch({
  label,
  onPick,
  autoFocus,
  clearOnPick = true,
  inputRef,
}: {
  label: string;
  onPick: (p: ProductLite) => void;
  autoFocus?: boolean;
  clearOnPick?: boolean;
  inputRef?: React.RefObject<HTMLInputElement | null>;
}) {
  const [q, setQ] = useState('');
  const [items, setItems] = useState<ProductLite[]>([]);
  useEffect(() => {
    if (q.trim().length < 1) {
      setItems([]);
      return;
    }
    const h = window.setTimeout(() => {
      api<{ items: ProductLite[] }>(
        'GET',
        `/app/products?${new URLSearchParams({ q, limit: '8' })}`,
      ).then((r) => setItems(r.items));
    }, 120);
    return () => window.clearTimeout(h);
  }, [q]);
  const pick = (p: ProductLite) => {
    onPick(p);
    if (clearOnPick) setQ('');
    setItems([]);
  };
  return (
    <div className="picker">
      <input
        ref={inputRef}
        className="search"
        aria-label={label}
        placeholder={label}
        value={q}
        autoFocus={autoFocus}
        onChange={(e) => setQ(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter' && items[0]) {
            e.preventDefault();
            pick(items[0]);
          }
        }}
      />
      {items.length > 0 && (
        <ul className="picker__list" role="listbox">
          {items.map((p) => (
            <li key={p.id}>
              <button type="button" className="picker__item" onClick={() => pick(p)}>
                <b>{p.name}</b>
                <span className="muted">
                  {[p.dci, p.strength, p.form].filter(Boolean).join(' · ')}
                </span>
                <span>{money(p.sale_price)}</span>
              </button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
