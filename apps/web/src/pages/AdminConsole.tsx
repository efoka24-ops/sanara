import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { fmt, money } from '../lib/common';

/** Console super admin (maquettes S1, B3 à B11). */

export function BackLink({ to, label }: { to?: string; label?: string }) {
  const { t } = useT();
  const nav = useNavigate();
  return (
    <button type="button" className="back" onClick={() => (to ? nav(to) : nav(-1))}>
      ← {label ?? t('adm.back')}
    </button>
  );
}

function useAdminError() {
  const { t } = useT();
  return (e: unknown) => {
    if (e instanceof ApiError) {
      if (e.status === 409) return t('adm.err.conflict');
      if (e.status === 422) {
        const unknown = (e.details as { unknown?: string[] }).unknown;
        return unknown?.length
          ? t('adm.tpl.unknown', { v: unknown.join(', ') })
          : t('adm.err.invalid');
      }
    }
    return t('errors.server_error');
  };
}

/* ---------- S1 : tableau de bord ---------- */
interface Dash {
  pending_applications: number;
  active_pharmacies: number;
  suspended_pharmacies: number;
  open_reports: number;
  overdue_invoices: number;
  mrr: number;
  queue: {
    id: string;
    number: string;
    pharmacy_name: string;
    city: string;
    region: string;
    status: string;
    created_at: string;
  }[];
}
export function AdminDashboardPage() {
  const { t } = useT();
  const [d, setD] = useState<Dash | null>(null);
  useEffect(() => {
    api<Dash>('GET', '/admin/dashboard').then(setD);
  }, []);
  if (!d) return <div />;
  const tiles: [string, string | number, string][] = [
    ['adm.dash.pending', d.pending_applications, '/admin/demandes'],
    ['adm.dash.active', d.active_pharmacies, '/admin/pharmacies'],
    ['adm.dash.suspended', d.suspended_pharmacies, '/admin/pharmacies?status=suspended'],
    ['adm.dash.reports', d.open_reports, '/admin/signalements'],
    ['adm.dash.overdue', d.overdue_invoices, '/admin/facturation'],
    ['adm.dash.mrr', money(d.mrr), '/admin/abonnements'],
  ];
  return (
    <div>
      <h1>{t('adm.dash.title')}</h1>
      <div className="tiles">
        {tiles.map(([k, v, to]) => (
          <Link key={k} to={to} className="tile">
            <span className="muted">{t(k)}</span>
            <b>{v}</b>
          </Link>
        ))}
      </div>
      <div className="row" style={{ marginTop: 24 }}>
        <h2>{t('adm.dash.queue')}</h2>
        <Link to="/admin/demandes">{t('adm.dash.seeAll')}</Link>
      </div>
      {d.queue.length === 0 ? (
        <p className="muted">{t('adm.dash.queueEmpty')}</p>
      ) : (
        <Table
          rowKey={(r) => r.id}
          rows={d.queue}
          columns={[
            { key: 'n', header: 'N°', render: (r) => r.number },
            { key: 'p', header: t('adm.ph.col.name'), render: (r) => r.pharmacy_name },
            { key: 'c', header: '', render: (r) => `${r.city}, ${r.region}` },
            { key: 'd', header: 'Date', render: (r) => r.created_at.slice(0, 10) },
            {
              key: 'x',
              header: '',
              render: (r) => <Link to={`/admin/demandes/${r.id}`}>{t('admin.apps.examine')}</Link>,
            },
          ]}
        />
      )}
    </div>
  );
}

/* ---------- B3 : pharmacies ---------- */
interface Ph {
  id: string;
  name: string;
  status: string;
  owner_email: string | null;
  plan: string | null;
  subscription_status: string | null;
  created_at: string;
}
export function AdminPharmaciesPage() {
  const { t } = useT();
  const params = new URLSearchParams(window.location.search);
  const [q, setQ] = useState('');
  const [status, setStatus] = useState(params.get('status') ?? '');
  const [rows, setRows] = useState<Ph[]>([]);
  useEffect(() => {
    const h = window.setTimeout(() => {
      api<{ items: Ph[] }>('GET', `/admin/pharmacies?${new URLSearchParams({ q, status })}`).then(
        (r) => setRows(r.items),
      );
    }, 150);
    return () => window.clearTimeout(h);
  }, [q, status]);
  return (
    <div>
      <h1>{t('adm.ph.title')}</h1>
      <div className="row" style={{ margin: '14px 0' }}>
        <input
          className="search"
          aria-label={t('adm.ph.search')}
          placeholder={t('adm.ph.search')}
          value={q}
          onChange={(e) => setQ(e.target.value)}
        />
        <div className="tabs">
          {[
            ['', 'all'],
            ['active', 'active'],
            ['suspended', 'suspended'],
          ].map(([v, k]) => (
            <button
              key={k}
              className={`chip${status === v ? ' chip--on' : ''}`}
              onClick={() => setStatus(v!)}
            >
              {t(`adm.ph.${k}`)}
            </button>
          ))}
        </div>
      </div>
      <Table
        rowKey={(r) => r.id}
        rows={rows}
        columns={[
          {
            key: 'n',
            header: t('adm.ph.col.name'),
            render: (r) => (
              <Link className="link-strong" to={`/admin/pharmacies/${r.id}`}>
                {r.name}
              </Link>
            ),
          },
          { key: 'o', header: t('adm.ph.col.owner'), render: (r) => r.owner_email ?? '' },
          { key: 'p', header: t('adm.ph.col.plan'), render: (r) => r.plan ?? t('adm.ph.noPlan') },
          {
            key: 's',
            header: t('adm.ph.col.status'),
            render: (r) => (
              <Badge tone={r.status === 'active' ? 'ok' : 'danger'}>
                {t(`adm.ph.status.${r.status}`)}
              </Badge>
            ),
          },
        ]}
      />
    </div>
  );
}

