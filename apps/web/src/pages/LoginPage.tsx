import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field } from '@sanara/ui';
import { api, ApiError, setTokens } from '../api/client';
import { useSession, type Role } from '../app/session';
import { BrandPanel } from '../app/BrandPanel';

interface Tokens {
  access: string;
  refresh: string;
  user: { mfa_setup_required?: boolean };
}
type LoginResult = Partial<Tokens> & { mfa_required?: boolean; mfa_token?: string };

export function LoginPage() {
  const { t } = useT();
  const { signIn } = useSession();
  const nav = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [mfaToken, setMfaToken] = useState<string | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function finish(r: Tokens) {
    setTokens(r.access, r.refresh);
    const me = await api<{ role: Role; permissions: string[]; mfa_setup_required: boolean }>(
      'GET',
      '/auth/me',
    );
    signIn({ role: me.role, permissions: me.permissions, mfaSetupRequired: me.mfa_setup_required });
    nav(me.mfa_setup_required ? '/securite' : me.role === 'super_admin' ? '/admin' : '/app', {
      replace: true,
    });
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      if (mfaToken) {
        await finish(await api<Tokens>('POST', '/auth/mfa/verify', { mfa_token: mfaToken, code }));
      } else {
        const r = await api<LoginResult>('POST', '/auth/login', {
          email,
          password,
          device_label: navigator.userAgent.includes('Electron') ? 'Poste Sanara' : 'Navigateur',
        });
        if (r.mfa_required && r.mfa_token) setMfaToken(r.mfa_token);
        else await finish(r as Tokens);
      }
    } catch (err) {
      setError(err instanceof ApiError ? t(`errors.${err.code}`) : t('errors.server_error'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="brand-split brand-split--login">
      <BrandPanel image="officine" title={t('home.title')} lead={t('home.lead')} />
      <form className="card-form" onSubmit={submit}>
        <h1>{mfaToken ? t('mfa.title') : t('login.title')}</h1>
        {!mfaToken && (
          <>
            <Field
              label={t('auth.email')}
              type="email"
              autoComplete="username"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              required
            />
            <Field
              label={t('auth.password')}
              type="password"
              autoComplete="current-password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              required
            />
          </>
        )}
        {mfaToken && (
          <Field
            label={t('mfa.code')}
            inputMode="numeric"
            autoComplete="one-time-code"
            autoFocus
            value={code}
            onChange={(e) => setCode(e.target.value)}
            required
          />
        )}
        {error && (
          <div role="alert" className="form-error">
            {error}
          </div>
        )}
        <Button type="submit" block disabled={busy}>
          {mfaToken ? t('mfa.verify') : t('login.submit')}
        </Button>
        {!mfaToken && (
          <p className="muted">
            <Link to="/mot-de-passe-oublie">{t('auth.forgot')}</Link>
            <br />
            {t('login.noAccount')} <Link to="/inscription">{t('login.apply')}</Link>
          </p>
        )}
      </form>
    </div>
  );
}
