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
const searchProducts = async (q: string) =>
  (
    await api<{ items: ProductLite[] }>(
      'GET',
      `/app/products?${new URLSearchParams({ q, limit: '8', picker: '1' })}`,
    )
  ).items;

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
  // Résultats et saisie à laquelle ils répondent.
  const [found, setFound] = useState<{ q: string; items: ProductLite[] }>({ q: '', items: [] });
  const items = found.q === q ? found.items : [];
  // Une réponse arrivée après coup appelle la version courante de onPick (panier à jour).
  const latest = useRef(onPick);
  latest.current = onPick;
  useEffect(() => {
    if (q.trim().length < 1) return;
    let live = true;
    const h = window.setTimeout(() => {
      void searchProducts(q).then((r) => live && setFound({ q, items: r }));
    }, 120);
    return () => {
      live = false;
      window.clearTimeout(h);
    };
  }, [q]);
  const pick = (p: ProductLite) => {
    latest.current(p);
    if (clearOnPick) setQ('');
    setFound({ q: '', items: [] });
  };
  /** Un lecteur de codes-barres valide avant que la recherche différée ait répondu. */
  const submit = async () => {
    if (items[0]) return pick(items[0]);
    const term = q;
    if (term.trim() === '') return;
    // Le champ est libéré aussitôt pour le scan suivant.
    if (clearOnPick) setQ('');
    const [first] = await searchProducts(term);
    if (first) latest.current(first);
    else setQ((now) => now || term);
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
          if (e.key === 'Enter') {
            e.preventDefault();
            void submit();
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
