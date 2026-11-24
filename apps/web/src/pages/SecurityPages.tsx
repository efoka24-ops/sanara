import { useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import QRCode from 'qrcode';
import { useT } from '@sanara/i18n';
import { Button, Field } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { useSession } from '../app/session';

export function ForgotPage() {
  const { t } = useT();
  const [email, setEmail] = useState('');
  const [sent, setSent] = useState(false);
  return (
    <div className="card-form">
      <h1>{t('forgot.title')}</h1>
      <Field
        label={t('auth.email')}
        type="email"
        value={email}
        onChange={(e) => setEmail(e.target.value)}
      />
      {sent && (
        <div role="status" className="notice">
          {t('forgot.sent')}
        </div>
      )}
      <Button
        block
        disabled={!email}
        onClick={async () => {
          await api('POST', '/auth/password/forgot', { email }).catch(() => undefined);
          setSent(true);
        }}
      >
        {t('forgot.send')}
      </Button>
      <Link to="/connexion">{t('login.title')}</Link>
    </div>
  );
}

export function ResetPage() {
  const { t } = useT();
  const { token = '' } = useParams();
  const [usable, setUsable] = useState<boolean | null>(null);
  const [pw, setPw] = useState('');
  const [msg, setMsg] = useState<string | null>(null);
  const [done, setDone] = useState(false);
  useEffect(() => {
    api<{ usable: boolean }>('GET', `/auth/password/reset/${token}`)
      .then((r) => setUsable(r.usable))
      .catch(() => setUsable(false));
  }, [token]);
  async function submit() {
    try {
      await api('POST', `/auth/password/reset/${token}`, { password: pw });
      setDone(true);
    } catch (e) {
      const f =
        e instanceof ApiError
          ? (e.details.fields as Record<string, string> | undefined)?.password
          : undefined;
      setMsg(
        f === 'reused'
          ? t('reset.reused')
          : f === 'policy'
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
        <Link to="/mot-de-passe-oublie">{t('forgot.title')}</Link>
      </div>
    );
  if (done)
    return (
      <div className="card-form">
        <p>{t('reset.done')}</p>
        <Link className="sa-btn" to="/connexion">
          {t('login.submit')}
        </Link>
      </div>
    );
  return (
    <div className="card-form">
      <h1>{t('reset.title')}</h1>
      <p className="muted">{t('activate.rules')}</p>
      <Field
        label={t('auth.password')}
        type="password"
        autoComplete="new-password"
        value={pw}
        onChange={(e) => setPw(e.target.value)}
      />
      {msg && (
        <div role="alert" className="form-error">
          {msg}
        </div>
      )}
      <Button block onClick={submit}>
        {t('reset.submit')}
      </Button>
    </div>
  );
}

interface SessionRow {
  family_id: string;
  label: string | null;
  created_at: string;
}

export function SecurityPage() {
  const { t } = useT();
  const nav = useNavigate();
  const { session, signIn, signOut } = useSession();
  const [enabled, setEnabled] = useState(false);
  const [setup, setSetup] = useState<{ secret: string; uri: string; qr: string } | null>(null);
  const [code, setCode] = useState('');
  const [backup, setBackup] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sessions, setSessions] = useState<SessionRow[]>([]);
  const [pin, setPin] = useState('');
  const [pinMsg, setPinMsg] = useState<string | null>(null);
  const loadSessions = () =>
    api<{ sessions: SessionRow[] }>('GET', '/auth/sessions')
      .then((r) => setSessions(r.sessions))
      .catch(() => setSessions([]));
  useEffect(() => {
    api<{ mfa_enabled: boolean }>('GET', '/auth/me').then((r) => setEnabled(r.mfa_enabled));
    void loadSessions();
  }, []);

  async function begin() {
    const r = await api<{ secret: string; uri: string }>('POST', '/auth/mfa/begin');
    setSetup({ ...r, qr: await QRCode.toDataURL(r.uri, { margin: 1, width: 200 }) });
  }
  async function confirm() {
    try {
      const r = await api<{ backup_codes: string[] }>('POST', '/auth/mfa/confirm', { code });
      setBackup(r.backup_codes);
      setEnabled(true);
      setSetup(null);
      setError(null);
      if (session?.mfaSetupRequired) signIn({ ...session, mfaSetupRequired: false });
    } catch {
      setError(t('errors.invalid_credentials'));
    }
  }
  async function savePin() {
    try {
      await api('PUT', '/auth/pin', { pin });
      setPinMsg(t('security.pinSaved'));
      sessionStorage.setItem('sa_pin', '1');
      setPin('');
    } catch {
      setPinMsg(t('apply.invalid'));
    }
  }

  return (
    <div className="card-form card-form--wide">
      <h1>{t('security.title')}</h1>
      {session?.mfaSetupRequired && (
        <div role="alert" className="notice">
          {t('mfa.required')}
        </div>
      )}
      <section className="panel">
        <h2>{t('mfa.title')}</h2>
        {enabled && !backup && <p>✓ {t('mfa.enabled')}</p>}
        {!enabled && !setup && <Button onClick={begin}>{t('mfa.start')}</Button>}
        {setup && (
          <>
            <p className="muted">{t('mfa.setupHelp')}</p>
            <img src={setup.qr} alt="QR code" width={200} height={200} />
            <p className="muted">
              {t('mfa.secret')} : <code>{setup.secret}</code>
            </p>
            <Field
              label={t('mfa.code')}
              inputMode="numeric"
              autoComplete="one-time-code"
              value={code}
              onChange={(e) => setCode(e.target.value)}
              error={error ?? undefined}
            />
            <Button onClick={confirm} disabled={code.length < 6}>
              {t('mfa.confirm')}
            </Button>
          </>
        )}
        {backup && (
          <>
            <h3>{t('mfa.backupTitle')}</h3>
            <p className="muted">{t('mfa.backupHelp')}</p>
            <ul className="codes">
              {backup.map((c) => (
                <li key={c}>
                  <code>{c}</code>
                </li>
              ))}
            </ul>
            <Button
              onClick={() => {
                signOut();
                nav('/connexion');
              }}
            >
              {t('login.submit')}
            </Button>
          </>
        )}
      </section>
      {!session?.mfaSetupRequired && (
        <>
          <section className="panel">
            <h2>{t('security.pin')}</h2>
            <p className="muted">{t('security.pinHelp')}</p>
            <Field
              label="PIN"
              inputMode="numeric"
              maxLength={4}
              value={pin}
              onChange={(e) => setPin(e.target.value.replace(/\D/g, ''))}
            />
            {pinMsg && (
              <div role="status" className="notice">
                {pinMsg}
              </div>
            )}
            <Button disabled={pin.length !== 4} onClick={savePin}>
              {t('security.pinSave')}
            </Button>
          </section>
          <section className="panel">
            <h2>{t('security.sessions')}</h2>
            <ul className="sessions">
              {sessions.map((s) => (
                <li key={s.family_id}>
                  <span>
                    {s.label ?? 'Navigateur'} · {s.created_at.slice(0, 16).replace('T', ' ')}
                  </span>
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={async () => {
                      await api('DELETE', `/auth/sessions/${s.family_id}`);
                      await loadSessions();
                    }}
                  >
                    {t('security.revoke')}
                  </Button>
                </li>
              ))}
            </ul>
            <Button
              variant="danger"
              size="sm"
              onClick={async () => {
                await api('POST', '/auth/sessions/revoke-all');
                signOut();
                nav('/connexion');
              }}
            >
              {t('security.revokeAll')}
            </Button>
          </section>
        </>
      )}
    </div>
  );
}
