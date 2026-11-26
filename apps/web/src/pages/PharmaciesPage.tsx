import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field } from '@sanara/ui';
import { api } from '../api/client';

interface Pharmacy {
  id: string;
  pharmacy_name: string;
  region: string;
  city: string;
  district: string | null;
  address: string | null;
  phone: string | null;
}
interface Result {
  items: Pharmacy[];
  total: number;
}

const PAGE = 20;
const place = (p: Pharmacy) => [p.district, p.city, p.region].filter(Boolean).join(', ');
const mapLink = (p: Pharmacy) =>
  `https://www.openstreetmap.org/search?query=${encodeURIComponent(
    [p.address, p.district, p.city].filter(Boolean).join(', ') || p.pharmacy_name,
  )}`;

/** US18 : recherche publique des pharmacies par nom, ville ou quartier. */
export function PharmaciesPage() {
  const { t } = useT();
  const [params, setParams] = useSearchParams();
  const q = params.get('q') ?? '';
  const [items, setItems] = useState<Pharmacy[]>([]);
  const [total, setTotal] = useState<number | null>(null);
  const [failed, setFailed] = useState(false);

  async function load(offset: number, current: () => boolean) {
    const query = new URLSearchParams({ q, limit: String(PAGE), offset: String(offset) });
    try {
      const r = await api<Result>('GET', `/public/pharmacies?${query}`);
      if (!current()) return;
      setItems((before) => (offset === 0 ? r.items : [...before, ...r.items]));
      setTotal(r.total);
      setFailed(false);
    } catch {
      if (current()) setFailed(true);
    }
  }

  useEffect(() => {
    // Une réponse arrivée après une nouvelle saisie est ignorée.
    let live = true;
    const wait = window.setTimeout(() => load(0, () => live), 250);
    return () => {
      live = false;
      window.clearTimeout(wait);
    };
  }, [q]);

  return (
    <div className="directory">
      <h1>{t('pub.title')}</h1>
      <Field
        label={t('pub.search')}
        type="search"
        value={q}
        onChange={(e) => setParams(e.target.value ? { q: e.target.value } : {}, { replace: true })}
      />
      {failed && (
        <div role="alert" className="form-error">
          {t('errors.server_error')}
        </div>
      )}
      <p className="muted" aria-live="polite">
        {total === null ? t('pub.loading') : t('pub.count', { n: total })}
      </p>
      <ul className="directory__list">
        {items.map((p) => (
          <li key={p.id} className="panel">
            <h2>{p.pharmacy_name}</h2>
            <p className="muted">{[p.address, place(p)].filter(Boolean).join(' · ')}</p>
            <p className="directory__actions">
              {p.phone && (
                <a href={`tel:${p.phone.replace(/\s/g, '')}`}>
                  {t('pub.call')} {p.phone}
                </a>
              )}
              <a href={mapLink(p)} target="_blank" rel="noreferrer">
                {t('pub.directions')}
              </a>
            </p>
          </li>
        ))}
      </ul>
      {total !== null && items.length < total && (
        <Button variant="secondary" onClick={() => load(items.length, () => true)}>
          {t('pub.more')}
        </Button>
      )}
    </div>
  );
}
