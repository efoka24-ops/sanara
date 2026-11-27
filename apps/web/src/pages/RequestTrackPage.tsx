import { useEffect, useRef, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import QRCode from 'qrcode';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal } from '@sanara/ui';
import { api } from '../api/client';
import { money, useErrorText } from '../lib/common';
import { statusLabel, telLink, type PublicPharmacy } from '../lib/publicSearch';
import {
  MAX_TOTAL_BYTES,
  OPEN_STATUSES,
  shrink,
  toBase64,
  when,
  type PatientView,
} from '../lib/requests';

const STEPS = ['received', 'processing', 'quoted', 'accepted', 'ready', 'picked_up'] as const;
const STEP_OF: Record<string, number> = {
  received: 0,
  photo_needed: 0,
  processing: 1,
  quoted: 2,
  accepted: 3,
  ready: 4,
  picked_up: 5,
};

/** Code de retrait en QR, dessiné sur place : rien ne part vers un service tiers. */
function PickupQr({ code }: { code: string }) {
  const canvas = useRef<HTMLCanvasElement>(null);
  useEffect(() => {
    if (canvas.current) void QRCode.toCanvas(canvas.current, code, { width: 148, margin: 1 });
  }, [code]);
  return <canvas ref={canvas} aria-hidden="true" />;
}

