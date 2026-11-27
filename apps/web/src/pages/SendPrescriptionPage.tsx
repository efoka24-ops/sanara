import { useEffect, useState, type FormEvent } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { useErrorText } from '../lib/common';
import { statusLabel, type PublicSheet } from '../lib/publicSearch';
import { MAX_TOTAL_BYTES, shrink, toBase64 } from '../lib/requests';

const MAX_PAGES = 5;

interface Page {
  name: string;
  blob: Blob;
}

/** US21 : envoi d'une ordonnance à une pharmacie (maquette P4). */
export function SendPrescriptionPage() {
  const { id = '' } = useParams();
  const { t, locale } = useT();
  const navigate = useNavigate();
  const errText = useErrorText();
  const [sheet, setSheet] = useState<PublicSheet | null>(null);
  const [missing, setMissing] = useState(false);
  const [pages, setPages] = useState<Page[]>([]);
  const [form, setForm] = useState({ first_name: '', phone: '', message: '' });
  const [urgent, setUrgent] = useState(false);
  const [mode, setMode] = useState<'pickup' | 'own_delivery'>('pickup');
  const [consent, setConsent] = useState(false);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);

  useEffect(() => {
    api<PublicSheet>('GET', `/public/pharmacies/${encodeURIComponent(id)}`)
      .then(setSheet)
      .catch(() => setMissing(true));
  }, [id]);

  async function addFiles(list: FileList | null) {
    if (!list) return;
    setError(null);
    const room = MAX_PAGES - pages.length;
    const chosen = [...list].slice(0, Math.max(0, room));
    if (list.length > room) setError(t('send.tooMany', { n: MAX_PAGES }));
    const added = await Promise.all(
      chosen.map(async (f) => ({ name: f.name, blob: await shrink(f) })),
    );
    setPages((before) => [...before, ...added]);
  }

  async function send(e: FormEvent) {
    e.preventDefault();
    setError(null);
    setFields({});
    if (!consent) return setError(t('err.consent_required'));
    if (pages.length === 0) return setError(t('send.noPage'));
    if (pages.reduce((n, p) => n + p.blob.size, 0) > MAX_TOTAL_BYTES)
      return setError(t('send.tooHeavy'));
    setSending(true);
    try {
      const documents = await Promise.all(
        pages.map(async (p) => ({ name: p.name, base64: await toBase64(p.blob) })),
      );
      const done = await api<{ code: string; urgent_limited: boolean }>(
        'POST',
        `/public/pharmacies/${id}/requests`,
        {
          ...form,
          urgent,
          mode,
          consent: true,
          locale,
          documents,
        },
      );
      navigate(`/suivi/${done.code}`, {
        replace: true,
        state: { sent: true, limited: done.urgent_limited },
      });
    } catch (err) {
      const invalid =
        err instanceof ApiError ? (err.details.fields as Record<string, string> | undefined) : null;
      setFields(invalid ?? {});
      setError(invalid ? t('store.invalid') : errText(err));
      setSending(false);
    }
  }

  if (missing)
    return (
      <div className="sheet">
        <p role="alert" className="form-error">
          {t('sheet.missing')}
        </p>
        <Link to="/pharmacies">{t('home.find')}</Link>
      </div>
    );
  if (!sheet) return <p className="sheet muted">{t('common.loading')}</p>;

  return (
    <div className="chat">
      <header className="chat__head">
        <span className="chat__avatar" aria-hidden="true">
          ✦
        </span>
        <div>
          <b>{t('send.assistant')}</b>
          <div className="muted">{sheet.pharmacy_name}</div>
        </div>
      </header>
      <div className="chat__body">
        <p className="bubble">{t('send.hello', { pharmacy: sheet.pharmacy_name })}</p>
        <p className="bubble">
          {sheet.open
            ? t('send.open')
            : t('send.closed', { status: statusLabel(t, locale, sheet) })}
        </p>
        <p className="chat__rule">{t('send.noAdvice')}</p>

        <form className="panel stack" onSubmit={send} noValidate>
          <fieldset className="chat__files">
            <legend>{t('send.pages', { n: pages.length, max: MAX_PAGES })}</legend>
            <ul>
              {pages.map((p, i) => (
                <li key={`${p.name}-${i}`} className="row">
                  <span>
                    📄 {p.name} · {Math.max(1, Math.round(p.blob.size / 1024))} Ko
                  </span>
                  <button
                    type="button"
                    className="link"
                    onClick={() => setPages(pages.filter((_, k) => k !== i))}
                  >
                    {t('store.remove')}
                  </button>
                </li>
              ))}
            </ul>
            {pages.length < MAX_PAGES && (
              <label className="sa-btn sa-btn--secondary chat__pick">
                {pages.length === 0 ? t('send.addFirst') : t('send.addPage')}
                <input
                  type="file"
                  accept="image/jpeg,image/png,application/pdf"
                  multiple
                  onChange={(e) => {
                    void addFiles(e.target.files);
                    e.target.value = '';
                  }}
                />
              </label>
            )}
          </fieldset>
          <div className="cols2">
            <Field
              label={t('send.firstName')}
              autoComplete="given-name"
              value={form.first_name}
              error={fields.first_name ? t('common.required') : undefined}
              onChange={(e) => setForm({ ...form, first_name: e.target.value })}
            />
            <Field
              label={t('send.phone')}
              type="tel"
              autoComplete="tel"
              value={form.phone}
              error={fields.phone ? t('send.badPhone') : undefined}
              onChange={(e) => setForm({ ...form, phone: e.target.value })}
            />
          </div>
          <label className="sa-field">
            <span className="sa-field__label">{t('send.message')}</span>
            <textarea
              className="sa-field__input"
              rows={2}
              maxLength={500}
              value={form.message}
              onChange={(e) => setForm({ ...form, message: e.target.value })}
            />
          </label>
          <div className="chat__mode" role="group" aria-label={t('send.how')}>
            {(['pickup', 'own_delivery'] as const).map((m) => (
              <button
                key={m}
                type="button"
                className={`chip${mode === m ? ' chip--on' : ''}`}
                aria-pressed={mode === m}
                onClick={() => setMode(m)}
              >
                {t(`send.mode.${m}`)}
              </button>
            ))}
            <span className="muted">{t(`send.mode.${mode}.hint`)}</span>
          </div>
          <label className="check">
            <input type="checkbox" checked={urgent} onChange={(e) => setUrgent(e.target.checked)} />
            <span>{t('send.urgent')}</span>
          </label>
          <label className="check">
            <input
              type="checkbox"
              checked={consent}
              onChange={(e) => setConsent(e.target.checked)}
            />
            <span>{t('send.consent', { pharmacy: sheet.pharmacy_name })}</span>
          </label>
          {error && (
            <div role="alert" className="form-error">
              {error}
            </div>
          )}
          <Button type="submit" disabled={sending}>
            {sending ? t('send.sending') : t('send.submit')}
          </Button>
        </form>
      </div>
    </div>
  );
}
