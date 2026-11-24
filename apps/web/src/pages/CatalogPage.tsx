import { useCallback, useEffect, useState } from 'react';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { api, ApiError } from '../api/client';
import { useSession } from '../app/session';

interface Product {
  id: string;
  name: string;
  dci: string | null;
  form: string | null;
  strength: string | null;
  pack: string | null;
  lab: string | null;
  family_id: string | null;
  regulatory_status: string;
  vat_bp: number;
  purchase_price: number;
  sale_price: number;
  min_stock: number;
  active: number;
}
interface Detail extends Product {
  barcodes: string[];
  price_history: {
    kind: string;
    old_price: number | null;
    new_price: number;
    reason: string | null;
    at: string;
  }[];
  equivalents: Product[];
}
interface Family {
  id: string;
  name: string;
}
const STATUSES = ['free', 'prescription_only', 'list_i', 'list_ii', 'narcotic'];
const TONE: Record<string, 'ok' | 'wait' | 'danger'> = {
  free: 'ok',
  prescription_only: 'wait',
  list_i: 'danger',
  list_ii: 'danger',
  narcotic: 'danger',
};
const blank = {
  name: '',
  dci: '',
  form: '',
  strength: '',
  pack: '',
  lab: '',
  barcodes: '',
  regulatory_status: 'free',
  purchase_price: '0',
  sale_price: '0',
  vat_bp: '0',
  min_stock: '0',
  family_id: '',
  price_reason: '',
};
const fmt = (n: number) => new Intl.NumberFormat('fr-FR').format(n);

