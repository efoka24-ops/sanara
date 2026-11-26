import { useCallback, useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Button } from '@sanara/ui';
import { api } from '../api/client';

interface Status {
  online: boolean;
  ready: boolean;
  syncing: boolean;
  pending: number;
  rejected: { id: string; code: string }[];
  conflicts: number;
  lastSyncAt: string | null;
  clockSkewSeconds: number | null;
}

declare global {
  interface Window {
    sanaraDesktop?: { desktop: boolean };
  }
}

/** T141, T142 : état de la synchronisation, affiché dans l'application de bureau seulement. */
export function DesktopStatus() {
  const { t, locale } = useT();
  const [status, setStatus] = useState<Status | null>(null);
  const desktop = window.sanaraDesktop?.desktop === true;

  const load = useCallback(async (path = '/_desktop/status') => {
    try {
      setStatus(await api<Status>('GET', path));
    } catch {
      setStatus(null);
    }
  }, []);

  useEffect(() => {
    if (!desktop) return;
    void load();
    const timer = window.setInterval(() => void load(), 3000);
    return () => window.clearInterval(timer);
  }, [desktop, load]);

  if (!desktop || !status) return null;
  const time = status.lastSyncAt
    ? new Date(status.lastSyncAt).toLocaleTimeString(locale === 'fr' ? 'fr-FR' : 'en-GB')
    : null;
  return (
    <section className="desktop-status" aria-live="polite">
      <p className="desktop-status__line">
        <span className={`home__dot ${status.online ? 'home__dot--ok' : 'home__dot--bad'}`} />
        <b>{t(status.online ? 'desk.online' : 'desk.offline')}</b>
      </p>
      <p className="muted">
        {status.pending > 0 ? t('desk.pending', { n: status.pending }) : t('desk.upToDate')}
      </p>
      {time && <p className="muted">{t('desk.lastSync', { time })}</p>}
      {!status.ready && <p className="form-error">{t('desk.notReady')}</p>}
      {status.rejected.length > 0 && (
        <div role="alert" className="form-error">
          {t('desk.rejected', { n: status.rejected.length })}
          <ul>
            {[...new Set(status.rejected.map((r) => r.code))].map((code) => (
              <li key={code}>{t(`err.${code}`) === `err.${code}` ? code : t(`err.${code}`)}</li>
            ))}
          </ul>
        </div>
      )}
      {status.conflicts > 0 && (
        <p className="form-error">{t('desk.conflicts', { n: status.conflicts })}</p>
      )}
      {status.clockSkewSeconds !== null && Math.abs(status.clockSkewSeconds) > 300 && (
        <p className="form-error">{t('desk.clock')}</p>
      )}
      <Button variant="secondary" onClick={() => void load('/_desktop/sync')}>
        {t(status.syncing ? 'desk.syncing' : 'desk.sync')}
      </Button>
    </section>
  );
}
