import { useCallback, useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { useSession } from '../app/session';
import { DocModal, money, useErrorText } from '../lib/common';

interface Row {
  id: string;
  number: string;
  status: 'completed' | 'part_returned' | 'returned' | 'cancelled';
  total: number;
  created_at: string;
  customer_name: string | null;
}
interface SaleLine {
  id: string;
  name: string;
  qty: number;
  unit_price: number;
  line_total: number;
  regulatory_status: string;
}
interface Sale extends Row {
  customer_id: string | null;
  lines: SaleLine[];
  payments: { method: string; amount: number }[];
}
interface Approver {
  id: string;
  email: string;
}
interface Ret {
  id: string;
  number: string;
  refund_amount: number;
  refund_method: string;
  reason: string;
  lines: { sale_line_id: string; qty: number }[];
  credit_note: { number: string } | null;
}
const TONE = {
  completed: 'ok',
  part_returned: 'wait',
  returned: 'neutral',
  cancelled: 'neutral',
} as const;

export function SalesPage() {
  const { t } = useT();
  const toast = useToast();
  const errText = useErrorText();
  const { can } = useSession();
  const [date, setDate] = useState(new Date().toISOString().slice(0, 10));
  const [rows, setRows] = useState<Row[]>([]);
  const [sale, setSale] = useState<Sale | null>(null);
  const [returns, setReturns] = useState<Ret[]>([]);
  const [form, setForm] = useState<{
    qty: Record<string, string>;
    reason: string;
    method: string;
    restock: boolean;
    approver: string;
    pin: string;
  } | null>(null);
  const [managers, setManagers] = useState<Approver[]>([]);
  const [needApprover, setNeedApprover] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [doc, setDoc] = useState<{ title: string; path: string } | null>(null);

  const load = useCallback(() => {
    api<{ items: Row[] }>('GET', `/app/sales?date=${date}`).then((r) => setRows(r.items));
  }, [date]);
  useEffect(load, [load]);

  async function open(r: Row) {
    // La fiche ne s'affiche qu'une fois complète : sinon la fin du chargement des retours
    // refermait un formulaire de retour déjà ouvert.
    const [detail, past] = await Promise.all([
      api<Sale>('GET', `/app/sales/${r.id}`),
      api<{ items: Ret[] }>('GET', `/app/sales/${r.id}/returns`),
    ]);
    setSale(detail);
    setReturns(past.items);
    setForm(null);
    setNeedApprover(false);
  }
  async function startReturn() {
    setForm({ qty: {}, reason: '', method: 'credit_note', restock: false, approver: '', pin: '' });
    setError(null);
    setNeedApprover(!can('sales.cancel'));
    const r = await api<{ items: Approver[] }>('GET', '/app/approvers?permission=sales.cancel');
    setManagers(r.items);
  }
  async function submit() {
    if (!sale || !form) return;
    const lines = Object.entries(form.qty)
      .filter(([, v]) => Number(v) > 0)
      .map(([id, v]) => ({ sale_line_id: id, qty: Number(v) }));
    const body: Record<string, unknown> = {
      lines,
      reason: form.reason,
      refund_method: form.method,
      restock: form.restock,
    };
    if (needApprover && form.approver) body.approver = { user_id: form.approver, pin: form.pin };
    try {
      await api('POST', `/app/sales/${sale.id}/returns`, body);
      toast(t('sale.returnDone'));
      load();
      await open(sale);
    } catch (e) {
      if (e instanceof ApiError && e.status === 403) setNeedApprover(true);
      setError(errText(e));
    }
  }
  const already = (lineId: string) =>
    returns.reduce(
      (s, r) => s + r.lines.filter((l) => l.sale_line_id === lineId).reduce((a, l) => a + l.qty, 0),
      0,
    );

  return (
    <div>
      <div className="row">
        <h1>{t('sale.title')}</h1>
        <input
          type="date"
          className="search"
          aria-label="Date"
          value={date}
          onChange={(e) => setDate(e.target.value)}
        />
      </div>
      <Table
        rowKey={(r) => r.id}
        rows={rows}
        columns={[
          {
            key: 'n',
            header: t('sale.col.number'),
            render: (r) => (
              <button className="link-strong" onClick={() => open(r)}>
                {r.number}
              </button>
            ),
          },
          { key: 'd', header: t('sale.col.date'), render: (r) => r.created_at.slice(11, 16) },
          { key: 'c', header: '', render: (r) => r.customer_name ?? '' },
          { key: 't', header: t('sale.col.total'), render: (r) => money(r.total) },
          {
            key: 's',
            header: t('sale.col.status'),
            render: (r) => <Badge tone={TONE[r.status]}>{t(`sale.status.${r.status}`)}</Badge>,
          },
        ]}
      />

      {sale && (
        <Modal title={sale.number} onClose={() => setSale(null)}>
          {!form && (
            <>
              <Table
                rowKey={(l) => l.id}
                rows={sale.lines}
                columns={[
                  { key: 'n', header: t('st.col.product'), render: (l) => l.name },
                  {
                    key: 'q',
                    header: t('pos.qty'),
                    render: (l) => `${l.qty}${already(l.id) > 0 ? ` (−${already(l.id)})` : ''}`,
                  },
                  { key: 't', header: t('pos.total'), render: (l) => money(l.line_total) },
                ]}
              />
              <p>
                {t('pos.total')} : <b>{money(sale.total)}</b>
              </p>
              {returns.map((r) => (
                <p key={r.id} className="muted">
                  {r.number} · {money(r.refund_amount)} · {r.reason}{' '}
                  {r.credit_note && (
                    <button
                      className="link-strong"
                      onClick={() =>
                        setDoc({
                          title: r.credit_note!.number,
                          path: `/app/returns/${r.id}/credit-note`,
                        })
                      }
                    >
                      {t('sale.creditNote')}
                    </button>
                  )}
                </p>
              ))}
              <div className="actions">
                <Button
                  variant="secondary"
                  onClick={() =>
                    setDoc({ title: sale.number, path: `/app/sales/${sale.id}/receipt` })
                  }
                >
                  {t('sale.reprint')}
                </Button>
                <Button
                  variant="secondary"
                  onClick={() =>
                    setDoc({ title: t('pay.invoice'), path: `/app/sales/${sale.id}/invoice` })
                  }
                >
                  {t('pay.invoice')}
                </Button>
                {sale.status !== 'returned' && sale.status !== 'cancelled' && (
                  <Button variant="danger" onClick={startReturn}>
                    {t('sale.return')}
                  </Button>
                )}
              </div>
            </>
          )}
          {form && (
            <div className="stack">
              {sale.lines.map((l) => (
                <Field
                  key={l.id}
                  label={`${l.name} · ${t('sale.returnQty')} (max ${l.qty - already(l.id)})`}
                  type="number"
                  min={0}
                  max={l.qty - already(l.id)}
                  value={form.qty[l.id] ?? ''}
                  onChange={(e) =>
                    setForm({ ...form, qty: { ...form.qty, [l.id]: e.target.value } })
                  }
                />
              ))}
              <Field
                label={t('st.reason')}
                value={form.reason}
                onChange={(e) => setForm({ ...form, reason: e.target.value })}
              />
              <label className="sa-field">
                <span className="sa-field__label">{t('sale.refund')}</span>
                <select
                  className="sa-field__input"
                  value={form.method}
                  onChange={(e) => setForm({ ...form, method: e.target.value })}
                >
                  {['credit_note', 'cash', ...(sale?.customer_id ? ['account'] : []), 'other'].map(
                    (m) => (
                      <option key={m} value={m}>
                        {t(`sale.refund.${m}`)}
                      </option>
                    ),
                  )}
                </select>
              </label>
              <label className="check">
                <input
                  type="checkbox"
                  checked={form.restock}
                  onChange={(e) => setForm({ ...form, restock: e.target.checked })}
                />{' '}
                {t('sale.restock')}
              </label>
              {needApprover && (
                <div className="grid2">
                  <label className="sa-field">
                    <span className="sa-field__label">{t('appr.who')}</span>
                    <select
                      className="sa-field__input"
                      value={form.approver}
                      onChange={(e) => setForm({ ...form, approver: e.target.value })}
                    >
                      <option value="" />
                      {managers.map((m) => (
                        <option key={m.id} value={m.id}>
                          {m.email}
                        </option>
                      ))}
                    </select>
                  </label>
                  <Field
                    label={t('appr.pin')}
                    type="password"
                    inputMode="numeric"
                    maxLength={4}
                    value={form.pin}
                    onChange={(e) => setForm({ ...form, pin: e.target.value })}
                  />
                </div>
              )}
              {error && (
                <div role="alert" className="form-error">
                  {error}
                </div>
              )}
              <div className="actions">
                <Button variant="secondary" onClick={() => setForm(null)}>
                  {t('common.cancel')}
                </Button>
                <Button variant="danger" onClick={submit}>
                  {t('sale.return')}
                </Button>
              </div>
            </div>
          )}
        </Modal>
      )}
      {doc && <DocModal title={doc.title} path={doc.path} onClose={() => setDoc(null)} />}
    </div>
  );
}
