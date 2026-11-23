import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Table } from '@sanara/ui';
import { api, ApiError } from '../api/client';

interface Row {
  id: string;
  number: string;
  status: string;
  pharmacy_name: string;
  city: string;
  region: string;
  owner_last_name: string;
  license_number: string;
  created_at: string;
}
const TABS = ['pending', 'needs_info', 'approved', 'rejected'] as const;
const TONE: Record<string, 'wait' | 'ok' | 'danger'> = {
  pending: 'wait',
  needs_info: 'danger',
  approved: 'ok',
  rejected: 'danger',
};

export function AdminApplicationsPage() {
  const { t } = useT();
  const [tab, setTab] = useState<(typeof TABS)[number]>('pending');
  const [rows, setRows] = useState<Row[]>([]);
  useEffect(() => {
    api<{ items: Row[] }>('GET', `/admin/applications?status=${tab}`).then((r) => setRows(r.items));
  }, [tab]);
  return (
    <div>
      <h1>{t('admin.apps.title')}</h1>
      <div className="tabs">
        {TABS.map((s) => (
          <button
            key={s}
            className={`chip${s === tab ? ' chip--on' : ''}`}
            onClick={() => setTab(s)}
          >
            {t(`admin.apps.${s}`)}
          </button>
        ))}
      </div>
      {rows.length === 0 ? (
        <p className="muted">{t('admin.apps.empty')}</p>
      ) : (
        <Table
          rowKey={(r: Row) => r.id}
          rows={rows}
          columns={[
            { key: 'n', header: 'N°', render: (r) => <b>{r.number}</b> },
            { key: 'p', header: 'Pharmacie', render: (r) => r.pharmacy_name },
            { key: 'l', header: 'Localité', render: (r) => `${r.city} · ${r.region}` },
            { key: 'o', header: 'Responsable', render: (r) => r.owner_last_name },
            {
              key: 's',
              header: 'Statut',
              render: (r) => (
                <Badge tone={TONE[r.status] ?? 'neutral'}>{t(`admin.apps.${r.status}`)}</Badge>
              ),
            },
            {
              key: 'a',
              header: '',
              render: (r) => <Link to={`/admin/demandes/${r.id}`}>{t('admin.apps.examine')}</Link>,
            },
          ]}
        />
      )}
    </div>
  );
}

interface Detail {
  application: Record<string, string | number | null>;
  documents: { id: string; doc_type: string; original_name: string; url: string }[];
}

export function AdminApplicationPage() {
  const { t } = useT();
  const { id = '' } = useParams();
  const [d, setD] = useState<Detail | null>(null);
  const [mode, setMode] = useState<'reject' | 'info' | null>(null);
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [again, setAgain] = useState(false);
  const [docs, setDocs] = useState<string[]>([]);
  const [msg, setMsg] = useState<string | null>(null);
  useEffect(() => {
    api<Detail>('GET', `/admin/applications/${id}`).then(setD);
  }, [id]);

  async function act(path: string, body: object) {
    try {
      await api('POST', `/admin/applications/${id}/${path}`, body);
      setMsg(t('admin.detail.done'));
      setMode(null);
      setD(await api<Detail>('GET', `/admin/applications/${id}`));
    } catch (e) {
      setMsg(
        e instanceof ApiError && e.code === 'conflict'
          ? t('admin.detail.conflict')
          : t('errors.server_error'),
      );
    }
  }
  if (!d) return <p>{t('common.loading')}</p>;
  const a = d.application;
  return (
    <div className="detail">
      <Link to="/admin">← {t('admin.apps.title')}</Link>
      <h1>
        {String(a.pharmacy_name)}{' '}
        <Badge tone={TONE[String(a.status)] ?? 'neutral'}>{t(`admin.apps.${a.status}`)}</Badge>
      </h1>
      <div className="grid2">
        <section className="panel">
          <h2>{String(a.number)}</h2>
          <dl>
            <dt>Agrément</dt>
            <dd>{String(a.license_number)}</dd>
            <dt>Adresse</dt>
            <dd>
              {String(a.district)}, {String(a.city)} · {String(a.region)} / {String(a.department)}
            </dd>
            <dt>GPS</dt>
            <dd>
              {String(a.latitude ?? '—')}, {String(a.longitude ?? '—')}
            </dd>
          </dl>
        </section>
        <section className="panel">
          <h2>
            {String(a.owner_first_name)} {String(a.owner_last_name)}
          </h2>
          <dl>
            <dt>N° Ordre</dt>
            <dd>{String(a.owner_order_number)}</dd>
            <dt>E-mail</dt>
            <dd>{String(a.owner_email)}</dd>
            <dt>Tél.</dt>
            <dd>{String(a.owner_phone)}</dd>
          </dl>
        </section>
      </div>
      <section className="panel">
        <h2>{t('admin.detail.docs')}</h2>
        <ul>
          {d.documents.map((x) => (
            <li key={x.id}>
              <a href={`/api${x.url}`} target="_blank" rel="noreferrer">
                {t(`apply.doc.${x.doc_type}`)}
              </a>{' '}
              <span className="muted">{x.original_name}</span>
            </li>
          ))}
        </ul>
      </section>
      {a.status === 'pending' && !mode && (
        <div className="actions">
          <Button onClick={() => act('approve', { plan: 'trial', pos_count: a.pos_count })}>
            {t('admin.detail.approve')}
          </Button>
          <Button variant="warning" onClick={() => setMode('info')}>
            {t('admin.detail.info')}
          </Button>
          <Button variant="danger" onClick={() => setMode('reject')}>
            {t('admin.detail.reject')}
          </Button>
        </div>
      )}
      {mode === 'reject' && (
        <section className="panel">
          <Field
            label={t('admin.detail.reason')}
            value={reason}
            onChange={(e) => setReason(e.target.value)}
          />
          <Field
            label={t('admin.detail.note')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
          <label className="check">
            <input type="checkbox" checked={again} onChange={(e) => setAgain(e.target.checked)} />{' '}
            {t('admin.detail.allowReapply')}
          </label>
          <div className="actions">
            <Button variant="secondary" onClick={() => setMode(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="danger"
              disabled={!reason.trim()}
              onClick={() => act('reject', { reason, note, allow_reapply: again })}
            >
              {t('admin.detail.reject')}
            </Button>
          </div>
        </section>
      )}
      {mode === 'info' && (
        <section className="panel">
          {['license', 'diploma', 'order_card', 'identity'].map((x) => (
            <label key={x} className="check">
              <input
                type="checkbox"
                checked={docs.includes(x)}
                onChange={(e) =>
                  setDocs(e.target.checked ? [...docs, x] : docs.filter((y) => y !== x))
                }
              />{' '}
              {t(`apply.doc.${x}`)}
            </label>
          ))}
          <Field
            label={t('admin.detail.message')}
            value={note}
            onChange={(e) => setNote(e.target.value)}
          />
          <div className="actions">
            <Button variant="secondary" onClick={() => setMode(null)}>
              {t('common.cancel')}
            </Button>
            <Button
              variant="warning"
              disabled={!docs.length || !note.trim()}
              onClick={() => act('request-info', { docs, message: note })}
            >
              {t('admin.detail.info')}
            </Button>
          </div>
        </section>
      )}
      {msg && (
        <div role="status" className="notice">
          {msg}
        </div>
      )}
    </div>
  );
}
