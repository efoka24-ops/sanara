import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { api } from '../api/client';
import { useSession } from '../app/session';
import { DocModal, ProductSearch, fmt, useErrorText, type ProductLite } from '../lib/common';

interface Row {
  product_id: string;
  name: string;
  dci: string | null;
  min_stock: number;
  available: number;
  blocked: number;
  next_expiry: string | null;
  state: 'ok' | 'low' | 'out';
}
interface Lot {
  id: string;
  lot_number: string;
  expiry: string;
  status: string;
  unit_cost: number;
  available_qty: number;
}
interface Movement {
  id: string;
  qty: number;
  kind: string;
  reason: string | null;
  created_at: string;
  lot_id: string | null;
}
interface Alert {
  id: string;
  kind: string;
  message: string;
  created_at: string;
  read_at: string | null;
}
interface Destruction {
  id: string;
  number: string;
  date: string;
  product: string;
  lot: string;
  qty: number;
  value: number;
  reason: string;
}
type Tab = 'items' | 'alerts' | 'destructions';
type Action =
  | { kind: 'receive'; product: ProductLite | null }
  | { kind: 'adjust' | 'quarantine' | 'destroy'; lot: Lot }
  | null;

const TONE = { ok: 'ok', low: 'wait', out: 'danger' } as const;