/* ---------- B4 : fiche et suspension ---------- */
interface PhDetail {
  id: string;
  name: string;
  status: string;
  address: string | null;
  phone: string | null;
  license_number: string | null;
  created_at: string;
  team: { id: string; email: string; role_code: string; status: string }[];
  subscription: {
    status: string;
    stations: number;
    plan_id: string;
    plan: string;
    period_end: string | null;
    trial_ends_at: string | null;
  } | null;
  invoices: { id: string; number: string; amount: number; status: string; due_at: string }[];
  activity: { sales_30d: number; revenue_30d: number };
  audit: { action: string; created_at: string }[];
}
interface Plan {
  id: string;
  code: string;
  name: string;
  price_month: number;
  max_stations: number;
  features: string | null;
  active: number;
  pharmacies: number;
}
export function AdminPharmacyPage() {
  const { t } = useT();
  const { id } = useParams();
  const toast = useToast();
  const err = useAdminError();
  const [p, setP] = useState<PhDetail | null>(null);
  const [plans, setPlans] = useState<Plan[]>([]);
  const [suspending, setSuspending] = useState(false);
  const [reason, setReason] = useState('');
  const [sub, setSub] = useState({ plan_id: '', stations: '1', status: 'trial' });
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<PhDetail>('GET', `/admin/pharmacies/${id}`).then((d) => {
      setP(d);
      if (d.subscription)
        setSub({
          plan_id: d.subscription.plan_id,
          stations: String(d.subscription.stations),
          status: d.subscription.status,
        });
    });
  }, [id]);
  useEffect(() => {
    load();
    api<{ items: Plan[] }>('GET', '/admin/plans').then((r) =>
      setPlans(r.items.filter((x) => x.active)),
    );
  }, [load]);
  if (!p) return <div />;
  const maxStations = plans.find((x) => x.id === sub.plan_id)?.max_stations;
  const stations = Number(sub.stations);
  const stationsValid =
    maxStations !== undefined &&
    Number.isInteger(stations) &&
    stations >= 1 &&
    stations <= maxStations;
  async function suspend() {
    try {
      await api('POST', `/admin/pharmacies/${id}/suspend`, { reason });
      setSuspending(false);
      setReason('');
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  async function saveSub() {
    try {
      await api('PUT', `/admin/pharmacies/${id}/subscription`, {
        plan_id: sub.plan_id,
        stations: Number(sub.stations),
        status: sub.status,
      });
      toast(t('adm.set.saved'));
      load();
    } catch (e) {
      toast(err(e), 'error');
    }
  }
  return (
    <div>
      <BackLink to="/admin/pharmacies" label={t('adm.ph.title')} />
      <div className="row">
        <h1>
          {p.name}{' '}
          <Badge tone={p.status === 'active' ? 'ok' : 'danger'}>
            {t(`adm.ph.status.${p.status}`)}
          </Badge>
        </h1>
        {p.status === 'active' ? (
          <Button
            variant="danger"
            onClick={() => {
              setSuspending(true);
              setError(null);
            }}
          >
            {t('adm.ph.suspend')}
          </Button>
        ) : (
          <Button
            onClick={async () => {
              await api('POST', `/admin/pharmacies/${id}/reactivate`, {});
              load();
            }}
          >
            {t('adm.ph.reactivate')}
          </Button>
        )}
      </div>
      <p className="muted">{[p.address, p.phone, p.license_number].filter(Boolean).join(' · ')}</p>
      <div className="cols2">
        <section className="panel">
          <h2>{t('adm.ph.activity')}</h2>
          <p>
            <b>{fmt(p.activity.sales_30d)}</b> {t('adm.ph.sales')} ·{' '}
            <b>{money(p.activity.revenue_30d)}</b>
          </p>
          <h2>{t('adm.ph.team')}</h2>
          <ul className="sessions">
            {p.team.map((u) => (
              <li key={u.id}>
                <span>{u.email}</span>
                <span className="muted">{u.role_code}</span>
              </li>
            ))}
          </ul>
        </section>
        <section className="panel stack">
          <h2>{t('adm.ph.assign')}</h2>
          <label className="sa-field">
            <span className="sa-field__label">{t('adm.ph.col.plan')}</span>
            <select
              className="sa-field__input"
              value={sub.plan_id}
              onChange={(e) => setSub({ ...sub, plan_id: e.target.value })}
            >
              <option value="" />
              {plans.map((x) => (
                <option key={x.id} value={x.id}>
                  {x.name} · {money(x.price_month)}
                </option>
              ))}
            </select>
          </label>
          <div className="grid2">
            <Field
              label={t('adm.ph.stations')}
              type="number"
              min={1}
              max={maxStations}
              value={sub.stations}
              error={
                maxStations !== undefined && !stationsValid
                  ? t('adm.ph.stationsRange', { max: maxStations })
                  : undefined
              }
              onChange={(e) => setSub({ ...sub, stations: e.target.value })}
            />
            <label className="sa-field">
              <span className="sa-field__label">{t('adm.ph.subStatus')}</span>
              <select
                className="sa-field__input"
                value={sub.status}
                onChange={(e) => setSub({ ...sub, status: e.target.value })}
              >
                {['trial', 'active', 'grace', 'cancelled'].map((s) => (
                  <option key={s} value={s}>
                    {t(`adm.sub.${s}`)}
                  </option>
                ))}
              </select>
            </label>
          </div>
          {maxStations !== undefined && stationsValid && (
            <p className="muted">{t('adm.ph.stationsMax', { max: maxStations })}</p>
          )}
          <Button onClick={saveSub} disabled={!stationsValid}>
            {t('common.save')}
          </Button>
        </section>
      </div>
      <h2>{t('adm.ph.invoices')}</h2>
      <Table
        rowKey={(i) => i.id}
        rows={p.invoices}
        columns={[
          { key: 'n', header: t('adm.bill.number'), render: (i) => i.number },
          { key: 'a', header: t('adm.bill.amount'), render: (i) => money(i.amount) },
          { key: 'd', header: t('adm.bill.due'), render: (i) => i.due_at.slice(0, 10) },
          {
            key: 's',
            header: t('adm.bill.status'),
            render: (i) => (
              <Badge tone={i.status === 'paid' ? 'ok' : i.status === 'overdue' ? 'danger' : 'wait'}>
                {t(`adm.bill.${i.status}`)}
              </Badge>
            ),
          },
        ]}
      />
      <h2>{t('adm.ph.recentAudit')}</h2>
      <ul className="sessions">
        {p.audit.map((a, i) => (
          <li key={i}>
            <span>{a.action}</span>
            <span className="muted">{a.created_at.slice(0, 16)}</span>
          </li>
        ))}
      </ul>
      {suspending && (
        <Modal title={t('adm.ph.suspend')} onClose={() => setSuspending(false)}>
          <p className="muted">{t('adm.ph.suspendWarn')}</p>
          <Field
            label={t('adm.ph.suspendReason')}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          {error && (
            <div role="alert" className="form-error">
              {error}
            </div>
          )}
          <div className="actions">
            <Button variant="secondary" onClick={() => setSuspending(false)}>
              {t('common.cancel')}
            </Button>
            <Button variant="danger" onClick={suspend}>
              {t('adm.ph.suspend')}
            </Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/* ---------- B5 : formules ---------- */
export function AdminPlansPage() {
  const { t } = useT();
  const err = useAdminError();
  const [plans, setPlans] = useState<Plan[]>([]);
  const [form, setForm] = useState<{
    id?: string;
    code: string;
    name: string;
    price: string;
    stations: string;
    features: string;
    active: boolean;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ items: Plan[] }>('GET', '/admin/plans').then((r) => setPlans(r.items));
  }, []);
  useEffect(load, [load]);
  async function save() {
    if (!form) return;
    const body = {
      code: form.code,
      name: form.name,
      price_month: Number(form.price),
      max_stations: Number(form.stations),
      features: form.features,
      active: form.active,
    };
    try {
      if (form.id) await api('PUT', `/admin/plans/${form.id}`, body);
      else await api('POST', '/admin/plans', body);
      setForm(null);
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  return (
    <div>
      <div className="row">
        <h1>{t('adm.plans.title')}</h1>
        <Button
          onClick={() => {
            setForm({ code: '', name: '', price: '0', stations: '1', features: '', active: true });
            setError(null);
          }}
        >
          {t('adm.plans.new')}
        </Button>
      </div>
      <div className="tiles">
        {plans.map((p) => (
          <button
            key={p.id}
            className="tile tile--btn"
            onClick={() => {
              setForm({
                id: p.id,
                code: p.code,
                name: p.name,
                price: String(p.price_month),
                stations: String(p.max_stations),
                features: p.features ?? '',
                active: !!p.active,
              });
              setError(null);
            }}
          >
            <span className="muted">
              {p.name} {!p.active && <Badge tone="neutral">off</Badge>}
            </span>
            <b>{money(p.price_month)}</b>
            <span className="muted">
              {p.max_stations} {t('adm.ph.stations').toLowerCase()} · {p.pharmacies}{' '}
              {t('adm.plans.pharmacies')}
            </span>
            <span className="muted">{p.features}</span>
          </button>
        ))}
      </div>
      {form && (
        <Modal title={form.id ? form.name : t('adm.plans.new')} onClose={() => setForm(null)}>
          <div className="stack">
            {!form.id && (
              <Field
                label={t('adm.plans.code')}
                value={form.code}
                onChange={(e) => setForm({ ...form, code: e.target.value })}
              />
            )}
            <Field
              label={t('adm.plans.name')}
              value={form.name}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            <div className="grid2">
              <Field
                label={t('adm.plans.price')}
                type="number"
                min={0}
                value={form.price}
                onChange={(e) => setForm({ ...form, price: e.target.value })}
              />
              <Field
                label={t('adm.plans.stations')}
                type="number"
                min={1}
                value={form.stations}
                onChange={(e) => setForm({ ...form, stations: e.target.value })}
              />
            </div>
            <Field
              label={t('adm.plans.features')}
              value={form.features}
              onChange={(e) => setForm({ ...form, features: e.target.value })}
            />
            {form.id && (
              <label className="check">
                <input
                  type="checkbox"
                  checked={form.active}
                  onChange={(e) => setForm({ ...form, active: e.target.checked })}
                />{' '}
                {t('adm.plans.active')}
              </label>
            )}
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setForm(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={save}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/* ---------- B6 : facturation ---------- */
interface Inv {
  id: string;
  number: string;
  pharmacy: string | null;
  pharmacy_id: string;
  amount: number;
  status: string;
  due_at: string;
  method: string | null;
  reference: string | null;
}
export function AdminBillingPage() {
  const { t } = useT();
  const err = useAdminError();
  const [status, setStatus] = useState('');
  const [rows, setRows] = useState<Inv[]>([]);
  const [pharmacies, setPharmacies] = useState<Ph[]>([]);
  const [form, setForm] = useState<{
    pharmacy_id: string;
    amount: string;
    due_days: string;
  } | null>(null);
  const [pay, setPay] = useState<{ id: string; method: string; reference: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ items: Inv[] }>('GET', `/admin/invoices?status=${status}`).then((r) => setRows(r.items));
  }, [status]);
  useEffect(load, [load]);
  useEffect(() => {
    api<{ items: Ph[] }>('GET', '/admin/pharmacies').then((r) => setPharmacies(r.items));
  }, []);
  async function create() {
    if (!form) return;
    try {
      await api('POST', '/admin/invoices', {
        pharmacy_id: form.pharmacy_id,
        amount: Number(form.amount),
        due_days: Number(form.due_days),
      });
      setForm(null);
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  async function markPaid() {
    if (!pay) return;
    try {
      await api('POST', `/admin/invoices/${pay.id}/paid`, {
        method: pay.method,
        reference: pay.reference,
      });
      setPay(null);
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  const tone = (s: string) =>
    s === 'paid' ? 'ok' : s === 'overdue' ? 'danger' : s === 'void' ? 'neutral' : 'wait';
  return (
    <div>
      <div className="row">
        <h1>{t('adm.bill.title')}</h1>
        <Button
          onClick={() => {
            setForm({ pharmacy_id: '', amount: '', due_days: '15' });
            setError(null);
          }}
        >
          {t('adm.bill.new')}
        </Button>
      </div>
      <div className="tabs" style={{ margin: '12px 0' }}>
        {['', 'issued', 'overdue', 'paid', 'void'].map((s) => (
          <button
            key={s}
            className={`chip${status === s ? ' chip--on' : ''}`}
            onClick={() => setStatus(s)}
          >
            {s === '' ? t('adm.ph.all') : t(`adm.bill.${s}`)}
          </button>
        ))}
      </div>
      <Table
        rowKey={(i) => i.id}
        rows={rows}
        columns={[
          { key: 'n', header: t('adm.bill.number'), render: (i) => i.number },
          {
            key: 'p',
            header: t('adm.bill.pharmacy'),
            render: (i) => <Link to={`/admin/pharmacies/${i.pharmacy_id}`}>{i.pharmacy}</Link>,
          },
          { key: 'a', header: t('adm.bill.amount'), render: (i) => money(i.amount) },
          { key: 'd', header: t('adm.bill.due'), render: (i) => i.due_at.slice(0, 10) },
          {
            key: 's',
            header: t('adm.bill.status'),
            render: (i) => <Badge tone={tone(i.status)}>{t(`adm.bill.${i.status}`)}</Badge>,
          },
          {
            key: 'x',
            header: '',
            render: (i) =>
              i.status === 'issued' || i.status === 'overdue' ? (
                <span className="row">
                  <button
                    className="link-strong"
                    onClick={() => {
                      setPay({ id: i.id, method: 'momo_mtn', reference: '' });
                      setError(null);
                    }}
                  >
                    {t('adm.bill.markPaid')}
                  </button>
                  <button
                    className="link"
                    onClick={async () => {
                      await api('POST', `/admin/invoices/${i.id}/void`, {});
                      load();
                    }}
                  >
                    {t('adm.bill.cancel')}
                  </button>
                </span>
              ) : (
                (i.reference ?? '')
              ),
          },
        ]}
      />
      {form && (
        <Modal title={t('adm.bill.new')} onClose={() => setForm(null)}>
          <div className="stack">
            <label className="sa-field">
              <span className="sa-field__label">{t('adm.bill.pharmacy')}</span>
              <select
                className="sa-field__input"
                value={form.pharmacy_id}
                onChange={(e) => setForm({ ...form, pharmacy_id: e.target.value })}
              >
                <option value="" />
                {pharmacies.map((p) => (
                  <option key={p.id} value={p.id}>
                    {p.name}
                  </option>
                ))}
              </select>
            </label>
            <div className="grid2">
              <Field
                label={t('adm.bill.amount')}
                type="number"
                min={1}
                value={form.amount}
                onChange={(e) => setForm({ ...form, amount: e.target.value })}
              />
              <Field
                label={t('adm.bill.dueDays')}
                type="number"
                min={0}
                value={form.due_days}
                onChange={(e) => setForm({ ...form, due_days: e.target.value })}
              />
            </div>
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setForm(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={create}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
      {pay && (
        <Modal title={t('adm.bill.markPaid')} onClose={() => setPay(null)}>
          <div className="stack">
            <label className="sa-field">
              <span className="sa-field__label">{t('adm.bill.method')}</span>
              <select
                className="sa-field__input"
                value={pay.method}
                onChange={(e) => setPay({ ...pay, method: e.target.value })}
              >
                {['momo_mtn', 'momo_orange', 'bank', 'cash'].map((m) => (
                  <option key={m} value={m}>
                    {t(`adm.bill.${m}`)}
                  </option>
                ))}
              </select>
            </label>
            <Field
              label={t('adm.bill.reference')}
              value={pay.reference}
              onChange={(e) => setPay({ ...pay, reference: e.target.value })}
            />
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setPay(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={markPaid}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/* ---------- B7 : signalements ---------- */
interface Rep {
  id: string;
  kind: string;
  pharmacy: string | null;
  subject: string;
  details: string | null;
  status: string;
  note: string | null;
  created_at: string;
}
export function AdminReportsPage() {
  const { t } = useT();
  const err = useAdminError();
  const [status, setStatus] = useState('open');
  const [rows, setRows] = useState<Rep[]>([]);
  const [handle, setHandle] = useState<{
    id: string;
    decision: 'dismissed' | 'actioned';
    note: string;
  } | null>(null);
  const [form, setForm] = useState<{ kind: string; subject: string; details: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ items: Rep[] }>('GET', `/admin/reports?status=${status}`).then((r) => setRows(r.items));
  }, [status]);
  useEffect(load, [load]);
  async function submitHandle() {
    if (!handle) return;
    try {
      await api('POST', `/admin/reports/${handle.id}/handle`, {
        decision: handle.decision,
        note: handle.note,
      });
      setHandle(null);
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  async function create() {
    if (!form) return;
    try {
      await api('POST', '/admin/reports', form);
      setForm(null);
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  return (
    <div>
      <div className="row">
        <h1>{t('adm.rep.title')}</h1>
        <Button
          onClick={() => {
            setForm({ kind: 'false_urgency', subject: '', details: '' });
            setError(null);
          }}
        >
          {t('adm.rep.new')}
        </Button>
      </div>
      <div className="tabs" style={{ margin: '12px 0' }}>
        {['open', 'dismissed', 'actioned'].map((s) => (
          <button
            key={s}
            className={`chip${status === s ? ' chip--on' : ''}`}
            onClick={() => setStatus(s)}
          >
            {t(`adm.rep.${s}`)}
          </button>
        ))}
      </div>
      {rows.length === 0 && <p className="muted">{t('adm.rep.empty')}</p>}
      <ul className="cards">
        {rows.map((r) => (
          <li key={r.id} className="panel">
            <div className="row">
              <b>{r.subject}</b>
              <Badge tone={r.kind === 'false_urgency' ? 'danger' : 'wait'}>
                {t(`adm.rep.kind.${r.kind}`)}
              </Badge>
            </div>
            <p className="muted">
              {[r.pharmacy, r.created_at.slice(0, 10)].filter(Boolean).join(' · ')}
            </p>
            {r.details && <p>{r.details}</p>}
            {r.note && (
              <p className="muted">
                {t('adm.rep.note')} : {r.note}
              </p>
            )}
            {r.status === 'open' && (
              <div className="actions">
                <Button
                  variant="secondary"
                  onClick={() => {
                    setHandle({ id: r.id, decision: 'dismissed', note: '' });
                    setError(null);
                  }}
                >
                  {t('adm.rep.dismiss')}
                </Button>
                <Button
                  onClick={() => {
                    setHandle({ id: r.id, decision: 'actioned', note: '' });
                    setError(null);
                  }}
                >
                  {t('adm.rep.action')}
                </Button>
              </div>
            )}
          </li>
        ))}
      </ul>
      {handle && (
        <Modal
          title={handle.decision === 'dismissed' ? t('adm.rep.dismiss') : t('adm.rep.action')}
          onClose={() => setHandle(null)}
        >
          <Field
            label={t('adm.rep.note')}
            value={handle.note}
            onChange={(e) => setHandle({ ...handle, note: e.target.value })}
          />
          {error && (
            <div role="alert" className="form-error">
              {error}
            </div>
          )}
          <div className="actions">
            <Button variant="secondary" onClick={() => setHandle(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={submitHandle}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
      {form && (
        <Modal title={t('adm.rep.new')} onClose={() => setForm(null)}>
          <div className="stack">
            <label className="sa-field">
              <span className="sa-field__label">Type</span>
              <select
                className="sa-field__input"
                value={form.kind}
                onChange={(e) => setForm({ ...form, kind: e.target.value })}
              >
                {['false_urgency', 'abuse', 'content', 'other'].map((k) => (
                  <option key={k} value={k}>
                    {t(`adm.rep.kind.${k}`)}
                  </option>
                ))}
              </select>
            </label>
            <Field
              label={t('adm.rep.subject')}
              value={form.subject}
              onChange={(e) => setForm({ ...form, subject: e.target.value })}
            />
            <Field
              label={t('adm.rep.details')}
              value={form.details}
              onChange={(e) => setForm({ ...form, details: e.target.value })}
            />
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setForm(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={create}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/* ---------- B8 : modèles d'e-mails ---------- */
interface Tpl {
  code: string;
  locale: 'fr' | 'en';
  subject: string;
  body: string;
  variables: string[];
  customized: boolean;
}
export function AdminTemplatesPage() {
  const { t } = useT();
  const toast = useToast();
  const err = useAdminError();
  const [items, setItems] = useState<Tpl[]>([]);
  const [code, setCode] = useState('access_granted');
  const [locale, setLocale] = useState<'fr' | 'en'>('fr');
  const [draft, setDraft] = useState({ subject: '', body: '' });
  const [preview, setPreview] = useState<{ subject: string; text: string } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ items: Tpl[] }>('GET', '/admin/templates').then((r) => setItems(r.items));
  }, []);
  useEffect(load, [load]);
  const cur = items.find((x) => x.code === code && x.locale === locale);
  useEffect(() => {
    if (cur) setDraft({ subject: cur.subject, body: cur.body });
    setPreview(null);
    setError(null);
  }, [cur?.code, cur?.locale, cur?.subject, cur?.body]);
  const codes = [...new Set(items.map((i) => i.code))];
  async function save() {
    try {
      await api('PUT', `/admin/templates/${code}/${locale}`, draft);
      toast(t('adm.tpl.saved'));
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  async function showPreview() {
    setPreview(
      await api<{ subject: string; text: string }>(
        'POST',
        `/admin/templates/${code}/${locale}/preview`,
        draft,
      ),
    );
  }
  return (
    <div>
      <h1>{t('adm.tpl.title')}</h1>
      <div className="split">
        <ul className="list">
          {codes.map((c) => (
            <li key={c}>
              <button
                className={`list__item${c === code ? ' is-on' : ''}`}
                onClick={() => setCode(c)}
              >
                {t(`adm.tpl.${c}`)}{' '}
                {items.some((i) => i.code === c && i.customized) && (
                  <Badge tone="wait">{t('adm.tpl.customized')}</Badge>
                )}
              </button>
            </li>
          ))}
        </ul>
        {cur && (
          <section className="panel stack">
            <div className="tabs">
              {(['fr', 'en'] as const).map((l) => (
                <button
                  key={l}
                  className={`chip${locale === l ? ' chip--on' : ''}`}
                  onClick={() => setLocale(l)}
                >
                  {l.toUpperCase()}
                </button>
              ))}
            </div>
            <Field
              label={t('adm.tpl.subject')}
              value={draft.subject}
              onChange={(e) => setDraft({ ...draft, subject: e.target.value })}
            />
            <label className="sa-field">
              <span className="sa-field__label">{t('adm.tpl.body')}</span>
              <textarea
                className="sa-field__input"
                rows={9}
                value={draft.body}
                onChange={(e) => setDraft({ ...draft, body: e.target.value })}
              />
            </label>
            <p className="muted">
              {t('adm.tpl.variables')} : {cur.variables.map((v) => `{{${v}}}`).join('  ')}
            </p>
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
            <div className="actions">
              {cur.customized && (
                <button
                  className="link"
                  onClick={async () => {
                    await api('DELETE', `/admin/templates/${code}/${locale}`);
                    load();
                  }}
                >
                  {t('adm.tpl.reset')}
                </button>
              )}
              <Button variant="secondary" onClick={showPreview}>
                {t('adm.tpl.preview')}
              </Button>
              <Button onClick={save}>{t('common.save')}</Button>
            </div>
            {preview && (
              <div className="preview" aria-label={t('adm.tpl.preview')}>
                <b>{preview.subject}</b>
                <pre>{preview.text}</pre>
              </div>
            )}
          </section>
        )}
      </div>
    </div>
  );
}

/* ---------- B9 : journal d'audit ---------- */
interface AuditRow {
  id: string;
  action: string;
  pharmacy: string | null;
  user_email: string | null;
  created_at: string;
}
export function AdminAuditPage() {
  const { t } = useT();
  const [action, setAction] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [rows, setRows] = useState<AuditRow[]>([]);
  useEffect(() => {
    const h = window.setTimeout(() => {
      api<{ items: AuditRow[] }>(
        'GET',
        `/admin/audit?${new URLSearchParams({ action, from, to })}`,
      ).then((r) => setRows(r.items));
    }, 200);
    return () => window.clearTimeout(h);
  }, [action, from, to]);
  return (
    <div>
      <h1>{t('adm.aud.title')}</h1>
      <p className="muted">{t('adm.aud.readonly')}</p>
      <div className="row" style={{ margin: '12px 0' }}>
        <input
          className="search"
          aria-label={t('adm.aud.action')}
          placeholder={t('adm.aud.action')}
          value={action}
          onChange={(e) => setAction(e.target.value)}
        />
        <Field
          label={t('adm.aud.from')}
          type="date"
          value={from}
          onChange={(e) => setFrom(e.target.value)}
        />
        <Field
          label={t('adm.aud.to')}
          type="date"
          value={to}
          onChange={(e) => setTo(e.target.value)}
        />
      </div>
      {rows.length === 0 ? (
        <p className="muted">{t('adm.aud.empty')}</p>
      ) : (
        <Table
          rowKey={(r) => r.id}
          rows={rows}
          columns={[
            {
              key: 'd',
              header: t('adm.aud.when'),
              render: (r) => r.created_at.slice(0, 19).replace('T', ' '),
            },
            { key: 'u', header: t('adm.aud.who'), render: (r) => r.user_email ?? '' },
            { key: 'a', header: t('adm.aud.what'), render: (r) => <code>{r.action}</code> },
            { key: 'p', header: t('adm.aud.where'), render: (r) => r.pharmacy ?? '' },
          ]}
        />
      )}
    </div>
  );
}

/* ---------- B11 : pays et zones ---------- */
interface Country {
  code: string;
  name_fr: string;
  currency: string;
  dial_code: string;
  status: string;
  authority: string;
  required_documents: string[];
  retention_years: number;
  pharmacies: number;
}
export function AdminCountriesPage() {
  const { t } = useT();
  const toast = useToast();
  const err = useAdminError();
  const [items, setItems] = useState<Country[]>([]);
  const [edit, setEdit] = useState<{
    code: string;
    status: string;
    authority: string;
    docs: string;
    retention: string;
  } | null>(null);
  const [error, setError] = useState<string | null>(null);
  const load = useCallback(() => {
    api<{ items: Country[] }>('GET', '/admin/countries').then((r) => setItems(r.items));
  }, []);
  useEffect(load, [load]);
  async function save() {
    if (!edit) return;
    try {
      await api('PUT', `/admin/countries/${edit.code}`, {
        status: edit.status,
        authority: edit.authority,
        required_documents: edit.docs.split('\n'),
        retention_years: Number(edit.retention),
      });
      toast(t('adm.cty.saved'));
      setEdit(null);
      load();
    } catch (e) {
      setError(err(e));
    }
  }
  const tone = (s: string) => (s === 'active' ? 'ok' : s === 'preparing' ? 'wait' : 'neutral');
  return (
    <div>
      <h1>{t('adm.cty.title')}</h1>
      <Table
        rowKey={(c) => c.code}
        rows={items}
        columns={[
          {
            key: 'n',
            header: 'Pays',
            render: (c) => (
              <button
                className="link-strong"
                onClick={() => {
                  setEdit({
                    code: c.code,
                    status: c.status,
                    authority: c.authority,
                    docs: c.required_documents.join('\n'),
                    retention: String(c.retention_years),
                  });
                  setError(null);
                }}
              >
                {c.name_fr} ({c.code})
              </button>
            ),
          },
          { key: 'c', header: 'Devise', render: (c) => `${c.currency} · ${c.dial_code}` },
          { key: 'a', header: t('adm.cty.authority'), render: (c) => c.authority },
          { key: 'p', header: t('adm.nav.pharmacies'), render: (c) => c.pharmacies },
          {
            key: 's',
            header: t('adm.cty.status'),
            render: (c) => <Badge tone={tone(c.status)}>{t(`adm.cty.${c.status}`)}</Badge>,
          },
        ]}
      />
      {edit && (
        <Modal title={edit.code} onClose={() => setEdit(null)}>
          <div className="stack">
            <label className="sa-field">
              <span className="sa-field__label">{t('adm.cty.status')}</span>
              <select
                className="sa-field__input"
                value={edit.status}
                onChange={(e) => setEdit({ ...edit, status: e.target.value })}
              >
                {['active', 'preparing', 'closed'].map((s) => (
                  <option key={s} value={s}>
                    {t(`adm.cty.${s}`)}
                  </option>
                ))}
              </select>
            </label>
            <Field
              label={t('adm.cty.authority')}
              value={edit.authority}
              onChange={(e) => setEdit({ ...edit, authority: e.target.value })}
            />
            <label className="sa-field">
              <span className="sa-field__label">{t('adm.cty.docs')}</span>
              <textarea
                className="sa-field__input"
                rows={5}
                value={edit.docs}
                onChange={(e) => setEdit({ ...edit, docs: e.target.value })}
              />
            </label>
            <Field
              label={t('adm.cty.retention')}
              type="number"
              min={0}
              value={edit.retention}
              onChange={(e) => setEdit({ ...edit, retention: e.target.value })}
            />
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setEdit(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={save}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}
    </div>
  );
}

/* ---------- B10 : paramètres globaux ---------- */
const NUMERIC = ['link_validity_hours', 'request_quota_per_day', 'reservation_release_hours'];
const FLAGS = ['admin_mfa', 'maintenance'];
export function AdminSettingsPage() {
  const { t } = useT();
  const toast = useToast();
  const err = useAdminError();
  const [s, setS] = useState<Record<string, string> | null>(null);
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    api<{ settings: Record<string, string> }>('GET', '/admin/settings').then((r) =>
      setS(r.settings),
    );
  }, []);
  if (!s) return <div />;
  async function save() {
    try {
      await api('PUT', '/admin/settings', { settings: s });
      toast(t('adm.set.saved'));
      setError(null);
    } catch (e) {
      setError(err(e));
    }
  }
  return (
    <div>
      <h1>{t('adm.set.title')}</h1>
      <section className="panel stack" style={{ maxWidth: 560 }}>
        {NUMERIC.map((k) => (
          <Field
            key={k}
            label={t(`adm.set.${k}`)}
            type="number"
            min={1}
            value={s[k] ?? ''}
            onChange={(e) => setS({ ...s, [k]: e.target.value })}
          />
        ))}
        {FLAGS.map((k) => (
          <label key={k} className="check">
            <input
              type="checkbox"
              checked={s[k] === '1'}
              onChange={(e) => setS({ ...s, [k]: e.target.checked ? '1' : '0' })}
            />{' '}
            {t(`adm.set.${k}`)}
          </label>
        ))}
        <Field
          label={t('adm.set.desktop_version')}
          value={s.desktop_version ?? ''}
          onChange={(e) => setS({ ...s, desktop_version: e.target.value })}
        />
        {error && (
          <div role="alert" className="form-error">
            {error}
          </div>
        )}
        <Button onClick={save}>{t('common.save')}</Button>
      </section>
    </div>
  );
}