/** US26, US23 : suivi d'une demande, devis et code de retrait (maquettes P8, P5, P7). */
export function RequestTrackPage() {
  const { code = '' } = useParams();
  const arrival = useLocation().state as { sent?: boolean; limited?: boolean } | null;
  const sent = arrival?.sent === true;
  const { t, locale } = useT();
  const errText = useErrorText();
  const [view, setView] = useState<PatientView | null>(null);
  const [missing, setMissing] = useState(false);
  const [chosen, setChosen] = useState<string[] | null>(null);
  const [refusing, setRefusing] = useState<string | null>(null);
  const [confirmUrgent, setConfirmUrgent] = useState(false);
  const [message, setMessage] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [copied, setCopied] = useState(false);

  const path = `/public/requests/${encodeURIComponent(code)}`;
  const open = view !== null && OPEN_STATUSES.includes(view.status);

  useEffect(() => {
    let live = true;
    const load = () =>
      api<PatientView>('GET', path)
        .then((v) => live && setView(v))
        .catch(() => live && setMissing(true));
    void load();
    // Rafraîchi tant que la demande avance, seulement si la page est visible.
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load();
    }, 15000);
    return () => {
      live = false;
      window.clearInterval(timer);
    };
  }, [path]);

  useEffect(() => {
    if (view?.status === 'quoted' && chosen === null)
      setChosen(view.quote!.lines.filter((l) => l.available).map((l) => l.id));
  }, [view, chosen]);

  async function act(method: string, suffix: string, body: unknown) {
    setBusy(true);
    setError(null);
    try {
      setView(await api<PatientView>(method, `${path}${suffix}`, body));
      return true;
    } catch (e) {
      setError(errText(e));
      return false;
    } finally {
      setBusy(false);
    }
  }

  async function addPages(list: FileList | null) {
    if (!list?.length) return;
    const blobs = await Promise.all(
      [...list].map(async (f) => ({ name: f.name, blob: await shrink(f) })),
    );
    if (blobs.reduce((n, b) => n + b.blob.size, 0) > MAX_TOTAL_BYTES)
      return setError(t('send.tooHeavy'));
    const documents = await Promise.all(
      blobs.map(async (b) => ({ name: b.name, base64: await toBase64(b.blob) })),
    );
    await act('POST', '/pages', { documents });
  }

  if (missing)
    return (
      <div className="track">
        <p role="alert" className="form-error">
          {t('track.missing')}
        </p>
        <Link to="/pharmacies">{t('home.find')}</Link>
      </div>
    );
  if (!view) return <p className="track muted">{t('common.loading')}</p>;

  const step = STEP_OF[view.status] ?? -1;
  const selected = view.quote?.lines.filter((l) => chosen?.includes(l.id)) ?? [];
  const selectedTotal = selected.reduce((n, l) => n + l.total, 0);
  const pharmacy = view.pharmacy as unknown as PublicPharmacy;
  const link = window.location.href;

  return (
    <div className="track">
      <p className="eyebrow">{t('track.eyebrow', { number: view.number })}</p>
      <h1>{t(`track.status.${view.status}`)}</h1>
      <p className="muted">
        {view.pharmacy.name}
        {view.pharmacy.phone && (
          <>
            {' · '}
            <a href={telLink(view.pharmacy.phone)}>
              {t('pub.call')} {view.pharmacy.phone}
            </a>
          </>
        )}
      </p>

      {view.advice === 'emergency' && (
        <div role="alert" className="form-error track__emergency">
          <b>{t('track.emergencyTitle')}</b>
          <p>{t('track.emergency')}</p>
        </div>
      )}
      {sent && view.status === 'received' && (
        <p role="status" className="notice">
          {t('track.sent')}
        </p>
      )}

      {step >= 0 && (
        <ol className="track__steps">
          {STEPS.map((s, i) => (
            <li key={s} className={i < step ? 'is-done' : i === step ? 'is-current' : undefined}>
              {t(`track.step.${s}`)}
            </li>
          ))}
        </ol>
      )}

      {(view.status === 'received' || view.status === 'processing') && (
        <p className="panel">
          {view.status === 'processing'
            ? t('track.processing')
            : view.pharmacy.open === false
              ? t('track.waitClosed', { status: statusLabel(t, locale, pharmacy) })
              : view.pharmacy.online
                ? t('track.online')
                : t('track.offline')}
        </p>
      )}
      {arrival?.limited && <p className="notice">{t('track.limited')}</p>}
      {(view.escalation === 'alerted' || view.escalation === 'texted') && (
        <p role="status" className="notice">
          {t(`track.escalation.${view.escalation}`)}
        </p>
      )}
      {(view.escalation === 'exhausted' || view.escalation === 'closed') && (
        <div role="alert" className="form-error track__emergency">
          <b>{t(`track.escalation.${view.escalation}`)}</b>
          <p>
            {view.pharmacy.phone && (
              <a href={telLink(view.pharmacy.phone)}>
                {t('pub.call')} {view.pharmacy.phone}
              </a>
            )}{' '}
            · <Link to="/gardes">{t('home.duties')}</Link>
          </p>
        </div>
      )}
      {view.status === 'photo_needed' && (
        <p role="alert" className="notice">
          {t('track.photoNeeded')}
        </p>
      )}

      {view.status === 'quoted' && view.quote && (
        <section className="panel stack">
          <h2>{t('track.quoteTitle', { number: view.number })}</h2>
          <ul className="quote">
            {view.quote.lines.map((l) => (
              <li key={l.id} className={l.available ? undefined : 'is-off'}>
                <label className="check">
                  <input
                    type="checkbox"
                    disabled={!l.available}
                    checked={chosen?.includes(l.id) ?? false}
                    onChange={(e) =>
                      setChosen((c) =>
                        e.target.checked
                          ? [...(c ?? []), l.id]
                          : (c ?? []).filter((x) => x !== l.id),
                      )
                    }
                  />
                  <span>
                    {l.label} × {l.quantity}
                  </span>
                </label>
                <b>{l.available ? money(l.total) : t('track.unavailable')}</b>
              </li>
            ))}
          </ul>
          <p className="pos__total">
            <span>{t('track.total')}</span> <b>{money(selectedTotal)}</b>
          </p>
          <p className="muted">
            {t('track.validUntil', { date: when(view.quote.valid_until, locale) })} ·{' '}
            {t('track.payThere')}
          </p>
          <p className="muted">{t('track.afterAccept')}</p>
          {error && (
            <div role="alert" className="form-error">
              {error}
            </div>
          )}
          <div className="actions">
            <Button variant="secondary" onClick={() => setRefusing('')} disabled={busy}>
              {t('track.refuse')}
            </Button>
            <Button
              onClick={() => void act('POST', '/decision', { accept: chosen })}
              disabled={busy || selected.length === 0}
            >
              {selected.length === view.quote.lines.filter((l) => l.available).length
                ? t('track.accept')
                : t('track.acceptPart', { n: selected.length })}
            </Button>
          </div>
        </section>
      )}

      {(view.status === 'accepted' || view.status === 'ready') && view.pickup_code && (
        <section className="pickup">
          <p className="eyebrow">{t('track.pickupEyebrow')}</p>
          <h2>{view.status === 'ready' ? t('track.ready') : t('track.preparing')}</h2>
          <p>{t('track.showCode')}</p>
          <div className="pickup__code">
            <b>{view.pickup_code}</b>
            <PickupQr code={view.pickup_code} />
          </div>
          <dl className="pickup__facts">
            <div>
              <dt>{t('track.request')}</dt>
              <dd>{view.number}</dd>
            </div>
            <div>
              <dt>{t('track.toPay')}</dt>
              <dd>{money(view.quote?.total ?? 0)}</dd>
            </div>
          </dl>
          <p className="muted">
            {t('track.codeRule', { date: when(view.pickup_until ?? '', locale) })}
          </p>
        </section>
      )}

      {view.status === 'picked_up' && <p className="notice">{t('track.thanks')}</p>}
      {(view.status === 'cancelled' || view.status === 'expired') && (
        <p className="notice">
          {t(`track.end.${view.status}`)}{' '}
          {view.pharmacy.id && (
            <Link to={`/pharmacies/${view.pharmacy.id}/envoyer`}>{t('track.again')}</Link>
          )}
        </p>
      )}

      {open && (
        <section className="panel stack">
          {['received', 'processing', 'photo_needed'].includes(view.status) && (
            <label className="sa-btn sa-btn--secondary chat__pick">
              {t('send.addPage')}
              <input
                type="file"
                accept="image/jpeg,image/png,application/pdf"
                multiple
                onChange={(e) => {
                  void addPages(e.target.files);
                  e.target.value = '';
                }}
              />
            </label>
          )}
          <form
            className="track__message"
            onSubmit={(e) => {
              e.preventDefault();
              void act('POST', '/message', { text: message }).then((ok) => ok && setMessage(''));
            }}
          >
            <Field
              label={t('track.message')}
              value={message}
              maxLength={500}
              onChange={(e) => setMessage(e.target.value)}
            />
            <Button type="submit" variant="secondary" disabled={busy || message.trim() === ''}>
              {t('track.sendMessage')}
            </Button>
          </form>
          {!view.urgent ? (
            <Button variant="warning" onClick={() => setConfirmUrgent(true)}>
              {t('track.urgent')}
            </Button>
          ) : (
            <Badge tone="danger">{t('track.urgentSent')}</Badge>
          )}
          {error && view.status !== 'quoted' && (
            <div role="alert" className="form-error">
              {error}
            </div>
          )}
          <p className="muted">{t('send.noAdvice')}</p>
        </section>
      )}

      <section className="track__keep">
        <p>{t('track.keep')}</p>
        <Button
          variant="secondary"
          onClick={() =>
            void navigator.clipboard?.writeText(link).then(
              () => setCopied(true),
              () => undefined,
            )
          }
        >
          {copied ? t('track.copied') : t('track.copy')}
        </Button>
      </section>

      {refusing !== null && (
        <Modal title={t('track.refuseTitle')} onClose={() => setRefusing(null)}>
          <div className="stack">
            <Field
              label={t('track.reason')}
              value={refusing}
              maxLength={300}
              onChange={(e) => setRefusing(e.target.value)}
            />
            <div className="actions">
              <Button variant="secondary" onClick={() => setRefusing(null)}>
                {t('common.cancel')}
              </Button>
              <Button
                variant="danger"
                onClick={() =>
                  void act('POST', '/decision', { refuse: true, reason: refusing }).then(
                    (ok) => ok && setRefusing(null),
                  )
                }
              >
                {t('track.refuse')}
              </Button>
            </div>
          </div>
        </Modal>
      )}
      {confirmUrgent && (
        <Modal title={t('track.urgentTitle')} onClose={() => setConfirmUrgent(false)}>
          <div className="stack">
            <p>{t('track.urgentConfirm')}</p>
            <p className="muted">{t('track.emergency')}</p>
            <div className="actions">
              <Button variant="secondary" onClick={() => setConfirmUrgent(false)}>
                {t('common.cancel')}
              </Button>
              <Button
                variant="warning"
                onClick={() => void act('POST', '/urgent', {}).then(() => setConfirmUrgent(false))}
              >
                {t('track.urgentYes')}
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