export function StockPage() {
  const { t } = useT();
  const toast = useToast();
  const errText = useErrorText();
  const { can } = useSession();
  const [tab, setTab] = useState<Tab>('items');
  const [rows, setRows] = useState<Row[]>([]);
  const [filter, setFilter] = useState('');
  const [alerts, setAlerts] = useState<Alert[]>([]);
  const [destructions, setDestructions] = useState<Destruction[]>([]);
  const [open, setOpen] = useState<Row | null>(null);
  const [lots, setLots] = useState<Lot[]>([]);
  const [moves, setMoves] = useState<Movement[]>([]);
  const [action, setAction] = useState<Action>(null);
  const [f, setF] = useState({
    lot: '',
    expiry: '',
    qty: '',
    cost: '',
    delta: '',
    reason: '',
    method: '',
    witness: '',
  });
  const [error, setError] = useState<string | null>(null);
  const [doc, setDoc] = useState<{ title: string; path: string } | null>(null);
  const write = can('stock.write');

  const loadItems = useCallback(() => {
    api<{ items: Row[] }>('GET', '/app/stock/overview').then((r) => setRows(r.items));
  }, []);
  const loadAlerts = useCallback(() => {
    api<{ items: Alert[] }>('GET', '/app/stock/alerts').then((r) => setAlerts(r.items));
  }, []);
  useEffect(() => {
    loadItems();
    loadAlerts();
    api<{ items: Destruction[] }>('GET', '/app/stock/destructions').then((r) =>
      setDestructions(r.items),
    );
  }, [loadItems, loadAlerts]);

  async function loadLots(r: Row) {
    const [l, m] = await Promise.all([
      api<{ items: Lot[] }>('GET', `/app/stock/products/${r.product_id}/lots`),
      api<{ items: Movement[] }>('GET', `/app/stock/products/${r.product_id}/movements`),
    ]);
    setLots(l.items);
    setMoves(m.items);
  }
  async function openProduct(r: Row) {
    setOpen(r);
    await loadLots(r);
  }
  const refresh = async () => {
    loadItems();
    loadAlerts();
    if (open) await loadLots(open);
  };
  const begin = (a: Action) => {
    setAction(a);
    setError(null);
    setF({
      lot: '',
      expiry: '',
      qty: '',
      cost: '',
      delta: '',
      reason: '',
      method: '',
      witness: '',
    });
  };
  const set = (k: keyof typeof f) => (e: { target: { value: string } }) =>
    setF((x) => ({ ...x, [k]: e.target.value }));

  async function submit() {
    if (!action) return;
    setError(null);
    try {
      if (action.kind === 'receive') {
        const product = action.product ?? (open ? { id: open.product_id } : null);
        if (!product) return;
        const r = await api<{ short_dated: boolean }>('POST', '/app/stock/receipts', {
          product_id: product.id,
          lot_number: f.lot,
          expiry: f.expiry,
          quantity: Number(f.qty),
          unit_cost: Number(f.cost || 0),
        });
        toast(r.short_dated ? t('st.shortDated') : t('st.received'));
      } else if (action.kind === 'adjust' && open) {
        await api('POST', '/app/stock/adjustments', {
          product_id: open.product_id,
          lot_id: action.lot.id,
          delta: Number(f.delta),
          reason: f.reason,
        });
      } else if (action.kind === 'quarantine') {
        await api('POST', `/app/stock/lots/${action.lot.id}/quarantine`, { reason: f.reason });
      } else if (action.kind === 'destroy') {
        const r = await api<{ id: string }>('POST', `/app/stock/lots/${action.lot.id}/destroy`, {
          quantity: Number(f.qty),
          reason: f.reason,
          method: f.method || null,
          witness: f.witness || null,
        });
        setDoc({ title: t('st.pv'), path: `/app/stock/destructions/${r.id}/document` });
        api<{ items: Destruction[] }>('GET', '/app/stock/destructions').then((x) =>
          setDestructions(x.items),
        );
      }
      setAction(null);
      await refresh();
    } catch (e) {
      setError(errText(e));
    }
  }
  async function release(l: Lot) {
    try {
      await api('POST', `/app/stock/lots/${l.id}/release`, {});
      await refresh();
    } catch (e) {
      toast(errText(e), 'error');
    }
  }

  const shown = rows.filter((r) =>
    `${r.name} ${r.dci ?? ''}`.toLowerCase().includes(filter.toLowerCase()),
  );
  const unread = alerts.filter((a) => !a.read_at).length;

  return (
    <div>
      <div className="row">
        <h1>{t('st.title')}</h1>
        <div className="row">
          {can('stock.read') && (
            <Link className="sa-btn sa-btn--secondary" to="/app/stock/inventaire">
              {t('st.inventory')}
            </Link>
          )}
          {write && (
            <Button onClick={() => begin({ kind: 'receive', product: null })}>
              {t('st.receive')}
            </Button>
          )}
        </div>
      </div>

      <div className="tabs" role="tablist">
        {(['items', 'alerts', 'destructions'] as Tab[]).map((k) => (
          <button
            key={k}
            role="tab"
            aria-selected={tab === k}
            className={`chip${tab === k ? ' chip--on' : ''}`}
            onClick={() => setTab(k)}
          >
            {t(`st.tab.${k}`)}
            {k === 'alerts' && unread > 0 ? ` (${unread})` : ''}
          </button>
        ))}
      </div>

      {tab === 'items' && (
        <>
          <div className="row" style={{ margin: '14px 0' }}>
            <input
              className="search"
              aria-label={t('st.search')}
              placeholder={t('st.search')}
              value={filter}
              onChange={(e) => setFilter(e.target.value)}
            />
          </div>
          <Table
            rowKey={(r) => r.product_id}
            rows={shown}
            columns={[
              {
                key: 'n',
                header: t('st.col.product'),
                render: (r) => (
                  <button className="link-strong" onClick={() => openProduct(r)}>
                    {r.name}
                  </button>
                ),
              },
              { key: 'a', header: t('st.col.available'), render: (r) => fmt(r.available) },
              { key: 'm', header: t('st.col.min'), render: (r) => fmt(r.min_stock) },
              {
                key: 'b',
                header: t('st.col.blocked'),
                render: (r) => (r.blocked > 0 ? fmt(r.blocked) : ''),
              },
              { key: 'e', header: t('st.col.expiry'), render: (r) => r.next_expiry ?? '' },
              {
                key: 's',
                header: t('st.col.state'),
                render: (r) => <Badge tone={TONE[r.state]}>{t(`st.state.${r.state}`)}</Badge>,
              },
            ]}
          />
        </>
      )}

      {tab === 'alerts' && (
        <div className="panel">
          {alerts.length === 0 && <p className="muted">{t('st.noAlerts')}</p>}
          <ul className="sessions">
            {alerts.map((a) => (
              <li key={a.id}>
                <span>
                  <Badge
                    tone={
                      a.kind === 'out' || a.kind === 'expired' || a.kind === 'expiry_30'
                        ? 'danger'
                        : 'wait'
                    }
                  >
                    {a.kind}
                  </Badge>{' '}
                  {a.message}
                </span>
                <span className="muted">{a.created_at.slice(0, 10)}</span>
              </li>
            ))}
          </ul>
          {unread > 0 && (
            <Button
              variant="secondary"
              onClick={async () => {
                await api('POST', '/app/stock/alerts/read', {});
                loadAlerts();
              }}
            >
              {t('st.markRead')}
            </Button>
          )}
        </div>
      )}

      {tab === 'destructions' && (
        <Table
          rowKey={(d) => d.number}
          rows={destructions}
          columns={[
            { key: 'n', header: 'N°', render: (d) => d.number },
            { key: 'd', header: 'Date', render: (d) => d.date.slice(0, 10) },
            {
              key: 'p',
              header: t('st.col.product'),
              render: (d) => `${d.product} (${t('st.lot')} ${d.lot})`,
            },
            { key: 'q', header: t('st.qty'), render: (d) => fmt(d.qty) },
            { key: 'r', header: t('st.reason'), render: (d) => d.reason },
            {
              key: 'x',
              header: '',
              render: (d) => (
                <button
                  className="link-strong"
                  onClick={() =>
                    setDoc({ title: t('st.pv'), path: `/app/stock/destructions/${d.id}/document` })
                  }
                >
                  {t('st.pv')}
                </button>
              ),
            },
          ]}
        />
      )}

      {open && !action && (
        <Modal title={open.name} onClose={() => setOpen(null)}>
          <h3>{t('st.lots')}</h3>
          <Table
            rowKey={(l) => l.id}
            rows={lots}
            columns={[
              { key: 'l', header: t('st.lot'), render: (l) => l.lot_number },
              { key: 'e', header: t('st.expiry'), render: (l) => l.expiry },
              {
                key: 's',
                header: t('st.col.state'),
                render: (l) => (
                  <Badge tone={l.status === 'available' ? 'ok' : 'danger'}>
                    {t(`st.status.${l.status}`)}
                  </Badge>
                ),
              },
              { key: 'q', header: t('st.qty'), render: (l) => fmt(l.available_qty) },
              {
                key: 'a',
                header: '',
                render: (l) =>
                  write && l.status !== 'destroyed' ? (
                    <span className="row">
                      <button
                        className="link-strong"
                        onClick={() => begin({ kind: 'adjust', lot: l })}
                      >
                        {t('st.adjust')}
                      </button>
                      {l.status === 'available' && (
                        <button
                          className="link-strong"
                          onClick={() => begin({ kind: 'quarantine', lot: l })}
                        >
                          {t('st.quarantine')}
                        </button>
                      )}
                      {l.status === 'quarantine' && (
                        <button className="link-strong" onClick={() => release(l)}>
                          {t('st.release')}
                        </button>
                      )}
                      {can('stock.destroy') &&
                        (l.status === 'quarantine' || l.status === 'expired') && (
                          <button
                            className="link-strong"
                            onClick={() => begin({ kind: 'destroy', lot: l })}
                          >
                            {t('st.destroy')}
                          </button>
                        )}
                    </span>
                  ) : null,
              },
            ]}
          />
          <h3>{t('st.movements')}</h3>
          <ul className="sessions">
            {moves.slice(0, 12).map((m) => (
              <li key={m.id}>
                <span>
                  {m.qty > 0 ? '+' : ''}
                  {m.qty} · {m.kind} <span className="muted">{m.reason ?? ''}</span>
                </span>
                <span className="muted">{m.created_at.slice(0, 16)}</span>
              </li>
            ))}
          </ul>
          <div className="actions">
            {write && (
              <Button
                variant="secondary"
                onClick={() =>
                  begin({ kind: 'receive', product: { id: open.product_id } as ProductLite })
                }
              >
                {t('st.receive')}
              </Button>
            )}
            <Button onClick={() => setOpen(null)}>{t('common.close')}</Button>
          </div>
        </Modal>
      )}

      {action && (
        <Modal
          title={t(`st.${action.kind === 'receive' ? 'receive' : action.kind}`).replace('+ ', '')}
          onClose={() => setAction(null)}
        >
          <div className="stack">
            {action.kind === 'receive' && !action.product && !open && (
              <ProductSearch
                label={t('st.pick')}
                onPick={(p) => setAction({ kind: 'receive', product: p })}
                autoFocus
              />
            )}
            {action.kind === 'receive' && action.product && 'name' in action.product && (
              <b>{action.product.name}</b>
            )}
            {action.kind === 'receive' && (action.product || open) && (
              <>
                <Field label={t('st.lot')} value={f.lot} onChange={set('lot')} />
                <div className="grid2">
                  <Field
                    label={t('st.expiry')}
                    type="date"
                    value={f.expiry}
                    onChange={set('expiry')}
                  />
                  <Field
                    label={t('st.qty')}
                    type="number"
                    min={1}
                    value={f.qty}
                    onChange={set('qty')}
                  />
                </div>
                <Field
                  label={t('st.cost')}
                  type="number"
                  min={0}
                  value={f.cost}
                  onChange={set('cost')}
                />
              </>
            )}
            {action.kind === 'adjust' && (
              <>
                <Field
                  label={t('st.delta')}
                  type="number"
                  value={f.delta}
                  onChange={set('delta')}
                />
                <Field label={t('st.reason')} value={f.reason} onChange={set('reason')} />
              </>
            )}
            {action.kind === 'quarantine' && (
              <Field label={t('st.reason')} value={f.reason} onChange={set('reason')} />
            )}
            {action.kind === 'destroy' && (
              <>
                <Field
                  label={t('st.qty')}
                  type="number"
                  min={1}
                  value={f.qty}
                  onChange={set('qty')}
                />
                <Field label={t('st.reason')} value={f.reason} onChange={set('reason')} />
                <div className="grid2">
                  <Field label={t('st.method')} value={f.method} onChange={set('method')} />
                  <Field label={t('st.witness')} value={f.witness} onChange={set('witness')} />
                </div>
              </>
            )}
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setAction(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={submit}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
      {doc && <DocModal title={doc.title} path={doc.path} onClose={() => setDoc(null)} />}
    </div>
  );
}
