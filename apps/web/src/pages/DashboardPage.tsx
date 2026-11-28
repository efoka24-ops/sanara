import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge } from '@sanara/ui';
import { api } from '../api/client';
import { useSession } from '../app/session';
import { money } from '../lib/common';
import { when } from '../lib/requests';

interface Dashboard {
  user: { email: string; role: string };
  sales?: {
    today: { total: number; tickets: number; average: number; yesterday: number };
    days: { date: string; total: number }[];
  };
  till?: {
    open: boolean;
    station?: string;
    stale?: boolean;
    sales?: number;
    total?: number;
    expected_cash?: number;
  };
  pickups?: { ready: number; accepted: number };
  requests?: {
    open: number;
    urgent: number;
    latest: {
      id: string;
      number: string;
      first_name: string;
      status: string;
      urgent: boolean;
      created_at: string;
    }[];
  };
  presence?: { online: boolean; anyone: boolean };
  stock?: {
    out: number;
    low: number;
    expiring: number;
    watch: { name: string; reason: 'out' | 'low' | 'expiring'; qty: number; expiry?: string }[];
  };
  credit?: { outstanding: number; accounts: number };
}

const day = (date: string, locale: string) =>
  new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', timeZone: 'UTC' }).format(
    new Date(`${date}T00:00:00Z`),
  );

