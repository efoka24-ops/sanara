import { lazy, Suspense, useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { Art } from '../app/BrandPanel';
import {
  directionsLink,
  dutyLabel,
  hour,
  statusLabel,
  telLink,
  type PublicSheet,
} from '../lib/publicSearch';

const PharmacyMap = lazy(() => import('../app/PharmacyMap'));
const WEEKDAYS = [1, 2, 3, 4, 5, 6, 7];

/** US19 : fiche publique d'une pharmacie (maquette P3). */
export function PublicPharmacyPage() {
  const { id } = useParams();
  const { t, locale } = useT();
  const [sheet, setSheet] = useState<PublicSheet | null>(null);
  const [error, setError] = useState<'missing' | 'failed' | null>(null);

  useEffect(() => {
    let live = true;
    setSheet(null);
    setError(null);
    api<PublicSheet>('GET', `/public/pharmacies/${encodeURIComponent(id ?? '')}`)
      .then((r) => live && setSheet(r))
      .catch(
        (e) => live && setError(e instanceof ApiError && e.status === 404 ? 'missing' : 'failed'),
      );
    return () => {
      live = false;
    };
  }, [id]);

  if (error)
    return (
      <div className="sheet">
        <p role="alert" className="form-error">
          {t(error === 'missing' ? 'sheet.missing' : 'errors.server_error')}
        </p>
        <Link to="/pharmacies">{t('home.find')}</Link>
      </div>
    );
  if (!sheet) return <p className="sheet muted">{t('common.loading')}</p>;

  const today = ((new Date().getDay() + 6) % 7) + 1;
  const hasPoint = sheet.latitude !== null && sheet.longitude !== null;

  return (
    <article className="sheet">
      <div className="sheet__banner">
        <Art name="officine" />
      </div>
      <p className="sheet__badges">
        <Badge tone={sheet.open ? 'ok' : sheet.open === false ? 'wait' : 'neutral'}>
          {statusLabel(t, locale, sheet)}
        </Badge>
        {sheet.duty_badge && <Badge tone="wait">{t(`duty.badge.${sheet.duty_badge}`)}</Badge>}
        <Badge tone="ok">{t('sheet.verified')}</Badge>
      </p>
      <h1>{sheet.pharmacy_name}</h1>
      <p className="muted">
        {[sheet.address, sheet.district, sheet.city, sheet.region].filter(Boolean).join(' · ')}
      </p>
      <p className="sheet__actions">
        <a className="sa-btn" href={directionsLink(sheet)} target="_blank" rel="noreferrer">
          {t('pub.directions')}
        </a>
        {sheet.phone && (
          <a className="sa-btn sa-btn--secondary" href={telLink(sheet.phone)}>
            {t('pub.call')}
          </a>
        )}
      </p>

      <div className="sheet__grid">
        <section className="panel">
          <h2>{t('sheet.hours')}</h2>
          {sheet.week === null ? (
            <p className="muted">{t('pub.hoursUnknown')}</p>
          ) : (
            <dl className="sheet__week">
              {WEEKDAYS.map((d) => {
                const open = sheet.week!.find((w) => w.weekday === d);
                return (
                  <div key={d} className={d === today ? 'is-today' : undefined}>
                    <dt>{t(`day.${d}`)}</dt>
                    <dd>
                      {open
                        ? `${hour(open.opens, locale)} – ${hour(open.closes, locale)}`
                        : t('pub.closed')}
                    </dd>
                  </div>
                );
              })}
            </dl>
          )}
        </section>
        <section className="panel">
          <h2>{t('sheet.duties')}</h2>
          {sheet.duties.length === 0 ? (
            <p className="muted">{t('sheet.noDuty')}</p>
          ) : (
            <ul>
              {sheet.duties.map((d) => (
                <li key={d.starts}>{dutyLabel(t, locale, d)}</li>
              ))}
            </ul>
          )}
        </section>
        <section className="panel">
          <h2>{t('sheet.services')}</h2>
          {sheet.services.length === 0 ? (
            <p className="muted">{t('sheet.notGiven')}</p>
          ) : (
            <p className="sheet__tags">
              {sheet.services.map((s) => (
                <span key={s}>{t(`service.${s}`)}</span>
              ))}
            </p>
          )}
        </section>
        <section className="panel">
          <h2>{t('sheet.insurances')}</h2>
          {sheet.insurances.length === 0 ? (
            <p className="muted">{t('sheet.notGiven')}</p>
          ) : (
            <ul>
              {sheet.insurances.map((s) => (
                <li key={s}>{s}</li>
              ))}
            </ul>
          )}
        </section>
        <section className="panel">
          <h2>{t('sheet.contact')}</h2>
          {sheet.phone && (
            <p>
              <a href={telLink(sheet.phone)}>{sheet.phone}</a>
            </p>
          )}
          {sheet.email && (
            <p>
              <a href={`mailto:${sheet.email}`}>{sheet.email}</a>
            </p>
          )}
          {!sheet.phone && !sheet.email && <p className="muted">{t('sheet.notGiven')}</p>}
        </section>
        {hasPoint && (
          <section className="sheet__map">
            <Suspense fallback={<p className="muted">{t('pub.mapLoading')}</p>}>
              <PharmacyMap
                items={[sheet]}
                position={null}
                selected={sheet.id}
                onSelect={() => undefined}
                onOpen={() => undefined}
              />
            </Suspense>
          </section>
        )}
      </div>
    </article>
  );
}
