import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Modal, Table, useToast } from '@sanara/ui';
import { accessHeader, api } from '../api/client';
import { useSession } from '../app/session';
import { ProductSearch, fmt, money, useErrorText, type ProductLite } from '../lib/common';

interface Inv {
  id: string;
  number: string;
  kind: 'full' | 'cycle';
  blind: number;
  status: 'open' | 'pending_validation' | 'validated' | 'cancelled';
  created_at: string;
  gap_value: number | null;
  lines_count?: number;
  counted_count?: number;
}
interface Line {
  lot_id: string;
  product_id: string;
  product: string;
  lot_number: string;
  expiry: string;
  expected_qty: number | null;
  counted_qty: number | null;
}
interface Report {
  lines: {
    product: string;
    lot_number: string;
    expected_qty: number;
    counted_qty: number;
    gap: number;
    value: number;
  }[];
  gap_value: number;
  loss_value: number;
  surplus_value: number;
  uncounted: number;
}
const TONE = {
  open: 'wait',
  pending_validation: 'wait',
  validated: 'ok',
  cancelled: 'neutral',
} as const;
const device = `web-${Math.random().toString(36).slice(2, 8)}`;

export function InventoryPage() {
  const { t } = useT();
  const toast = useToast();
  const errText = useErrorText();
  const { can } = useSession();
  const [list, setList] = useState<Inv[]>([]);
  const [cur, setCur] = useState<{ inventory: Inv; lines: Line[] } | null>(null);
  const [report, setReport] = useState<Report | null>(null);
  const [only, setOnly] = useState<ProductLite | null>(null);
  const [draft, setDraft] = useState<Record<string, string>>({});
  const [creating, setCreating] = useState(false);
  const [form, setForm] = useState({ kind: 'full', blind: true, family_id: '' });
  const [families, setFamilies] = useState<{ id: string; name: string }[]>([]);

  const loadList = useCallback(() => {
    api<{ items: Inv[] }>('GET', '/app/inventories').then((r) => setList(r.items));
  }, []);
  useEffect(() => {
    loadList();
    api<{ items: { id: string; name: string }[] }>('GET', '/app/families').then((r) =>
      setFamilies(r.items),
    );
  }, [loadList]);

  async function open(id: string) {
    const d = await api<{ inventory: Inv; lines: Line[] }>('GET', `/app/inventories/${id}`);
    setCur(d);
    setDraft({});
    setOnly(null);
    setReport(
      d.inventory.status === 'open' || d.inventory.status === 'cancelled'
        ? null
        : await api<Report>('GET', `/app/inventories/${id}/report`),
    );
  }
  async function create() {
    try {
      const r = await api<{ id: string }>('POST', '/app/inventories', {
        kind: form.kind,
        blind: form.blind,
        family_id: form.kind === 'cycle' ? form.family_id : null,
      });
      setCreating(false);
      loadList();
      await open(r.id);
    } catch (e) {
      toast(errText(e), 'error');
    }
  }
  async function count(l: Line) {
    const v = draft[l.lot_id];
    if (v === undefined || v === '' || !cur) return;
    try {
      await api('POST', `/app/inventories/${cur.inventory.id}/counts`, {
        device_id: device,
        counts: [{ lot_id: l.lot_id, qty: Number(v) }],
      });
      setCur({
        ...cur,
        lines: cur.lines.map((x) => (x.lot_id === l.lot_id ? { ...x, counted_qty: Number(v) } : x)),
      });
    } catch (e) {
      toast(errText(e), 'error');
    }
  }
  async function act(path: string, done?: string) {
    if (!cur) return;
    try {
      await api('POST', `/app/inventories/${cur.inventory.id}/${path}`, {});
      if (done) toast(done);
      loadList();
      await open(cur.inventory.id);
    } catch (e) {
      toast(errText(e), 'error');
    }
  }
  async function exportCsv() {
    if (!cur) return;
    const base = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api';
    const res = await fetch(`${base}/app/inventories/${cur.inventory.id}/report.csv`, {
      headers: accessHeader(),
    });
    const url = URL.createObjectURL(await res.blob());
    Object.assign(document.createElement('a'), {
      href: url,
      download: `ecarts-${cur.inventory.number}.csv`,
    }).click();
    URL.revokeObjectURL(url);
  }

  const lines = useMemo(
    () => (cur ? cur.lines.filter((l) => !only || l.product_id === only.id) : []),
    [cur, only],
  );
  const counted = cur?.lines.filter((l) => l.counted_qty !== null).length ?? 0;
  const isOpen = cur?.inventory.status === 'open';

  return (
    <div>
      <div className="row">
        <h1>{t('inv.title')}</h1>
        <div className="row">
          <Link className="sa-btn sa-btn--secondary" to="/app/stock">
            {t('st.title')}
          </Link>
          {can('stock.write') && <Button onClick={() => setCreating(true)}>{t('inv.new')}</Button>}
        </div>
      </div>

      {!cur && (
        <Table
          rowKey={(i) => i.id}
          rows={list}
          columns={[
            {
              key: 'n',
              header: 'N°',
              render: (i) => (
                <button className="link-strong" onClick={() => open(i.id)}>
                  {i.number}
                </button>
              ),
            },
            { key: 'k', header: t('inv.kind'), render: (i) => t(`inv.${i.kind}`) },
            { key: 'd', header: 'Date', render: (i) => i.created_at.slice(0, 10) },
            {
              key: 's',
              header: '',
              render: (i) => <Badge tone={TONE[i.status]}>{t(`inv.status.${i.status}`)}</Badge>,
            },
            {
              key: 'g',
              header: t('inv.value'),
              render: (i) => (i.gap_value === null ? '' : money(Number(i.gap_value))),
            },
          ]}
        />
      )}

      {cur && (
        <div className="panel">
          <div className="row">
            <h2>
              {cur.inventory.number}{' '}
              <Badge tone={TONE[cur.inventory.status]}>
                {t(`inv.status.${cur.inventory.status}`)}
              </Badge>
            </h2>
            <button
              className="link"
              onClick={() => {
                setCur(null);
                setReport(null);
              }}
            >
              {t('common.close')}
            </button>
          </div>
          <p className="muted">{t('inv.progress', { n: counted, total: cur.lines.length })}</p>

          {isOpen && (
            <div className="row" style={{ margin: '10px 0' }}>
              <ProductSearch label={t('inv.scan')} onPick={setOnly} autoFocus clearOnPick />
              {only && (
                <button className="chip chip--on" onClick={() => setOnly(null)}>
                  {only.name} ✕
                </button>
              )}
            </div>
          )}

          <Table
            rowKey={(l) => l.lot_id}
            rows={lines}
            columns={[
              { key: 'p', header: t('st.col.product'), render: (l) => l.product },
              { key: 'l', header: t('st.lot'), render: (l) => `${l.lot_number} · ${l.expiry}` },
              {
                key: 'e',
                header: t('inv.expected'),
                render: (l) => (l.expected_qty === null ? '·' : fmt(l.expected_qty)),
              },
              {
                key: 'c',
                header: t('inv.counted'),
                render: (l) =>
                  isOpen ? (
                    <input
                      className="qty"
                      type="number"
                      min={0}
                      aria-label={`${t('inv.counted')} ${l.product} ${l.lot_number}`}
                      value={draft[l.lot_id] ?? l.counted_qty ?? ''}
                      onChange={(e) => setDraft({ ...draft, [l.lot_id]: e.target.value })}
                      onBlur={() => count(l)}
                      onKeyDown={(e) => e.key === 'Enter' && (e.target as HTMLInputElement).blur()}
                    />
                  ) : (
                    (l.counted_qty ?? '')
                  ),
              },
            ]}
          />

          <div className="actions">
            {isOpen && can('stock.write') && (
              <Button onClick={() => act('submit')} disabled={counted === 0}>
                {t('inv.submit')}
              </Button>
            )}
            {report && (
              <Button variant="secondary" onClick={exportCsv}>
                {t('inv.export')}
              </Button>
            )}
            {cur.inventory.status === 'pending_validation' && can('inventory.validate') && (
              <Button onClick={() => act('validate', t('inv.validated'))}>
                {t('inv.validate')}
              </Button>
            )}
          </div>

          {report && (
            <>
              <h3>{t('inv.gap')}</h3>
              {report.lines.length === 0 ? (
                <p className="muted">{t('inv.noGap')}</p>
              ) : (
                <Table
                  rowKey={(l) => l.product + l.lot_number}
                  rows={report.lines}
                  columns={[
                    {
                      key: 'p',
                      header: t('st.col.product'),
                      render: (l) => `${l.product} · ${l.lot_number}`,
                    },
                    { key: 'e', header: t('inv.expected'), render: (l) => fmt(l.expected_qty) },
                    { key: 'c', header: t('inv.counted'), render: (l) => fmt(l.counted_qty) },
                    {
                      key: 'g',
                      header: t('inv.gap'),
                      render: (l) => (
                        <Badge tone={l.gap < 0 ? 'danger' : 'ok'}>
                          {l.gap > 0 ? `+${l.gap}` : l.gap}
                        </Badge>
                      ),
                    },
                    { key: 'v', header: t('inv.value'), render: (l) => money(l.value) },
                  ]}
                />
              )}
              <p>
                {t('inv.loss')} : <b>{money(report.loss_value)}</b> · {t('inv.surplus')} :{' '}
                <b>{money(report.surplus_value)}</b>
              </p>
            </>
          )}
        </div>
      )}

      {creating && (
        <Modal title={t('inv.new')} onClose={() => setCreating(false)}>
          <div className="stack">
            <label className="sa-field">
              <span className="sa-field__label">{t('inv.kind')}</span>
              <select
                className="sa-field__input"
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value })}
              >
                <option value="full">{t('inv.full')}</option>
                <option value="cycle">{t('inv.cycle')}</option>
              </select>
            </label>
            {form.kind === 'cycle' && (
              <label className="sa-field">
                <span className="sa-field__label">{t('inv.family')}</span>
                <select
                  className="sa-field__input"
                  value={form.family_id}
                  onChange={(e) => setForm({ ...form, family_id: e.target.value })}
                >
                  <option value="" />
                  {families.map((f) => (
                    <option key={f.id} value={f.id}>
                      {f.name}
                    </option>
                  ))}
                </select>
              </label>
            )}
            <label className="check">
              <input
                type="checkbox"
                checked={form.blind}
                onChange={(e) => setForm({ ...form, blind: e.target.checked })}
              />{' '}
              {t('inv.blind')}
            </label>
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setCreating(false)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={create}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
    </div>
  );
}