/** Ventes des 12 derniers jours : une seule série, barres fines, détail au survol et tableau accessible. */
function SalesChart({ days }: { days: { date: string; total: number }[] }) {
  const { t, locale } = useT();
  const max = Math.max(1, ...days.map((d) => d.total));
  const W = 600;
  const H = 160;
  const slot = W / days.length;
  const bar = Math.min(28, slot - 6);
  const [hover, setHover] = useState<number | null>(null);
  return (
    <figure className="chart">
      <figcaption>{t('dash.salesChart')}</figcaption>
      <svg viewBox={`0 0 ${W} ${H + 24}`} role="img" aria-label={t('dash.salesChart')}>
        <line x1={0} x2={W} y1={H} y2={H} className="chart__base" />
        {days.map((d, i) => {
          const h = d.total === 0 ? 0 : Math.max(3, (d.total / max) * (H - 10));
          const x = i * slot + (slot - bar) / 2;
          const last = i === days.length - 1;
          return (
            <g key={d.date} onMouseEnter={() => setHover(i)} onMouseLeave={() => setHover(null)}>
              {/* Zone de survol plus large que la barre. */}
              <rect x={i * slot} y={0} width={slot} height={H} fill="transparent" />
              {h > 0 && (
                <path
                  className={`chart__bar${last ? ' chart__bar--today' : ''}`}
                  d={`M${x},${H} v${-Math.max(0, h - 4)} q0,-4 4,-4 h${bar - 8} q4,0 4,4 v${Math.max(0, h - 4)} z`}
                />
              )}
              {(last || i % 3 === 2) && (
                <text
                  x={i * slot + slot / 2}
                  y={H + 16}
                  className="chart__label"
                  textAnchor="middle"
                >
                  {day(d.date, locale)}
                </text>
              )}
            </g>
          );
        })}
      </svg>
      {hover !== null && (
        <p className="chart__tip" role="status">
          {day(days[hover]!.date, locale)} · {money(days[hover]!.total)}
        </p>
      )}
      <table className="visually-hidden">
        <caption>{t('dash.salesChart')}</caption>
        <tbody>
          {days.map((d) => (
            <tr key={d.date}>
              <th scope="row">{d.date}</th>
              <td>{money(d.total)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </figure>
  );
}

function Tile({
  label,
  value,
  hint,
  to,
  tone,
}: {
  label: string;
  value: string | number;
  hint?: string;
  to?: string;
  tone?: 'alert';
}) {
  const body = (
    <>
      <span className="tile__label">{label}</span>
      <b className="tile__value">{value}</b>
      {hint && <span className="tile__hint">{hint}</span>}
    </>
  );
  const cls = `kpi${tone === 'alert' ? ' kpi--alert' : ''}`;
  return to ? (
    <Link className={cls} to={to}>
      {body}
    </Link>
  ) : (
    <div className={cls}>{body}</div>
  );
}

/** Tableau de bord (maquette H1) : les blocs dépendent du métier de la personne connectée. */
export function DashboardPage() {
  const { t, locale } = useT();
  const { can } = useSession();
  const [d, setD] = useState<Dashboard | null>(null);
  const [failed, setFailed] = useState(false);
  const [o, setO] = useState<{ percent: number; finished: boolean } | null>(null);

  useEffect(() => {
    api<Dashboard>('GET', '/app/dashboard')
      .then(setD)
      .catch(() => setFailed(true));
    if (can('settings.write'))
      api<{ percent: number; finished: boolean }>('GET', '/app/onboarding')
        .then(setO)
        .catch(() => setO(null));
  }, []);

  const today = new Intl.DateTimeFormat(locale, {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
  }).format(new Date());
  const name = d?.user.email.split('@')[0] ?? '';
  const sales = d?.sales;
  const change =
    sales && sales.today.yesterday > 0
      ? Math.round(((sales.today.total - sales.today.yesterday) / sales.today.yesterday) * 100)
      : null;

  return (
    <div className="dash">
      <header className="dash__head">
        <div>
          <p className="muted">{today}</p>
          <h1>{t('nav.dashboard')}</h1>
          {name && <p className="dash__hello">{t('dash.hello', { name })}</p>}
        </div>
        {d?.presence && (
          <Badge tone={d.presence.online ? 'ok' : 'neutral'}>
            {t(d.presence.online ? 'dash.onlineForPatients' : 'dash.offlineForPatients')}
          </Badge>
        )}
      </header>

      {o && !o.finished && (
        <div className="panel row">
          <b>{t('setup.progress', { percent: o.percent })}</b>
          <Link className="sa-btn" to="/app/configuration">
            {t('setup.resume')}
          </Link>
        </div>
      )}

      {!d ? (
        <p className={failed ? 'notice' : 'muted'}>
          {t(failed ? 'dash.unavailable' : 'common.loading')}
        </p>
      ) : (
        <>
          <section className="kpis">
            {sales && (
              <>
                <Tile
                  label={t('dash.salesToday')}
                  value={money(sales.today.total)}
                  hint={
                    change === null
                      ? undefined
                      : t('dash.vsYesterday', { n: `${change > 0 ? '+' : ''}${change}` })
                  }
                  to={can('sales.create') ? '/app/ventes' : undefined}
                />
                <Tile
                  label={t('dash.tickets')}
                  value={sales.today.tickets}
                  hint={t('dash.average', { amount: money(sales.today.average) })}
                />
              </>
            )}
            {d.requests && (
              <Tile
                label={t('dash.requests')}
                value={d.requests.open}
                hint={
                  d.requests.urgent > 0
                    ? t('dash.urgentCount', { n: d.requests.urgent })
                    : undefined
                }
                to="/app/demandes"
                tone={d.requests.urgent > 0 ? 'alert' : undefined}
              />
            )}
            {d.pickups && (
              <Tile
                label={t('dash.pickups')}
                value={d.pickups.ready}
                hint={t('dash.toPrepare', { n: d.pickups.accepted })}
                to={can('sales.create') ? '/app/caisse' : undefined}
              />
            )}
            {d.stock && (
              <Tile
                label={t('dash.stockAlerts')}
                value={d.stock.out + d.stock.low + d.stock.expiring}
                hint={t('dash.stockHint', {
                  out: d.stock.out,
                  low: d.stock.low,
                  exp: d.stock.expiring,
                })}
                to="/app/stock"
                tone={d.stock.out > 0 ? 'alert' : undefined}
              />
            )}
            {d.credit && (
              <Tile
                label={t('dash.credit')}
                value={money(d.credit.outstanding)}
                hint={t('dash.creditHint', { n: d.credit.accounts })}
                to="/app/clients"
              />
            )}
          </section>

          <div className="dash__grid">
            {sales && (
              <section className="panel">
                <SalesChart days={sales.days} />
              </section>
            )}

            {d.till && (
              <section className="panel stack">
                <h2>{t('dash.myTill')}</h2>
                {d.till.open ? (
                  <>
                    <p>
                      {t('dash.tillHint', { station: d.till.station ?? '', n: d.till.sales ?? 0 })}{' '}
                      · <b>{money(d.till.total ?? 0)}</b>
                    </p>
                    <p className="muted">
                      {t('dash.expectedCash', { amount: money(d.till.expected_cash ?? 0) })}
                    </p>
                    {d.till.stale && <p className="notice">{t('pos.stale')}</p>}
                  </>
                ) : (
                  <p className="muted">{t('dash.tillClosed')}</p>
                )}
                <Link className="sa-btn" to="/app/caisse">
                  {t(d.till.open ? 'dash.goTill' : 'pos.open')}
                </Link>
              </section>
            )}

            {d.requests && (
              <section className="panel stack">
                <div className="row">
                  <h2>{t('req.title')}</h2>
                  <Link to="/app/demandes">{t('dash.seeAll')}</Link>
                </div>
                {d.requests.latest.length === 0 ? (
                  <p className="muted">{t('req.none')}</p>
                ) : (
                  <ul className="dash__list">
                    {d.requests.latest.map((r) => (
                      <li key={r.id}>
                        <Link to={`/app/demandes/${r.id}`}>
                          {r.urgent && <Badge tone="danger">{t('req.urgent')}</Badge>}{' '}
                          {r.first_name} · {r.number}
                        </Link>
                        <span className="muted">{when(r.created_at, locale)}</span>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            )}

            {d.stock && (
              <section className="panel stack">
                <div className="row">
                  <h2>{t('dash.watch')}</h2>
                  <Link to="/app/stock">{t('dash.seeAll')}</Link>
                </div>
                {d.stock.watch.length === 0 ? (
                  <p className="muted">{t('dash.allGood')}</p>
                ) : (
                  <ul className="dash__list">
                    {d.stock.watch.map((w, i) => (
                      <li key={i}>
                        <span>{w.name}</span>
                        <Badge tone={w.reason === 'out' ? 'danger' : 'wait'}>
                          {t(`dash.watch.${w.reason}`, { n: w.qty, date: w.expiry ?? '' })}
                        </Badge>
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            )}
          </div>
        </>
      )}
    </div>
  );
}
