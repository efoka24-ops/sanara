import { useState, type FormEvent } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Button, Field } from '@sanara/ui';
import { api, ApiError, setTokens } from '../api/client';
import { useSession, type Role } from '../app/session';

export function LoginPage() {
  const { t } = useT();
  const { signIn } = useSession();
  const nav = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const r = await api<{ access: string; refresh: string }>('POST', '/auth/login', {
        email,
        password,
      });
      setTokens(r.access, r.refresh);
      const me = await api<{ role: Role; permissions: string[] }>('GET', '/auth/me');
      signIn({ role: me.role, permissions: me.permissions });
      nav(me.role === 'super_admin' ? '/admin' : '/app', { replace: true });
    } catch (err) {
      setError(err instanceof ApiError ? t(`errors.${err.code}`) : t('errors.server_error'));
    } finally {
      setBusy(false);
    }
  }

  return (
    <form className="card-form" onSubmit={submit}>
      <h1>{t('login.title')}</h1>
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
      {error && (
        <div role="alert" className="form-error">
          {error}
        </div>
      )}
      <Button type="submit" block disabled={busy}>
        {t('login.submit')}
      </Button>
      <p className="muted">
        {t('login.noAccount')} <Link to="/inscription">{t('login.apply')}</Link>
      </p>
    </form>
  );
}
