import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Logo } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { useDesktop, type DesktopState } from './desktop';

/** Cadre de l'application de bureau (maquette D1) : barre de titre, bandeau hors ligne, barre d'état. */
export function DesktopFrame({ children }: { children: ReactNode }) {
  const { t } = useT();
  const { desktop, status } = useDesktop();
  if (!desktop) return <>{children}</>;
  const title = ['Sanara', status?.pharmacy, status?.station].filter(Boolean).join(' · ');
  return (
    <div className="desktop-frame">
      <header className="titlebar">
        <Logo size={18} tone="light" wordmark={false} />
        <span>{title}</span>
      </header>
      {status && !status.online && (
        <div className="offline-banner" role="status">
          <span>
            ● <b>{t('desk.offline')}</b> · {t('desk.offlineHint')}
          </span>
          {status.pending > 0 && <span>{t('desk.waiting', { n: status.pending })}</span>}
        </div>
      )}
      <div className="desktop-frame__body">{children}</div>
      {status && <StatusBar status={status} />}
    </div>
  );
}

function StatusBar({ status }: { status: DesktopState }) {
  const { t, locale } = useT();
  const { sync } = useDesktop();
  const time = status.lastSyncAt
    ? new Date(status.lastSyncAt).toLocaleTimeString(locale === 'fr' ? 'fr-FR' : 'en-GB')
    : null;
  const label = (code: string) => (t(`err.${code}`) === `err.${code}` ? code : t(`err.${code}`));
  return (
    <footer className="statusbar" aria-live="polite">
      <span>{status.user ? `${status.user.email} · ${t(`role.${status.user.role}`)}` : ''}</span>
      <span className="statusbar__sync">
        <span className={`home__dot ${status.online ? 'home__dot--ok' : 'home__dot--bad'}`} />
        <b>{t(status.online ? 'desk.online' : 'desk.stateOffline')}</b>
        <span>
          {status.progress
            ? t('desk.progress', { sent: status.progress.sent, total: status.progress.total })
            : status.pending > 0
              ? t('desk.pending', { n: status.pending })
              : t('desk.upToDate')}
        </span>
        {time && <span>{t('desk.lastSync', { time })}</span>}
        {!status.ready && <span className="statusbar__alert">{t('desk.notReady')}</span>}
        {status.rejected.length > 0 && (
          <span
            role="alert"
            className="statusbar__alert"
            title={[...new Set(status.rejected.map((r) => label(r.code)))].join('\n')}
          >
            {t('desk.rejected', { n: status.rejected.length })}
          </span>
        )}
        {status.conflicts > 0 && (
          <Link className="statusbar__alert" to="/app/synchronisation">
            {t('desk.conflicts', { n: status.conflicts })}
          </Link>
        )}
        {status.clockSkewSeconds !== null && Math.abs(status.clockSkewSeconds) > 300 && (
          <span className="statusbar__alert">{t('desk.clock')}</span>
        )}
        <button className="link-strong" onClick={() => void sync()}>
          {t(status.syncing ? 'desk.syncing' : 'desk.sync')}
        </button>
      </span>
      <span>
        {status.printers !== undefined &&
          `${status.printers > 0 ? t('desk.printers', { n: status.printers }) : t('desk.noPrinter')} · `}
        {t('desk.version', { v: status.version })}
        {status.update?.status === 'downloading' &&
          ` · ${t('desk.updateDownloading', { v: status.update.version ?? '' })}`}
        {status.update?.status === 'ready' && (
          <>
            {' · '}
            <button
              className="link-strong"
              onClick={() =>
                void api('POST', '/_desktop/update/install').catch((e: unknown) =>
                  window.alert(
                    t(
                      e instanceof ApiError && e.code === 'till_open'
                        ? 'desk.updateTillOpen'
                        : 'errors.server_error',
                    ),
                  ),
                )
              }
            >
              {t('desk.updateReady', { v: status.update.version ?? '' })}
            </button>
          </>
        )}
      </span>
    </footer>
  );
}
