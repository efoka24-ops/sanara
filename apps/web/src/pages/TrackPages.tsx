import { useEffect, useState } from 'react';
import { Link, useLocation, useParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button } from '@sanara/ui';
import { api, ApiError, readAsBase64 } from '../api/client';

export function SentPage() {
  const { t } = useT();
  const s = useLocation().state as { number: string; tracking_code: string } | null;
  if (!s)
    return (
      <div className="card-form">
        <Link to="/inscription">{t('apply.title')}</Link>
      </div>
    );
  return (
    <div className="card-form">
      <h1>{t('apply.sentTitle')}</h1>
      <p>{t('apply.sentBody')}</p>
      <div className="big-number">
        <small>{t('apply.number')}</small>
        <b>{s.number}</b>
      </div>
      <Link className="sa-btn" to={`/inscription/suivi/${s.tracking_code}`}>
        {t('apply.track')}
      </Link>
    </div>
  );
}

interface Status {
  number: string;
  status: 'pending' | 'needs_info' | 'approved' | 'rejected';
  message: string | null;
  requested_docs: string[];
}
const TONE = { pending: 'wait', needs_info: 'wait', approved: 'ok', rejected: 'danger' } as const;

export function TrackPage() {
  const { t } = useT();
  const { code = '' } = useParams();
  const [s, setS] = useState<Status | 'missing' | null>(null);
  const load = () =>
    api<Status>('GET', `/public/applications/${code}/status`)
      .then(setS)
      .catch((e) => setS(e instanceof ApiError && e.status === 404 ? 'missing' : null));
  useEffect(() => {
    void load();
  }, [code]);

  async function resend(type: string, file?: File) {
    if (!file) return;
    await api('POST', `/public/applications/${code}/documents`, {
      doc_type: type,
      documents: { [type]: { name: file.name, base64: await readAsBase64(file) } },
    });
    await load();
  }

  if (s === null) return <div className="card-form">{t('common.loading')}</div>;
  if (s === 'missing')
    return (
      <div className="card-form">
        <p role="alert">{t('track.notFound')}</p>
      </div>
    );
  return (
    <div className="card-form">
      <h1>{t('track.title')}</h1>
      <p>
        <b>{s.number}</b> <Badge tone={TONE[s.status]}>{t(`track.${s.status}`)}</Badge>
      </p>
      {s.message && <div className="notice">{s.message}</div>}
      {s.status === 'needs_info' &&
        s.requested_docs.map((d) => (
          <label key={d} className="dropzone">
            <strong>{t(`apply.doc.${d}`)}</strong>
            <span className="muted">{t('track.resend')}</span>
            <input
              type="file"
              accept="application/pdf,image/jpeg,image/png"
              hidden
              onChange={(e) => resend(d, e.target.files?.[0])}
            />
          </label>
        ))}
      {s.status === 'rejected' && <Link to="/inscription">{t('track.reapply')}</Link>}
    </div>
  );
}

export function ActivatePage() {
  const { t } = useT();
  const { token = '' } = useParams();
  const [usable, setUsable] = useState<boolean | null>(null);
  const [pw, setPw] = useState('');
  const [pw2, setPw2] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  useEffect(() => {
    api<{ usable: boolean }>('GET', `/public/activation/${token}`)
      .then((r) => setUsable(r.usable))
      .catch(() => setUsable(false));
  }, [token]);

  async function submit() {
    if (pw !== pw2) {
      setMsg(t('activate.mismatch'));
      return;
    }
    try {
      await api('POST', `/public/activation/${token}`, { password: pw });
      setDone(true);
      setMsg(null);
    } catch (e) {
      setMsg(
        e instanceof ApiError && e.code === 'validation_failed'
          ? t('activate.rules')
          : t('activate.invalid'),
      );
    }
  }

  if (usable === null) return <div className="card-form">{t('common.loading')}</div>;
  if (!usable && !done)
    return (
      <div className="card-form">
        <p role="alert">{t('activate.invalid')}</p>
      </div>
    );
  if (done)
    return (
      <div className="card-form">
        <p>{t('activate.done')}</p>
        <Link className="sa-btn" to="/connexion">
          {t('login.submit')}
        </Link>
      </div>
    );
  return (
    <div className="card-form">
      <h1>{t('activate.title')}</h1>
      <p className="muted">{t('activate.rules')}</p>
      <label className="sa-field">
        <span className="sa-field__label">{t('auth.password')}</span>
        <input
          className="sa-field__input"
          type="password"
          autoComplete="new-password"
          value={pw}
          onChange={(e) => setPw(e.target.value)}
        />
      </label>
      <label className="sa-field">
        <span className="sa-field__label">{t('activate.confirm')}</span>
        <input
          className="sa-field__input"
          type="password"
          autoComplete="new-password"
          value={pw2}
          onChange={(e) => setPw2(e.target.value)}
        />
      </label>
      {msg && (
        <div role="alert" className="form-error">
          {msg}
        </div>
      )}
      <Button type="button" block onClick={submit}>
        {t('activate.submit')}
      </Button>
    </div>
  );
}
