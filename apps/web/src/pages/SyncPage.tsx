import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button } from '@sanara/ui';
import { api } from '../api/client';
import { Art } from '../app/BrandPanel';
import { useDesktop } from '../app/desktop';
import { useSession } from '../app/session';
import { money, useErrorText } from '../lib/common';

interface Conflict {
  id: string;
  kind: string;
  details: {
    missing?: number;
    sold?: number;
    current?: number;
    secondsAhead?: number;
    over?: number;
  };
  product_name: string | null;
  customer_name: string | null;
  created_at: string;
}

/** T138, maquette D2 : ce qui reste à envoyer depuis le poste et les écarts à vérifier. */
export function SyncPage() {
  const { t, locale } = useT();
  const { can } = useSession();
  const { desktop, status, sync } = useDesktop();
  const errText = useErrorText();
  const [items, setItems] = useState<Conflict[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function load() {
    try {
      setItems((await api<{ items: Conflict[] }>('GET', '/sync/conflicts')).items);
      setError(null);
    } catch (e) {
      setError(errText(e));
    }
  }

  // Sur le poste, la liste est relue après chaque synchronisation.
  useEffect(() => {
    void load();
  }, [status?.lastSyncAt]);

  async function resolve(id: string) {
    try {
      await api('POST', `/sync/conflicts/${id}/resolve`, {});
      await load();
    } catch (e) {
      setError(errText(e));
    }
  }

  const message = (c: Conflict) =>
    t(`sync.kind.${c.kind}`, {
      product: c.product_name ?? '',
      missing: c.details.missing ?? 0,
      sold: money(c.details.sold ?? 0),
      current: money(c.details.current ?? 0),
      seconds: c.details.secondsAhead ?? 0,
      customer: c.customer_name ?? '',
      over: money(c.details.over ?? 0),
    });
  const label = (code: string) => (t(`err.${code}`) === `err.${code}` ? code : t(`err.${code}`));

  return (
    <div className="sync stack">
      <h1>{t('sync.title')}</h1>

      {desktop && status && (
        <section className="sync__post">
          <div className="stack">
            <p>{t(status.online ? 'sync.connected' : 'sync.waitingNetwork')}</p>
            {status.progress && (
              <progress max={status.progress.total} value={status.progress.sent} />
            )}
            <p>
              <b>
                {status.progress
                  ? t('desk.progress', { sent: status.progress.sent, total: status.progress.total })
                  : status.pending > 0
                    ? t('desk.pending', { n: status.pending })
                    : t('desk.upToDate')}
              </b>
            </p>
            {status.rejected.length > 0 && (
              <div role="alert" className="form-error">
                {t('desk.rejected', { n: status.rejected.length })}
                <ul>
                  {[...new Set(status.rejected.map((r) => r.code))].map((code) => (
                    <li key={code}>{label(code)}</li>
                  ))}
                </ul>
              </div>
            )}
            <div className="row">
              <Button onClick={() => void sync()} disabled={status.syncing}>
                {t(status.syncing ? 'desk.syncing' : 'desk.sync')}
              </Button>
            </div>
          </div>
          <Art name="hors-ligne" />
        </section>
      )}

      <h2>{t('sync.conflicts')}</h2>
      {error && (
        <div role="alert" className="form-error">
          {error}
        </div>
      )}
      {items?.length === 0 && <p className="muted">{t('sync.none')}</p>}
      <ul className="sync__list">
        {items?.map((c) => (
          <li key={c.id} className="sync__conflict">
            <div>
              <Badge tone="wait">{t(`sync.badge.${c.kind}`)}</Badge>
              <p>{message(c)}</p>
              <p className="muted">
                {new Date(`${c.created_at.replace(' ', 'T')}Z`).toLocaleString(
                  locale === 'fr' ? 'fr-FR' : 'en-GB',
                )}
              </p>
            </div>
            <div className="row">
              {c.kind === 'stock_shortage' && can('stock.write') && (
                <Link className="sa-btn" to="/app/stock/inventaire">
                  {t('sync.fixStock')}
                </Link>
              )}
              {can('stock.write') && (
                <Button variant="secondary" onClick={() => void resolve(c.id)}>
                  {t('sync.resolve')}
                </Button>
              )}
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