export function CatalogPage() {
  const { t } = useT();
  const toast = useToast();
  const { can } = useSession();
  const canWrite = can('products.write');
  const [q, setQ] = useState('');
  const [all, setAll] = useState(false);
  const [items, setItems] = useState<Product[]>([]);
  const [families, setFamilies] = useState<Family[]>([]);
  const [detail, setDetail] = useState<Detail | null>(null);
  const [form, setForm] = useState<(typeof blank & { id?: string }) | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [newFamily, setNewFamily] = useState('');

  const load = useCallback(() => {
    const p = new URLSearchParams({ q, active: all ? 'all' : '1', limit: '100' });
    api<{ items: Product[] }>('GET', `/app/products?${p}`).then((r) => setItems(r.items));
  }, [q, all]);
  useEffect(() => {
    const h = window.setTimeout(load, 200);
    return () => window.clearTimeout(h);
  }, [load]);
  useEffect(() => {
    api<{ items: Family[] }>('GET', '/app/families').then((r) => setFamilies(r.items));
  }, []);
  const famName = (id: string | null) => families.find((f) => f.id === id)?.name ?? '';

  async function open(p: Product) {
    setDetail(await api<Detail>('GET', `/app/products/${p.id}`));
  }
  function edit(d: Detail) {
    setForm({
      id: d.id,
      name: d.name,
      dci: d.dci ?? '',
      form: d.form ?? '',
      strength: d.strength ?? '',
      pack: d.pack ?? '',
      lab: d.lab ?? '',
      barcodes: d.barcodes.join(', '),
      regulatory_status: d.regulatory_status,
      purchase_price: String(d.purchase_price),
      sale_price: String(d.sale_price),
      vat_bp: String(d.vat_bp),
      min_stock: String(d.min_stock),
      family_id: d.family_id ?? '',
      price_reason: '',
    });
    setErrors({});
  }
  async function save() {
    if (!form) return;
    const num = (v: string) => (v === '' ? 0 : Number(v));
    const body = {
      ...form,
      purchase_price: num(form.purchase_price),
      sale_price: num(form.sale_price),
      vat_bp: num(form.vat_bp),
      min_stock: num(form.min_stock),
      barcodes: form.barcodes
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean),
      family_id: form.family_id || null,
      dci: form.dci || null,
      form: form.form || null,
      strength: form.strength || null,
      pack: form.pack || null,
      lab: form.lab || null,
    };
    delete (body as { id?: string }).id;
    try {
      if (form.id) await api('PUT', `/app/products/${form.id}`, body);
      else await api('POST', '/app/products', body);
      setForm(null);
      setDetail(null);
      load();
    } catch (e) {
      if (e instanceof ApiError)
        setErrors(
          e.code === 'conflict'
            ? { barcodes: 'dup' }
            : ((e.details.fields ?? {}) as Record<string, string>),
        );
    }
  }
  async function toggle(d: Detail) {
    await api('POST', `/app/products/${d.id}/active`, { active: !d.active });
    setDetail(null);
    load();
  }
  async function addFamily() {
    if (!newFamily.trim()) return;
    const r = await api<{ id: string }>('POST', '/app/families', { name: newFamily });
    setFamilies([...families, { id: r.id, name: newFamily }]);
    setForm((f) => f && { ...f, family_id: r.id });
    setNewFamily('');
  }
  async function importCsv(file?: File) {
    if (!file) return;
    const r = await api<{
      created: number;
      updated: number;
      rejected: { line: number; reason: string }[];
    }>('POST', '/app/products/import', { csv: await file.text() });
    toast(
      t('cat.importReport', { c: r.created, u: r.updated, r: r.rejected.length }) +
        r.rejected
          .slice(0, 3)
          .map((x) => ' · ' + t('cat.importLine', x))
          .join(''),
    );
    load();
  }
  async function reference() {
    const r = await api<{ added: number }>('POST', '/app/products/reference');
    toast(t('cat.referenceDone', { n: r.added }));
    load();
  }
  async function exportCsv() {
    const base = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api';
    const { accessHeader } = await import('../api/client');
    const res = await fetch(`${base}/app/products/export`, { headers: accessHeader() });
    const url = URL.createObjectURL(await res.blob());
    Object.assign(document.createElement('a'), { href: url, download: 'catalogue.csv' }).click();
    URL.revokeObjectURL(url);
  }
  const set = (k: keyof typeof blank) => (e: { target: { value: string } }) =>
    setForm((f) => f && { ...f, [k]: e.target.value });

  return (
    <div>
      <div className="row">
        <h1>{t('cat.title')}</h1>
        <div className="row">
          {canWrite && (
            <>
              <label className="sa-btn sa-btn--secondary">
                {t('cat.import')}
                <input
                  type="file"
                  accept=".csv,text/csv"
                  hidden
                  onChange={(e) => importCsv(e.target.files?.[0])}
                />
              </label>
              <Button variant="secondary" onClick={reference}>
                {t('cat.reference')}
              </Button>
            </>
          )}
          <Button variant="secondary" onClick={exportCsv}>
            {t('cat.export')}
          </Button>
          {canWrite && (
            <Button
              onClick={() => {
                setForm({ ...blank });
                setErrors({});
              }}
            >
              {t('cat.add')}
            </Button>
          )}
        </div>
      </div>
      <div className="row" style={{ margin: '14px 0' }}>
        <input
          className="search"
          placeholder={t('cat.search')}
          value={q}
          onChange={(e) => setQ(e.target.value)}
          aria-label={t('cat.search')}
        />
        <label className="check">
          <input type="checkbox" checked={all} onChange={(e) => setAll(e.target.checked)} />{' '}
          {t('cat.inactive')}
        </label>
      </div>
      <Table
        rowKey={(p: Product) => p.id}
        rows={items}
        columns={[
          {
            key: 'n',
            header: t('cat.col.name'),
            render: (p) => (
              <button className="link-strong" onClick={() => open(p)}>
                {p.name}
                {p.active ? '' : ' (inactif)'}
              </button>
            ),
          },
          { key: 'f', header: t('cat.col.family'), render: (p) => famName(p.family_id) },
          {
            key: 's',
            header: t('cat.col.class'),
            render: (p) => (
              <Badge tone={TONE[p.regulatory_status] ?? 'neutral'}>
                {t(`status.${p.regulatory_status}`)}
              </Badge>
            ),
          },
          { key: 'p', header: t('cat.col.price'), render: (p) => fmt(p.sale_price) },
        ]}
      />
      {detail && !form && (
        <Modal title={detail.name} onClose={() => setDetail(null)}>
          <dl className="panel-dl">
            <dt>{t('cat.f.dci')}</dt>
            <dd>{detail.dci ?? '—'}</dd>
            <dt>{t('cat.f.form')}</dt>
            <dd>
              {[detail.form, detail.strength, detail.pack].filter(Boolean).join(' · ') || '—'}
            </dd>
            <dt>{t('cat.f.barcodes')}</dt>
            <dd>{detail.barcodes.join(', ') || '—'}</dd>
            <dt>{t('cat.f.purchase')}</dt>
            <dd>{fmt(detail.purchase_price)}</dd>
            <dt>{t('cat.f.sale')}</dt>
            <dd>{fmt(detail.sale_price)}</dd>
          </dl>
          <h3>{t('cat.history')}</h3>
          <ul className="sessions">
            {detail.price_history.map((h, i) => (
              <li key={i}>
                <span>
                  {h.kind} · {h.old_price === null ? '' : fmt(h.old_price) + ' → '}
                  {fmt(h.new_price)}
                </span>
                <span className="muted">{h.reason ?? ''}</span>
              </li>
            ))}
          </ul>
          {detail.equivalents.length > 0 && (
            <>
              <h3>{t('cat.equivalents')}</h3>
              <ul>
                {detail.equivalents.map((e) => (
                  <li key={e.id}>
                    {e.name} · {fmt(e.sale_price)}
                  </li>
                ))}
              </ul>
            </>
          )}
          {canWrite && (
            <div className="actions">
              <Button variant="secondary" onClick={() => toggle(detail)}>
                {detail.active ? t('cat.deactivate') : t('cat.reactivate')}
              </Button>
              <Button onClick={() => edit(detail)}>{t('cat.edit')}</Button>
            </div>
          )}
        </Modal>
      )}
      {form && (
        <Modal title={form.id ? t('cat.edit') : t('cat.add')} onClose={() => setForm(null)}>
          <div className="stack">
            <Field
              label={t('cat.f.name') + ' *'}
              value={form.name}
              onChange={set('name')}
              error={errors.name ? t('common.required') : undefined}
            />
            <div className="grid2">
              <Field label={t('cat.f.dci')} value={form.dci} onChange={set('dci')} />
              <Field label={t('cat.f.form')} value={form.form} onChange={set('form')} />
            </div>
            <div className="grid2">
              <Field label={t('cat.f.strength')} value={form.strength} onChange={set('strength')} />
              <Field label={t('cat.f.pack')} value={form.pack} onChange={set('pack')} />
            </div>
            <Field
              label={t('cat.f.barcodes')}
              value={form.barcodes}
              onChange={set('barcodes')}
              error={errors.barcodes ? t('cat.dup') : undefined}
            />
            <div className="grid2">
              <label className="sa-field">
                <span className="sa-field__label">{t('cat.f.status')} *</span>
                <select
                  className="sa-field__input"
                  value={form.regulatory_status}
                  onChange={set('regulatory_status')}
                >
                  {STATUSES.map((s) => (
                    <option key={s} value={s}>
                      {t(`status.${s}`)}
                    </option>
                  ))}
                </select>
              </label>
              <label className="sa-field">
                <span className="sa-field__label">{t('cat.f.family')}</span>
                <select
                  className="sa-field__input"
                  value={form.family_id}
                  onChange={set('family_id')}
                >
                  <option value="" />
                  {families.map((f) => (
                    <option key={f.id} value={f.id}>
                      {f.name}
                    </option>
                  ))}
                </select>
              </label>
            </div>
            <div className="row">
              <input
                className="search"
                placeholder={t('cat.f.newFamily')}
                value={newFamily}
                onChange={(e) => setNewFamily(e.target.value)}
              />
              <Button size="sm" variant="secondary" onClick={addFamily}>
                +
              </Button>
            </div>
            <div className="grid2">
              <Field
                label={t('cat.f.purchase')}
                inputMode="numeric"
                value={form.purchase_price}
                onChange={set('purchase_price')}
                error={errors.purchase_price ? t('apply.invalid') : undefined}
              />
              <Field
                label={t('cat.f.sale')}
                inputMode="numeric"
                value={form.sale_price}
                onChange={set('sale_price')}
                error={errors.sale_price ? t('apply.invalid') : undefined}
              />
            </div>
            <div className="grid2">
              <Field
                label={t('cat.f.vat')}
                inputMode="numeric"
                value={form.vat_bp}
                onChange={set('vat_bp')}
              />
              <Field
                label={t('cat.f.min')}
                inputMode="numeric"
                value={form.min_stock}
                onChange={set('min_stock')}
              />
            </div>
            {form.id && (
              <Field
                label={t('cat.f.reason')}
                value={form.price_reason}
                onChange={set('price_reason')}
              />
            )}
            <div className="actions">
              <Button variant="secondary" onClick={() => setForm(null)}>
                {t('common.cancel')}
              </Button>
              <Button onClick={save}>{t('common.save')}</Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
