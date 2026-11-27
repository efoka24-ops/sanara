import { useEffect, useState } from 'react';
import { Link, useSearchParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { api } from '../api/client';
import {
  directionsLink,
  distinct,
  dutyLabel,
  telLink,
  usePlaces,
  type SearchResult,
} from '../lib/publicSearch';

const PERIODS = ['tonight', 'week', 'weekend'];

/** US19 : pharmacies de garde par ville et période (maquette P9). */
export function DutiesPage() {
  const { t, locale } = useT();
  const [params, setParams] = useSearchParams();
  const city = params.get('city') ?? '';
  const asked = params.get('period') ?? '';
  const period = PERIODS.includes(asked) ? asked : 'tonight';
  const places = usePlaces();
  const [result, setResult] = useState<SearchResult | null>(null);
  const [failed, setFailed] = useState(false);

  useEffect(() => {
    let live = true;
    setResult(null);
    const query = new URLSearchParams({ duty: period, limit: '100' });
    if (city) query.set('city', city);
    api<SearchResult>('GET', `/public/pharmacies?${query}`)
      .then((r) => {
        if (!live) return;
        setResult(r);
        setFailed(false);
      })
      .catch(() => live && setFailed(true));
    return () => {
      live = false;
    };
  }, [city, period]);

  function change(name: string, value: string) {
    const next = new URLSearchParams(params);
    if (value) next.set(name, value);
    else next.delete(name);
    setParams(next, { replace: true });
  }

  const cities = distinct([...places.map((p) => p.city), city || null]);

  return (
    <div className="duties">
      <p className="eyebrow">{t('duty.eyebrow')}</p>
      <h1>{city ? t('duty.titleCity', { city }) : t('duty.title')}</h1>
      <div className="duties__bar">
        <div className="duties__tabs" role="group" aria-label={t('duty.period')}>
          {PERIODS.map((p) => (
            <button
              key={p}
              type="button"
              className={`chip${p === period ? ' chip--on' : ''}`}
              aria-pressed={p === period}
              onClick={() => change('period', p)}
            >
              {t(`duty.${p}`)}
            </button>
          ))}
        </div>
        <label className="pick">
          <span>{t('duty.city')}</span>
          <select value={city} onChange={(e) => change('city', e.target.value)}>
            <option value="">{t('duty.allCities')}</option>
            {cities.map((c) => (
              <option key={c}>{c}</option>
            ))}
          </select>
        </label>
      </div>
      {failed && (
        <div role="alert" className="form-error">
          {t('errors.server_error')}
        </div>
      )}
      <p className="muted" aria-live="polite">
        {result === null ? t('pub.loading') : t('pub.count', { n: result.total })}
      </p>
      {result?.total === 0 && <p className="notice">{t('duty.none')}</p>}
      <ul className="duties__list">
        {result?.items.map((p) => (
          <li key={p.id} className="panel">
            <h2>
              <Link to={`/pharmacies/${p.id}`}>{p.pharmacy_name}</Link>
            </h2>
            <p className="muted">{[p.district, p.city].filter(Boolean).join(', ')}</p>
            {p.duty && <p className="duties__when">{dutyLabel(t, locale, p.duty)}</p>}
            <p className="duties__actions">
              {p.phone && (
                <a className="sa-btn" href={telLink(p.phone)}>
                  {t('pub.call')}
                </a>
              )}
              <a
                className="sa-btn sa-btn--secondary"
                href={directionsLink(p)}
                target="_blank"
                rel="noreferrer"
              >
                {t('pub.directions')}
              </a>
            </p>
          </li>
        ))}
      </ul>
    </div>
  );
}
