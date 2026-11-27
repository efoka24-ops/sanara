import { lazy, Suspense, useEffect, useState } from 'react';
import { useLocation, useSearchParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field } from '@sanara/ui';
import { api } from '../api/client';
import { PlaceSelects } from '../app/PlaceSelects';
import {
  directionsLink,
  distanceLabel,
  locate,
  statusLabel,
  telLink,
  usePlaces,
  type Position,
  type PublicPharmacy,
  type SearchResult,
} from '../lib/publicSearch';

// La carte est chargée après la liste (réseaux lents).
const PharmacyMap = lazy(() => import('../app/PharmacyMap'));
const PAGE = 50;

/** US18 : recherche par nom, ville ou quartier, liste et carte, tri par distance. */
export function PharmaciesPage() {
  const { t, locale } = useT();
  const [params, setParams] = useSearchParams();
  const q = params.get('q') ?? '';
  const region = params.get('region') ?? '';
  const department = params.get('department') ?? '';
  const near = params.get('near') ?? '';
  const openOnly = params.get('open') === '1';
  // La position n'apparaît pas dans l'adresse : elle ne se partage pas avec le lien.
  const state = useLocation().state as { position?: Position; denied?: boolean } | null;
  const [position, setPosition] = useState<Position | null>(state?.position ?? null);
  const [denied, setDenied] = useState(state?.denied ?? false);
  const places = usePlaces();
  const [result, setResult] = useState<SearchResult | null>(null);
  const [failed, setFailed] = useState(false);
  const [selected, setSelected] = useState<string | null>(null);

  function change(values: Record<string, string>) {
    const next = new URLSearchParams(params);
    for (const [k, v] of Object.entries(values)) {
      if (v) next.set(k, v);
      else next.delete(k);
    }
    setParams(next, { replace: true, state: position ? { position } : null });
  }

  async function load(offset: number, current: () => boolean) {
    const query = new URLSearchParams({ q, limit: String(PAGE), offset: String(offset) });
    if (region) query.set('region', region);
    if (department) query.set('department', department);
    if (openOnly) query.set('open', '1');
    if (position) {
      query.set('lat', String(position.lat));
      query.set('lng', String(position.lng));
    } else if (near) query.set('near', near);
    try {
      const r = await api<SearchResult>('GET', `/public/pharmacies?${query}`);
      if (!current()) return;
      setResult((before) =>
        offset === 0 || !before ? r : { ...r, items: [...before.items, ...r.items] },
      );
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
  }, [q, region, department, near, openOnly, position]);

  async function nearest() {
    if (position) {
      setPosition(null);
      return;
    }
    try {
      setPosition(await locate());
      setDenied(false);
    } catch {
      setDenied(true);
    }
  }

  function choose(id: string) {
    setSelected(id);
    document.getElementById(`pharmacy-${id}`)?.scrollIntoView({ block: 'nearest' });
  }

  const items: PublicPharmacy[] = result?.items ?? [];

  return (
    <div className="finder">
      <div className="finder__side">
        <h1>{t('pub.title')}</h1>
        <Field
          label={t('pub.search')}
          type="search"
          value={q}
          onChange={(e) => change({ q: e.target.value })}
        />
        <div className="finder__places">
          <PlaceSelects
            places={places}
            region={region}
            department={department}
            onChange={(r, d) => change({ region: r, department: d })}
          />
        </div>
        <div className="finder__filters">
          <button
            type="button"
            className={`chip${openOnly ? ' chip--on' : ''}`}
            aria-pressed={openOnly}
            onClick={() => change({ open: openOnly ? '' : '1' })}
          >
            {t('pub.openNow')}
          </button>
          <button
            type="button"
            className={`chip${position ? ' chip--on' : ''}`}
            aria-pressed={!!position}
            onClick={nearest}
          >
            {t('pub.nearest')}
          </button>
        </div>
        {(denied || near) && !position && (
          <>
            {denied && (
              <p className="notice" role="status">
                {t('pub.denied')}
              </p>
            )}
            <label className="pick">
              <span>{t('pub.district')}</span>
              <input
                list="districts"
                value={near}
                onChange={(e) => change({ near: e.target.value })}
              />
              <datalist id="districts">
                {places.map((p) => (
                  <option key={`${p.city}/${p.district}`} value={p.district ?? ''}>
                    {p.city}
                  </option>
                ))}
              </datalist>
            </label>
          </>
        )}
        {failed && (
          <div role="alert" className="form-error">
            {t('errors.server_error')}
          </div>
        )}
        <p className="muted" aria-live="polite">
          {result === null ? t('pub.loading') : t('pub.count', { n: result.total })}
          {result?.origin === 'district' && ` · ${t('pub.fromDistrict', { district: near })}`}
        </p>
        <ul className="finder__list">
          {items.map((p) => (
            <li
              key={p.id}
              id={`pharmacy-${p.id}`}
              className={`panel found${p.id === selected ? ' found--on' : ''}`}
            >
              <div className="found__head">
                <h2>
                  <button type="button" onClick={() => setSelected(p.id)}>
                    {p.pharmacy_name}
                  </button>
                </h2>
                {p.distance_m !== null && <b>{distanceLabel(p.distance_m, locale)}</b>}
              </div>
              <p className="muted">
                {[p.address, p.district, p.city, p.region].filter(Boolean).join(' · ')}
              </p>
              <Badge tone={p.open ? 'ok' : p.open === false ? 'wait' : 'neutral'}>
                {statusLabel(t, locale, p)}
              </Badge>
              <p className="found__actions">
                {p.phone && (
                  <a href={telLink(p.phone)}>
                    {t('pub.call')} {p.phone}
                  </a>
                )}
                <a href={directionsLink(p)} target="_blank" rel="noreferrer">
                  {t('pub.directions')}
                </a>
              </p>
            </li>
          ))}
        </ul>
        {result !== null && items.length < result.total && (
          <Button variant="secondary" onClick={() => load(items.length, () => true)}>
            {t('pub.more')}
          </Button>
        )}
      </div>
      <div className="finder__map">
        {result !== null && (
          <Suspense fallback={<p className="muted">{t('pub.mapLoading')}</p>}>
            <PharmacyMap
              items={items}
              position={position}
              selected={selected}
              onSelect={choose}
              onOpen={choose}
            />
          </Suspense>
        )}
      </div>
    </div>
  );
}
