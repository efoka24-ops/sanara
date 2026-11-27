import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { saleLine, settle, type PayMethod } from '@sanara/shared';
import { api, ApiError } from '../api/client';
import { useSession } from '../app/session';
import { useDesktop } from '../app/desktop';
import { DocModal, ProductSearch, fmt, money, useErrorText, type ProductLite } from '../lib/common';

interface CashSession {
  id: string;
  station: string;
  opening_float: number;
  stale: boolean;
}
interface CartLine {
  product: ProductLite;
  qty: number;
  discountBp: number;
  stock: number | null;
}
interface Held {
  id: string;
  label: string;
  cart: { lines: { product: ProductLite; qty: number; discountBp: number }[]; customer?: string };
}
interface Approver {
  id: string;
  email: string;
  role: string;
}
interface PayRow {
  method: PayMethod;
  amount: string;
  reference: string;
}
interface Sale {
  id: string;
  number: string;
  total: number;
  change: number;
}
const METHODS: PayMethod[] = ['cash', 'momo_mtn', 'momo_orange', 'card', 'credit'];
const DENOMS = [10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5];

export function PosPage() {
  const { t } = useT();
  const toast = useToast();
  const errText = useErrorText();
  const nav = useNavigate();
  const { can } = useSession();
  const search = useRef<HTMLInputElement>(null);
  const { desktop, status: post } = useDesktop();
  const offline = post !== null && !post.online;
  const [session, setSession] = useState<CashSession | null | undefined>(undefined);
  const [openForm, setOpenForm] = useState({ station: '', float: '0' });
  // Le poste enregistré propose son nom ; la saisie l'emporte.
  const station = openForm.station || post?.station || (desktop ? '' : 'CAISSE1');
  const [cart, setCart] = useState<CartLine[]>([]);
  const [sel, setSel] = useState(0);
  const [customer, setCustomer] = useState('');
  const [held, setHeld] = useState<Held[]>([]);
  const [paying, setPaying] = useState(false);
  const [rows, setRows] = useState<PayRow[]>([]);
  const [rx, setRx] = useState({ ref: '', prescriber: '', patient: '', pharmacist: '', pin: '' });
  const [appr, setAppr] = useState({ needed: false, user: '', pin: '' });
  const [pharmacists, setPharmacists] = useState<Approver[]>([]);
  const [managers, setManagers] = useState<Approver[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [done, setDone] = useState<Sale | null>(null);
  const [alts, setAlts] = useState<
    { product_id: string; name: string; sale_price: number; available: number }[] | null
  >(null);
  const [closing, setClosing] = useState<{
    expected: number;
    counts: Record<number, string>;
    reason: string;
  } | null>(null);
  const [doc, setDoc] = useState<{ title: string; path: string } | null>(null);
  const [discountFor, setDiscountFor] = useState<number | null>(null);
  const [discountPct, setDiscountPct] = useState('');
  const [askCustomer, setAskCustomer] = useState(false);

  const loadSession = useCallback(async () => {
    const r = await api<{ session: CashSession | null }>('GET', '/app/cash/current');
    setSession(r.session);
  }, []);
  const loadHeld = useCallback(() => {
    api<{ items: Held[] }>('GET', '/app/pos/held').then((r) => setHeld(r.items));
  }, []);
  useEffect(() => {
    loadSession();
    loadHeld();
  }, [loadSession, loadHeld]);

  const lines = useMemo(
    () =>
      cart.map((l) => ({
        l,
        m: saleLine(l.qty, l.product.sale_price, l.discountBp, l.product.vat_bp),
      })),
    [cart],
  );
  const total = lines.reduce((s, x) => s + x.m.total, 0);
  const regulated = cart.some((l) => l.product.regulatory_status !== 'free');
  const needsPharmacist = regulated && !can('narcotics.dispense');

  async function add(p: ProductLite) {
    setDone(null);
    const idx = cart.findIndex((l) => l.product.id === p.id);
    if (idx >= 0) {
      setCart(cart.map((l, i) => (i === idx ? { ...l, qty: l.qty + 1 } : l)));
      setSel(idx);
    } else {
      setCart([...cart, { product: p, qty: 1, discountBp: 0, stock: null }]);
      setSel(cart.length);
      api<{ on_hand: number }>('GET', `/app/stock/products/${p.id}/on-hand?picker=1`)
        .then((r) =>
          setCart((c) => c.map((l) => (l.product.id === p.id ? { ...l, stock: r.on_hand } : l))),
        )
        .catch(() => undefined);
    }
  }
  const setQty = (i: number, qty: number) =>
    setCart(cart.map((l, k) => (k === i ? { ...l, qty: Math.max(1, qty) } : l)));
  const remove = (i: number) => {
    setCart(cart.filter((_, k) => k !== i));
    setSel(0);
  };

  async function hold() {
    if (cart.length === 0) return;
    await api('POST', '/app/pos/held', {
      label: customer || cart[0]!.product.name,
      cart: {
        lines: cart.map((l) => ({ product: l.product, qty: l.qty, discountBp: l.discountBp })),
        customer,
      },
    });
    setCart([]);
    setCustomer('');
    loadHeld();
    search.current?.focus();
  }
  async function resume(h: Held) {
    setCart(h.cart.lines.map((l) => ({ ...l, stock: null })));
    setCustomer(h.cart.customer ?? '');
    await api('DELETE', `/app/pos/held/${h.id}`);
    loadHeld();
  }

  async function startPay() {
    if (cart.length === 0) return;
    setError(null);
    setAppr({ needed: false, user: '', pin: '' });
    setRows([{ method: 'cash', amount: String(total), reference: '' }]);
    setPaying(true);
    if (needsPharmacist) {
      api<{ items: Approver[] }>('GET', '/app/approvers?permission=narcotics.dispense').then((r) =>
        setPharmacists(r.items),
      );
    }
  }
  async function loadManagers() {
    const r = await api<{ items: Approver[] }>(
      'GET',
      '/app/approvers?permission=sales.discount_override',
    );
    setManagers(r.items);
  }

  const payments = rows.map((r) => ({ method: r.method, amount: Number(r.amount || 0) }));
  const result = settle(total, payments);
  const paid = payments.reduce((s, p) => s + p.amount, 0);
  const remaining = Math.max(0, total - paid);

  async function confirm() {
    setError(null);
    const body: Record<string, unknown> = {
      lines: cart.map((l) => ({ product_id: l.product.id, qty: l.qty, discount_bp: l.discountBp })),
      payments: rows.map((r) => ({
        method: r.method,
        amount: Number(r.amount),
        reference: r.reference || undefined,
      })),
      customer_name: customer || undefined,
    };
    if (regulated) {
      body.prescription = { ref: rx.ref, prescriber: rx.prescriber, patient_name: rx.patient };
      if (needsPharmacist) body.pharmacist = { user_id: rx.pharmacist, pin: rx.pin };
    }
    if (appr.needed) body.approver = { user_id: appr.user, pin: appr.pin };
    try {
      const sale = await api<Sale>('POST', '/app/sales', body);
      setDone(sale);
      setPaying(false);
      setCart([]);
      setCustomer('');
      setRx({ ref: '', prescriber: '', patient: '', pharmacist: '', pin: '' });
      setDoc({ title: sale.number, path: `/app/sales/${sale.id}/receipt` });
    } catch (e) {
      if (e instanceof ApiError && e.code === 'discount_needs_approval') {
        setAppr({ needed: true, user: '', pin: '' });
        loadManagers();
      }
      if (e instanceof ApiError && e.code === 'insufficient_stock') {
        const pid = String(e.details.product_id ?? '');
        if (pid) {
          const r = await api<{ items: NonNullable<typeof alts> }>(
            'GET',
            `/app/sales/alternatives/${pid}`,
          );
          setAlts(r.items);
        }
      }
      if (e instanceof ApiError && e.code === 'cash_session_stale') loadSession();
      setError(errText(e));
    }
  }

  async function startClose() {
    if (!session) return;
    const r = await api<{ totals: { expected_cash: number } }>(
      'GET',
      `/app/cash/sessions/${session.id}/preview`,
    );
    setClosing({ expected: r.totals.expected_cash, counts: {}, reason: '' });
    setError(null);
  }
  const counted = closing ? DENOMS.reduce((s, d) => s + d * Number(closing.counts[d] || 0), 0) : 0;
  async function doClose() {
    if (!closing || !session) return;
    try {
      await api('POST', `/app/cash/sessions/${session.id}/close`, {
        counts: DENOMS.filter((d) => Number(closing.counts[d] || 0) > 0).map((d) => ({
          denomination: d,
          qty: Number(closing.counts[d]),
        })),
        reason: closing.reason || undefined,
      });
      setDoc({ title: t('cash.zReport'), path: `/app/cash/sessions/${session.id}/z` });
      setClosing(null);
      setSession(null);
    } catch (e) {
      setError(errText(e));
    }
  }

  async function openCash() {
    try {
      await api('POST', '/app/cash/open', {
        station,
        opening_float: Number(openForm.float || 0),
      });
      await loadSession();
    } catch (e) {
      toast(errText(e), 'error');
    }
  }

  useEffect(() => {
    const h = (e: KeyboardEvent) => {
      if (!session || session.stale) return;
      const map: Record<string, () => void> = {
        F1: () => search.current?.focus(),
        F2: () => void hold(),
        F4: () => cart.length > 0 && (setDiscountFor(sel), setDiscountPct('')),
        F6: () => nav('/app/ventes'),
        F8: () => setAskCustomer(true),
        F9: () => void startPay(),
        F10: () => void startClose(),
      };
      const fn = map[e.key];
      if (fn) {
        e.preventDefault();
        fn();
      }
    };
    window.addEventListener('keydown', h);
    return () => window.removeEventListener('keydown', h);
  });

  if (session === undefined) return <div />;

  if (session === null || session.stale) {
    return (
      <div>
        <h1>{t('pos.title')}</h1>
        {session?.stale ? (
          <div className="panel">
            <p role="alert" className="form-error">
              {t('pos.stale')}
            </p>
            <Button onClick={startClose}>{t('pos.close')}</Button>
          </div>
        ) : (
          <div className="panel stack" style={{ maxWidth: 420 }}>
            <p className="muted">{t('pos.mustOpen')}</p>
            <Field
              label={t('pos.station')}
              value={station}
              onChange={(e) => setOpenForm({ ...openForm, station: e.target.value })}
            />
            <Field
              label={t('pos.float')}
              type="number"
              min={0}
              value={openForm.float}
              onChange={(e) => setOpenForm({ ...openForm, float: e.target.value })}
            />
            <Button onClick={openCash}>{t('pos.open')}</Button>
          </div>
        )}
        {closing && renderClose()}
        {doc && <DocModal title={doc.title} path={doc.path} onClose={() => setDoc(null)} />}
      </div>
    );
  }

  function renderClose() {
    if (!closing) return null;
    const gap = counted - closing.expected;
    return (
      <Modal title={t('cash.closeTitle')} onClose={() => setClosing(null)}>
        <div className="stack">
          <div className="grid2">
            {DENOMS.map((d) => (
              <Field
                key={d}
                label={`${t('cash.denomination')} ${fmt(d)}`}
                type="number"
                min={0}
                value={closing.counts[d] ?? ''}
                onChange={(e) =>
                  setClosing({ ...closing, counts: { ...closing.counts, [d]: e.target.value } })
                }
              />
            ))}
          </div>
          <p>
            {t('cash.expected')} : <b>{money(closing.expected)}</b> · {t('cash.counted')} :{' '}
            <b>{money(counted)}</b> · {t('cash.gap')} :{' '}
            <Badge tone={gap === 0 ? 'ok' : 'danger'}>{money(gap)}</Badge>
          </p>
          {gap !== 0 && (
            <Field
              label={t('cash.gapReason')}
              value={closing.reason}
              onChange={(e) => setClosing({ ...closing, reason: e.target.value })}
            />
          )}
          {error && (
            <div role="alert" className="form-error">
              {error}
            </div>
          )}
        </div>
        <div className="actions">
          <Button variant="secondary" onClick={() => setClosing(null)}>
            {t('common.cancel')}
          </Button>
          <Button onClick={doClose}>{t('cash.confirmClose')}</Button>
        </div>
      </Modal>
    );
  }

  return (
    <div className="pos">
      <div className="pos__main">
        <div className="row">
          <h1>{t('pos.title')}</h1>
          <span className="muted">{session.station}</span>
        </div>
        <ProductSearch label={t('pos.search')} onPick={add} autoFocus inputRef={search} />
        <Table
          rowKey={(x) => x.l.product.id}
          rows={lines}
          columns={[
            {
              key: 'n',
              header: t('st.col.product'),
              render: (x) => (
                <button
                  className={`link-strong${lines[sel]?.l === x.l ? ' is-sel' : ''}`}
                  onClick={() => setSel(lines.indexOf(x))}
                >
                  {x.l.product.name}
                </button>
              ),
            },
            {
              key: 'f',
              header: '',
              render: (x) => (
                <span className="row">
                  {x.l.product.regulatory_status !== 'free' && (
                    <Badge tone="danger">{t('pos.regulated')}</Badge>
                  )}
                  {x.l.stock !== null &&
                    (x.l.stock <= 0 ? (
                      <Badge tone="danger">{t('pos.noStock')}</Badge>
                    ) : (
                      <span className="muted">{t('pos.stock', { n: x.l.stock })}</span>
                    ))}
                </span>
              ),
            },
            {
              key: 'q',
              header: t('pos.qty'),
              render: (x) => (
                <input
                  className="qty"
                  type="number"
                  min={1}
                  aria-label={`${t('pos.qty')} ${x.l.product.name}`}
                  value={x.l.qty}
                  onChange={(e) => setQty(lines.indexOf(x), Number(e.target.value))}
                />
              ),
            },
            { key: 'p', header: t('pos.price'), render: (x) => money(x.l.product.sale_price) },
            {
              key: 'd',
              header: '%',
              render: (x) => (x.l.discountBp > 0 ? `−${x.l.discountBp / 100} %` : ''),
            },
            { key: 't', header: t('pos.total'), render: (x) => money(x.m.total) },
            {
              key: 'x',
              header: '',
              render: (x) => (
                <button className="link" onClick={() => remove(lines.indexOf(x))}>
                  {t('pos.remove')}
                </button>
              ),
            },
          ]}
        />
        {cart.length === 0 && <p className="muted">{t('pos.empty')}</p>}
      </div>

      <aside className="pos__side panel">
        <div className="pos__total">
          <span className="muted">{t('pos.total')}</span>
          <b>{money(total)}</b>
        </div>
        {customer && <p className="muted">{customer}</p>}
        <div className="stack">
          <Button onClick={startPay} disabled={cart.length === 0}>
            {t('pos.pay')}
          </Button>
          <Button variant="secondary" onClick={hold} disabled={cart.length === 0}>
            {t('pos.hold')}
          </Button>
          <Button
            variant="secondary"
            onClick={() => {
              setDiscountFor(sel);
              setDiscountPct('');
            }}
            disabled={cart.length === 0 || !can('sales.discount')}
          >
            {t('pos.discount')}
          </Button>
          <Button variant="secondary" onClick={() => setAskCustomer(true)}>
            {t('pos.customer')}
          </Button>
          <Button variant="secondary" onClick={() => nav('/app/ventes')}>
            {t('pos.returns')}
          </Button>
          <Button variant="warning" onClick={startClose}>
            {t('pos.close')}
          </Button>
        </div>
        {held.length > 0 && (
          <>
            <h3>{t('pos.held')}</h3>
            <ul className="sessions">
              {held.map((h) => (
                <li key={h.id}>
                  <span>{h.label}</span>
                  <button className="link-strong" onClick={() => resume(h)}>
                    {t('pos.resume')}
                  </button>
                </li>
              ))}
            </ul>
          </>
        )}
        {done && (
          <p role="status" className="notice">
            {t('pay.done')} {done.number}
            {done.change > 0 ? ` · ${t('pay.change')} ${money(done.change)}` : ''}
          </p>
        )}
      </aside>

      {paying && (
        <Modal title={t('pay.title')} onClose={() => setPaying(false)}>
          <div className="stack">
            <p className="pos__total">
              <span>{t('pos.total')}</span> <b>{money(total)}</b>
            </p>
            {regulated && (
              <fieldset className="panel stack">
                <legend>{t('rx.title')}</legend>
                <Field
                  label={t('rx.ref')}
                  value={rx.ref}
                  onChange={(e) => setRx({ ...rx, ref: e.target.value })}
                />
                <div className="grid2">
                  <Field
                    label={t('rx.prescriber')}
                    value={rx.prescriber}
                    onChange={(e) => setRx({ ...rx, prescriber: e.target.value })}
                  />
                  <Field
                    label={t('rx.patient')}
                    value={rx.patient}
                    onChange={(e) => setRx({ ...rx, patient: e.target.value })}
                  />
                </div>
                {needsPharmacist && (
                  <div className="grid2">
                    <label className="sa-field">
                      <span className="sa-field__label">{t('rx.pharmacist')}</span>
                      <select
                        className="sa-field__input"
                        value={rx.pharmacist}
                        onChange={(e) => setRx({ ...rx, pharmacist: e.target.value })}
                      >
                        <option value="" />
                        {pharmacists.map((p) => (
                          <option key={p.id} value={p.id}>
                            {p.email}
                          </option>
                        ))}
                      </select>
                    </label>
                    <Field
                      label={t('appr.pin')}
                      type="password"
                      inputMode="numeric"
                      maxLength={4}
                      value={rx.pin}
                      onChange={(e) => setRx({ ...rx, pin: e.target.value })}
                    />
                  </div>
                )}
              </fieldset>
            )}
            {rows.map((r, i) => (
              <div key={i} className="grid2">
                <label className="sa-field">
                  <span className="sa-field__label">{t('pay.title')}</span>
                  <select
                    className="sa-field__input"
                    value={r.method}
                    onChange={(e) =>
                      setRows(
                        rows.map((x, k) =>
                          k === i ? { ...x, method: e.target.value as PayMethod } : x,
                        ),
                      )
                    }
                  >
                    {METHODS.map((m) => (
                      <option key={m} value={m} disabled={offline && m.startsWith('momo')}>
                        {t(`pay.method.${m}`)}
                        {offline && m.startsWith('momo') ? ` (${t('desk.onlineOnly')})` : ''}
                      </option>
                    ))}
                  </select>
                </label>
                <Field
                  label={t('pay.amount')}
                  type="number"
                  min={0}
                  value={r.amount}
                  onChange={(e) =>
                    setRows(rows.map((x, k) => (k === i ? { ...x, amount: e.target.value } : x)))
                  }
                />
                {(r.method === 'momo_mtn' || r.method === 'momo_orange' || r.method === 'card') && (
                  <Field
                    label={t('pay.reference')}
                    value={r.reference}
                    onChange={(e) =>
                      setRows(
                        rows.map((x, k) => (k === i ? { ...x, reference: e.target.value } : x)),
                      )
                    }
                  />
                )}
                {r.method === 'credit' && !customer && (
                  <p className="muted">{t('pay.customerName')} : F8</p>
                )}
              </div>
            ))}
            <div className="row">
              <button
                className="link-strong"
                onClick={() =>
                  setRows([
                    ...rows,
                    {
                      method: offline ? 'card' : 'momo_mtn',
                      amount: String(remaining),
                      reference: '',
                    },
                  ])
                }
              >
                {t('pay.addMethod')}
              </button>
              <span>
                {remaining > 0 ? (
                  <>
                    {t('pay.remaining')} : <b>{money(remaining)}</b>
                  </>
                ) : result ? (
                  <>
                    {t('pay.change')} : <b>{money(result.change)}</b>
                  </>
                ) : null}
              </span>
            </div>
            {offline && <p className="notice">{t('desk.momoOffline')}</p>}
            {appr.needed && (
              <fieldset className="panel stack">
                <legend>{t('appr.who')}</legend>
                <p className="muted">{t('appr.discount')}</p>
                <div className="grid2">
                  <label className="sa-field">
                    <span className="sa-field__label">{t('appr.who')}</span>
                    <select
                      className="sa-field__input"
                      value={appr.user}
                      onChange={(e) => setAppr({ ...appr, user: e.target.value })}
                    >
                      <option value="" />
                      {managers.map((p) => (
                        <option key={p.id} value={p.id}>
                          {p.email}
                        </option>
                      ))}
                    </select>
                  </label>
                  <Field
                    label={t('appr.pin')}
                    type="password"
                    inputMode="numeric"
                    maxLength={4}
                    value={appr.pin}
                    onChange={(e) => setAppr({ ...appr, pin: e.target.value })}
                  />
                </div>
              </fieldset>
            )}
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setPaying(false)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={confirm} disabled={!result}>
              {t('pay.confirm')}
            </Button>
          </div>
        </Modal>
      )}

      {alts && (
        <Modal title={t('pos.alternatives')} onClose={() => setAlts(null)}>
          {alts.length === 0 ? (
            <p className="muted">{t('pos.noStock')}</p>
          ) : (
            <ul className="sessions">
              {alts.map((a) => (
                <li key={a.product_id}>
                  <span>
                    {a.name} · {money(a.sale_price)} · {t('pos.stock', { n: a.available })}
                  </span>
                  <button
                    className="link-strong"
                    onClick={async () => {
                      const r = await api<ProductLite>('GET', `/app/products/${a.product_id}`);
                      await add(r);
                      setAlts(null);
                      setPaying(false);
                    }}
                  >
                    {t('pos.addAlt')}
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Modal>
      )}

      {discountFor !== null && cart[discountFor] && (
        <Modal title={t('pos.discount')} onClose={() => setDiscountFor(null)}>
          <Field
            label={`% · ${cart[discountFor]!.product.name}`}
            type="number"
            min={0}
            max={100}
            value={discountPct}
            autoFocus
            onChange={(e) => setDiscountPct(e.target.value)}
          />
          <div className="actions">
            <Button variant="secondary" onClick={() => setDiscountFor(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              onClick={() => {
                setCart(
                  cart.map((l, i) =>
                    i === discountFor
                      ? { ...l, discountBp: Math.round(Number(discountPct || 0) * 100) }
                      : l,
                  ),
                );
                setDiscountFor(null);
              }}
            >
              {t('common.save')}
            </Button>
          </div>
        </Modal>
      )}

      {askCustomer && (
        <Modal title={t('pos.customer')} onClose={() => setAskCustomer(false)}>
          <Field
            label={t('pay.customerName')}
            value={customer}
            autoFocus
            onChange={(e) => setCustomer(e.target.value)}
            onKeyDown={(e) => e.key === 'Enter' && setAskCustomer(false)}
          />
          <div className="actions">
            <Button onClick={() => setAskCustomer(false)}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}

      {renderClose()}
      {doc && (
        <DocModal
          title={doc.title}
          path={doc.path}
          onClose={() => {
            setDoc(null);
            search.current?.focus();
          }}
        />
      )}
    </div>
  );
}
