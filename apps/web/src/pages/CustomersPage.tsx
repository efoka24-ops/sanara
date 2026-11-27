import { useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { useSession } from '../app/session';
import { DocModal, money, useErrorText } from '../lib/common';

interface Account {
  id: string;
  name: string;
  phone: string;
  credit_limit: number;
  balance: number;
  active: boolean;
}
interface Statement extends Account {
  email: string;
  reminder_consent: boolean;
  entries: {
    id: string;
    kind: string;
    amount: number;
    receipt_number: string | null;
    created_at: string;
  }[];
}
interface Form {
  id: string | null;
  name: string;
  phone: string;
  email: string;
  limit: string;
  consent: boolean;
  active: boolean;
}
const EMPTY: Form = {
  id: null,
  name: '',
  phone: '',
  email: '',
  limit: '0',
  consent: false,
  active: true,
};
const METHODS = ['cash', 'momo_mtn', 'momo_orange', 'card', 'bank'];

/** US13 : comptes clients, plafonds, relevés et encaissement des dettes. */
export function CustomersPage() {
  const { t, locale } = useT();
  const { can } = useSession();
  const toast = useToast();
  const errText = useErrorText();
  const [q, setQ] = useState('');
  const [items, setItems] = useState<Account[] | null>(null);
  const [form, setForm] = useState<Form | null>(null);
  const [fields, setFields] = useState<Record<string, string>>({});
  const [open, setOpen] = useState<Statement | null>(null);
  const [payment, setPayment] = useState({ amount: '', method: 'cash', reference: '' });
  const [error, setError] = useState<string | null>(null);
  const [receipt, setReceipt] = useState<{ title: string; path: string } | null>(null);

  async function load(term = q) {
    try {
      const query = new URLSearchParams({ q: term });
      setItems((await api<{ items: Account[] }>('GET', `/app/customers?${query}`)).items);
      setError(null);
    } catch (e) {
      setError(errText(e));
    }
  }
  useEffect(() => {
    const wait = window.setTimeout(() => void load(q), 200);
    return () => window.clearTimeout(wait);
  }, [q]);

  async function show(id: string) {
    try {
      setOpen(await api<Statement>('GET', `/app/customers/${id}`));
      setPayment({ amount: '', method: 'cash', reference: '' });
      setError(null);
    } catch (e) {
      setError(errText(e));
    }
  }

  async function save() {
    if (!form) return;
    const body = {
      name: form.name,
      phone: form.phone,
      email: form.email,
      credit_limit: Number(form.limit || 0),
      reminder_consent: form.consent,
      active: form.active,
    };
    try {
      await api('POST', form.id ? `/app/customers/${form.id}` : '/app/customers', body);
      toast(t('common.saved'));
      setForm(null);
      if (form.id && open) await show(form.id);
      await load();
    } catch (e) {
      const invalid = e instanceof ApiError ? (e.details.fields as Record<string, string>) : null;
      setFields(invalid ?? {});
      if (!invalid) toast(errText(e), 'error');
    }
  }

  async function pay() {
    if (!open) return;
    try {
      const done = await api<{ id: string; receipt_number: string }>(
        'POST',
        `/app/customers/${open.id}/payments`,
        {
          amount: Number(payment.amount),
          method: payment.method,
          reference: payment.reference,
        },
      );
      setReceipt({
        title: done.receipt_number,
        path: `/app/customers/${open.id}/receipts/${done.id}`,
      });
      await show(open.id);
      await load();
    } catch (e) {
      setError(
        e instanceof ApiError && e.code === 'validation_failed'
          ? t('cust.paymentInvalid')
          : errText(e),
      );
    }
  }

  const date = (stamp: string) =>
    new Date(`${stamp.replace(' ', 'T')}Z`).toLocaleDateString(locale === 'fr' ? 'fr-FR' : 'en-GB');
  const invalid = (key: string) => (fields[key] ? t('common.required') : undefined);

  return (
    <div className="stack customers">
      <div className="row">
        <h1>{t('cust.title')}</h1>
        {can('customers.write') && (
          <Button
            onClick={() => {
              setFields({});
              setForm(EMPTY);
            }}
          >
            {t('cust.add')}
          </Button>
        )}
      </div>
      <Field
        label={t('cust.search')}
        type="search"
        value={q}
        onChange={(e) => setQ(e.target.value)}
      />
      {error && !open && (
        <div role="alert" className="form-error">
          {error}
        </div>
      )}
      <Table
        rowKey={(c: Account) => c.id}
        rows={items ?? []}
        columns={[
          { key: 'n', header: t('cust.name'), render: (c) => <b>{c.name}</b> },
          { key: 'p', header: t('cust.phone'), render: (c) => c.phone },
          { key: 'b', header: t('cust.balance'), render: (c) => money(c.balance) },
          { key: 'l', header: t('cust.limit'), render: (c) => money(c.credit_limit) },
          {
            key: 's',
            header: '',
            render: (c) => (c.active ? '' : <Badge>{t('cust.inactive')}</Badge>),
          },
          {
            key: 'a',
            header: '',
            render: (c) => (
              <button className="link-strong" onClick={() => void show(c.id)}>
                {t('cust.statement')}
              </button>
            ),
          },
        ]}
      />
      {items?.length === 0 && <p className="muted">{t('cust.none')}</p>}

      {open && !form && !receipt && (
        <Modal title={`${t('cust.statement')} · ${open.name}`} onClose={() => setOpen(null)}>
          <div className="stack">
            <p className="pos__total">
              <span>{t('cust.balance')}</span> <b>{money(open.balance)}</b>
            </p>
            <Table
              rowKey={(e: Statement['entries'][number]) => e.id}
              rows={open.entries}
              columns={[
                { key: 'd', header: t('cust.date'), render: (e) => date(e.created_at) },
                {
                  key: 'k',
                  header: t('cust.operation'),
                  render: (e) => `${t(`cust.kind.${e.kind}`)} ${e.receipt_number ?? ''}`,
                },
                { key: 'm', header: t('pay.amount'), render: (e) => money(e.amount) },
              ]}
            />
            {can('customers.collect') && open.balance > 0 && (
              <fieldset className="panel stack">
                <legend>{t('cust.collect')}</legend>
                <div className="grid2">
                  <Field
                    label={t('pay.amount')}
                    type="number"
                    min={1}
                    max={open.balance}
                    value={payment.amount}
                    onChange={(e) => setPayment({ ...payment, amount: e.target.value })}
                  />
                  <label className="sa-field">
                    <span className="sa-field__label">{t('cust.method')}</span>
                    <select
                      className="sa-field__input"
                      value={payment.method}
                      onChange={(e) => setPayment({ ...payment, method: e.target.value })}
                    >
                      {METHODS.map((m) => (
                        <option key={m} value={m}>
                          {t(`pay.method.${m}`)}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
                {payment.method !== 'cash' && (
                  <Field
                    label={t('pay.reference')}
                    value={payment.reference}
                    onChange={(e) => setPayment({ ...payment, reference: e.target.value })}
                  />
                )}
                <Button onClick={() => void pay()} disabled={!payment.amount}>
                  {t('cust.record')}
                </Button>
              </fieldset>
            )}
            {error && (
              <div role="alert" className="form-error">
                {error}
              </div>
            )}
          </div>
          <div className="actions">
            {can('customers.write') && (
              <Button
                variant="secondary"
                onClick={() => {
                  setFields({});
                  setForm({
                    id: open.id,
                    name: open.name,
                    phone: open.phone,
                    email: open.email,
                    limit: String(open.credit_limit),
                    consent: open.reminder_consent,
                    active: open.active,
                  });
                }}
              >
                {t('cust.edit')}
              </Button>
            )}
            <Button onClick={() => setOpen(null)}>{t('common.close')}</Button>
          </div>
        </Modal>
      )}

      {form && (
        <Modal title={form.id ? t('cust.edit') : t('cust.add')} onClose={() => setForm(null)}>
          <div className="stack">
            <Field
              label={t('cust.name')}
              value={form.name}
              error={invalid('name')}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />
            <div className="grid2">
              <Field
                label={t('cust.phone')}
                type="tel"
                value={form.phone}
                error={invalid('phone')}
                onChange={(e) => setForm({ ...form, phone: e.target.value })}
              />
              <Field
                label={t('auth.email')}
                type="email"
                value={form.email}
                error={invalid('email')}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
            </div>
            <Field
              label={t('cust.limit')}
              type="number"
              min={0}
              value={form.limit}
              error={invalid('credit_limit')}
              onChange={(e) => setForm({ ...form, limit: e.target.value })}
            />
            <label className="check">
              <input
                type="checkbox"
                checked={form.consent}
                onChange={(e) => setForm({ ...form, consent: e.target.checked })}
              />{' '}
              {t('cust.consent')}
            </label>
            {form.id && (
              <label className="check">
                <input
                  type="checkbox"
                  checked={form.active}
                  onChange={(e) => setForm({ ...form, active: e.target.checked })}
                />{' '}
                {t('cust.active')}
              </label>
            )}
          </div>
          <div className="actions">
            <Button variant="secondary" onClick={() => setForm(null)}>
              {t('common.cancel')}
            </Button>
            <Button onClick={() => void save()}>{t('common.save')}</Button>
          </div>
        </Modal>
      )}

      {receipt && (
        <DocModal title={receipt.title} path={receipt.path} onClose={() => setReceipt(null)} />
      )}
    </div>
  );
}
