import { useEffect, useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { useT } from '@sanara/i18n';
import { Badge, Button, Field, Modal, Table, useToast } from '@sanara/ui';
import { accessHeader, api, ApiError } from '../api/client';
import { useDesktop } from '../app/desktop';
import { money, ProductSearch, useErrorText, type ProductLite } from '../lib/common';
import { when, type RequestStatus } from '../lib/requests';
import {
  loadLexicon,
  readPrescription,
  readText,
  type LearnedState,
  type Proposal,
} from '../lib/reader';

interface Item {
  id: string;
  number: string;
  status: RequestStatus;
  urgent: boolean;
  first_name: string;
  created_at: string;
  locked_by: string | null;
  locked_by_name: string | null;
  total: number | null;
  pages?: number;
}
interface Detail extends Item {
  mine: boolean;
  mode: 'pickup' | 'own_delivery';
  phone: string;
  advice: string | null;
  pickup_code: string | null;
  documents: { id: string; name: string; mime: string; size: number }[];
  duplicates: { number: string; status: RequestStatus }[];
  events: { kind: string; at: string; note: string | null }[];
  quote: {
    total: number;
    valid_until: string;
    lines: {
      id: string;
      label: string;
      quantity: number;
      unit_price: number;
      total: number;
      available: boolean;
      accepted: boolean | null;
      read_text: string | null;
      corrected: boolean;
      replaces: string | null;
    }[];
  } | null;
}
interface Draft {
  product: ProductLite | null;
  label: string;
  quantity: number;
  read_text: string;
  read_confidence: number | null;
  replaces?: string;
  available: boolean;
}

const TONE: Record<string, 'ok' | 'wait' | 'danger' | 'neutral'> = {
  received: 'wait',
  photo_needed: 'wait',
  processing: 'wait',
  quoted: 'neutral',
  accepted: 'ok',
  ready: 'ok',
  picked_up: 'ok',
  cancelled: 'danger',
  expired: 'danger',
};

export function StatusBadge({ status }: { status: RequestStatus }) {
  const { t } = useT();
  return <Badge tone={TONE[status] ?? 'neutral'}>{t(`req.status.${status}`)}</Badge>;
}

/** US22 : file des demandes, urgences d'abord, rafraîchie toutes les 10 s. */
function RequestsList() {
  const { t, locale } = useT();
  const errText = useErrorText();
  const [closed, setClosed] = useState(false);
  const [items, setItems] = useState<Item[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    let live = true;
    const load = () =>
      api<{ items: Item[] }>('GET', `/app/requests${closed ? '?closed=1' : ''}`)
        .then((r) => {
          if (!live) return;
          setItems(r.items);
          setError(null);
        })
        .catch((e) => live && setError(errText(e)));
    setItems(null);
    void load();
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load();
    }, 10000);
    return () => {
      live = false;
      window.clearInterval(timer);
    };
  }, [closed]);

  return (
    <div className="stack">
      <div className="row">
        <h1>{t('req.title')}</h1>
        <div className="duties__tabs">
          {[false, true].map((c) => (
            <button
              key={String(c)}
              type="button"
              className={`chip${closed === c ? ' chip--on' : ''}`}
              aria-pressed={closed === c}
              onClick={() => setClosed(c)}
            >
              {t(c ? 'req.closed' : 'req.open')}
            </button>
          ))}
        </div>
      </div>
      {error && (
        <div role="alert" className="form-error">
          {error}
        </div>
      )}
      <Table
        rowKey={(r: Item) => r.id}
        rows={items ?? []}
        columns={[
          {
            key: 'n',
            header: t('req.number'),
            render: (r) => (
              <Link className="link-strong" to={`/app/demandes/${r.id}`}>
                {r.number}
              </Link>
            ),
          },
          { key: 'p', header: t('req.patient'), render: (r) => r.first_name },
          {
            key: 's',
            header: t('req.status'),
            render: (r) => (
              <span className="row">
                <StatusBadge status={r.status} />
                {r.urgent && <Badge tone="danger">{t('req.urgent')}</Badge>}
              </span>
            ),
          },
          { key: 'd', header: t('req.pages'), render: (r) => r.pages ?? '' },
          { key: 'a', header: t('req.received'), render: (r) => when(r.created_at, locale) },
          { key: 'l', header: t('req.handler'), render: (r) => r.locked_by_name ?? '' },
          {
            key: 't',
            header: t('pos.total'),
            render: (r) => (r.total === null ? '' : money(r.total)),
          },
        ]}
      />
      {items?.length === 0 && <p className="muted">{t(closed ? 'req.noneClosed' : 'req.none')}</p>}
    </div>
  );
}

/** Document de la demande, lu avec le jeton de session : jamais d'adresse publique. */
function DocumentView({ path, mime, name }: { path: string; mime: string; name: string }) {
  const { t } = useT();
  const [url, setUrl] = useState<string | null>(null);
  const [failed, setFailed] = useState(false);
  useEffect(() => {
    let made: string | null = null;
    const base = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api';
    fetch(`${base}${path}`, { headers: accessHeader() })
      .then((r) => (r.ok ? r.blob() : Promise.reject(new Error(String(r.status)))))
      .then((b) => {
        made = URL.createObjectURL(b);
        setUrl(made);
      })
      .catch(() => setFailed(true));
    return () => {
      if (made) URL.revokeObjectURL(made);
    };
  }, [path]);
  if (failed) return <p className="form-error">{t('errors.server_error')}</p>;
  if (!url) return <p className="muted">{t('common.loading')}</p>;
  return mime === 'application/pdf' ? (
    <iframe className="rx-doc" title={name} src={url} />
  ) : (
    <a href={url} target="_blank" rel="noreferrer">
      <img className="rx-doc" src={url} alt={name} />
    </a>
  );
}

/** US22 : traitement d'une demande, du document au devis, puis préparation. */
function RequestDetail() {
  const { id = '' } = useParams();
  const { t, locale } = useT();
  const toast = useToast();
  const errText = useErrorText();
  const [d, setD] = useState<Detail | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [lines, setLines] = useState<Draft[]>([]);
  const [freeLabel, setFreeLabel] = useState('');
  const [cancelling, setCancelling] = useState<string | null>(null);
  const [page, setPage] = useState(0);
  const [learned, setLearned] = useState<LearnedState | null>(null);
  const [quota, setQuota] = useState<{ limit: number | null; used: number } | null>(null);
  const [reader, setReader] = useState<{
    busy: boolean;
    progress: number;
    proposals: Proposal[] | null;
    anomalies: string[];
    failed: boolean;
  }>({ busy: false, progress: 0, proposals: null, anomalies: [], failed: false });

  /** Lecture des photos sur ce poste, puis rapprochement avec le catalogue et ce que la pharmacie a appris. */
  async function read(docs: Detail['documents']) {
    setReader({ busy: true, progress: 0, proposals: null, anomalies: [], failed: false });
    try {
      const usage = await api<{ limit: number | null; used: number }>(
        'POST',
        '/app/assistant/readings',
        {
          request_id: id,
        },
      );
      setQuota(usage);
      // Relu à chaque fois : il inclut les choix faits sur les demandes précédentes.
      const lexicon = await loadLexicon();
      setLearned(lexicon);
      const base = (import.meta.env.VITE_API_URL as string | undefined) ?? '/api';
      const pages = await Promise.all(
        docs
          .filter((x) => x.mime !== 'application/pdf')
          .map((x) =>
            fetch(`${base}/app/requests/${id}/documents/${x.id}`, { headers: accessHeader() }).then(
              (r) => r.blob(),
            ),
          ),
      );
      const { reading, proposals } = await readPrescription(pages, lexicon.links, (p) =>
        setReader((r) => ({ ...r, progress: p })),
      );
      setReader({
        busy: false,
        progress: 1,
        proposals,
        anomalies: reading.anomalies,
        failed: false,
      });
    } catch (e) {
      console.error('lecture de l’ordonnance', e);
      if (e instanceof ApiError && e.code === 'reading_quota_reached') {
        setReader({ busy: false, progress: 0, proposals: null, anomalies: [], failed: false });
        setError(errText(e));
        return;
      }
      setReader({ busy: false, progress: 0, proposals: null, anomalies: [], failed: true });
    }
  }

  function propose(p: Proposal, chosen: ProductLite | null) {
    setLines((ls) => [
      ...ls,
      {
        product: chosen,
        label: chosen?.name ?? p.line.name,
        quantity: p.line.quantity ?? 1,
        read_text: readText(p.line),
        read_confidence: p.line.confidence,
        available: chosen !== null,
      },
    ]);
    setReader((r) => ({ ...r, proposals: r.proposals?.filter((x) => x !== p) ?? null }));
  }

  const load = () =>
    api<Detail>('GET', `/app/requests/${id}`)
      .then(setD)
      .catch((e) => setError(errText(e)));
  useEffect(() => {
    void load();
    // La réponse du patient arrive sans que le pharmacien ait à recharger.
    const timer = window.setInterval(() => {
      if (document.visibilityState === 'visible') void load();
    }, 10000);
    return () => window.clearInterval(timer);
  }, [id]);

  async function step(action: string, body: unknown = {}, done?: string) {
    setError(null);
    try {
      await api('POST', `/app/requests/${id}/${action}`, body);
      if (done) toast(done);
      await load();
      return true;
    } catch (e) {
      if (e instanceof ApiError && e.code === 'insufficient_stock') {
        const product = lines.find((l) => l.product?.id === e.details.product_id)?.product?.name;
        setError(t('req.short', { product: product ?? '', n: Number(e.details.available ?? 0) }));
      } else setError(errText(e));
      await load();
      return false;
    }
  }

  if (!d)
    return error ? (
      <div className="form-error">{error}</div>
    ) : (
      <p className="muted">{t('common.loading')}</p>
    );

  const mine = d.mine;
  const takeable = ['received', 'photo_needed', 'processing'].includes(d.status) && !mine;
  const draftTotal = lines.reduce(
    (n, l) => n + (l.product && l.available ? l.product.sale_price * l.quantity : 0),
    0,
  );
  const doc = d.documents[page];

  return (
    <div className="stack request">
      <div className="row">
        <div>
          <h1>
            {t('req.one', { number: d.number })} <StatusBadge status={d.status} />{' '}
            {d.urgent && <Badge tone="danger">{t('req.urgent')}</Badge>}
          </h1>
          <p className="muted">
            {d.first_name} · <a href={`tel:${d.phone.replace(/\s/g, '')}`}>{d.phone}</a> ·{' '}
            {t(`send.mode.${d.mode}`)} · {when(d.created_at, locale)}
          </p>
        </div>
        {d.pickup_code && (
          <p className="pickup__mini">
            {t('req.code')} <b>{d.pickup_code}</b>
          </p>
        )}
      </div>
      {d.advice === 'emergency' && (
        <p role="alert" className="form-error">
          {t('req.emergency')}
        </p>
      )}
      {d.duplicates.length > 0 && (
        <p role="alert" className="notice">
          {t('req.duplicate', { numbers: d.duplicates.map((x) => x.number).join(', ') })}
        </p>
      )}
      {error && (
        <div role="alert" className="form-error">
          {error}
        </div>
      )}

      <div className="request__grid">
        <section className="panel stack">
          <div className="row">
            <h2>{t('req.document')}</h2>
            {d.documents.length > 1 && (
              <span className="duties__tabs">
                {d.documents.map((x, i) => (
                  <button
                    key={x.id}
                    type="button"
                    className={`chip${i === page ? ' chip--on' : ''}`}
                    onClick={() => setPage(i)}
                  >
                    {t('req.page', { n: i + 1 })}
                  </button>
                ))}
              </span>
            )}
          </div>
          {doc && (
            <DocumentView
              key={doc.id}
              path={`/app/requests/${d.id}/documents/${doc.id}`}
              mime={doc.mime}
              name={doc.name}
            />
          )}
          <h3>{t('req.history')}</h3>
          <ul className="request__events">
            {d.events.map((e, i) => (
              <li key={i}>
                <span className="muted">{when(e.at, locale)}</span> {t(`req.event.${e.kind}`)}
                {e.note && <q>{e.note}</q>}
              </li>
            ))}
          </ul>
        </section>

        <section className="panel stack">
          {takeable && (
            <>
              {d.locked_by_name && d.status === 'processing' && (
                <p className="notice">{t('req.heldBy', { name: d.locked_by_name })}</p>
              )}
              <Button onClick={() => void step('take')}>{t('req.take')}</Button>
            </>
          )}

          {mine && (
            <>
              <h2>{t('req.quote')}</h2>
              <section className="reader">
                <div className="row">
                  <b>{t('reader.title')}</b>
                  {learned?.reliability.accepted_rate != null && (
                    <span className="muted">
                      {t('reader.reliability', {
                        rate: learned.reliability.accepted_rate,
                        n: learned.reliability.lines,
                      })}
                    </span>
                  )}
                </div>
                <p className="muted">{t('reader.hint')}</p>
                {quota?.limit != null && (
                  <p className="muted">
                    {t('reader.quota', { used: quota.used, limit: quota.limit })}
                  </p>
                )}
                {d.documents.every((x) => x.mime === 'application/pdf') ? (
                  <p className="muted">{t('reader.pdf')}</p>
                ) : (
                  <Button
                    variant="secondary"
                    disabled={reader.busy}
                    onClick={() => void read(d.documents)}
                  >
                    {reader.busy
                      ? t('reader.busy', { n: Math.round(reader.progress * 100) })
                      : t('reader.run')}
                  </Button>
                )}
                {reader.failed && <p className="form-error">{t('reader.failed')}</p>}
                {reader.anomalies.map((a) => (
                  <p key={a} className="notice">
                    {t(`reader.anomaly.${a}`)}
                  </p>
                ))}
                {reader.proposals?.map((p, i) => (
                  <div key={i} className="reader__line">
                    <div>
                      <b>{readText(p.line)}</b>{' '}
                      <span className="muted">
                        {t('reader.confidence', { n: p.line.confidence })}
                        {p.line.posology && ` · ${p.line.posology}`}
                        {p.line.quantity && ` · ${t('reader.boxes', { n: p.line.quantity })}`}
                      </span>
                    </div>
                    <div className="reader__choices">
                      {p.matches.map((m) => (
                        <button
                          key={m.product.id}
                          type="button"
                          className="chip"
                          onClick={() => propose(p, m.product as unknown as ProductLite)}
                        >
                          {m.product.name} · {m.score} %
                          {m.learned ? ` · ${t('reader.learned')}` : ''}
                        </button>
                      ))}
                      <button type="button" className="link" onClick={() => propose(p, null)}>
                        {t('reader.missing')}
                      </button>
                    </div>
                  </div>
                ))}
              </section>
              <p className="muted">{t('req.quoteHint')}</p>
              <ProductSearch
                label={t('req.addProduct')}
                onPick={(p) =>
                  setLines((ls) => [
                    ...ls,
                    {
                      product: p,
                      label: p.name,
                      quantity: 1,
                      read_text: '',
                      read_confidence: null,
                      available: true,
                    },
                  ])
                }
              />
              <form
                className="row"
                onSubmit={(e) => {
                  e.preventDefault();
                  if (!freeLabel.trim()) return;
                  setLines((ls) => [
                    ...ls,
                    {
                      product: null,
                      label: freeLabel.trim(),
                      quantity: 1,
                      read_text: '',
                      read_confidence: null,
                      available: false,
                    },
                  ]);
                  setFreeLabel('');
                }}
              >
                <Field
                  label={t('req.notFound')}
                  value={freeLabel}
                  onChange={(e) => setFreeLabel(e.target.value)}
                />
                <Button type="submit" variant="secondary">
                  {t('req.addMissing')}
                </Button>
              </form>
              <ul className="draft">
                {lines.map((l, i) => {
                  const set = (p: Partial<Draft>) =>
                    setLines((ls) => ls.map((x, k) => (k === i ? { ...x, ...p } : x)));
                  return (
                    <li key={i} className="panel">
                      <div className="row">
                        <b>{l.label}</b>
                        <button
                          type="button"
                          className="link"
                          onClick={() => setLines((ls) => ls.filter((_, k) => k !== i))}
                        >
                          {t('pos.remove')}
                        </button>
                      </div>
                      <div className="draft__fields">
                        <Field
                          label={t('pos.qty')}
                          type="number"
                          min={1}
                          value={l.quantity}
                          onChange={(e) => set({ quantity: Math.max(1, Number(e.target.value)) })}
                        />
                        <Field
                          label={t('req.readText')}
                          value={l.read_text}
                          onChange={(e) => set({ read_text: e.target.value })}
                        />
                        {l.product && (
                          <Field
                            label={t('req.replaces')}
                            value={l.replaces ?? ''}
                            onChange={(e) => set({ replaces: e.target.value })}
                          />
                        )}
                        {l.product && (
                          <label className="check">
                            <input
                              type="checkbox"
                              checked={l.available}
                              onChange={(e) => set({ available: e.target.checked })}
                            />
                            <span>{t('req.available')}</span>
                          </label>
                        )}
                      </div>
                      <p className="muted">
                        {l.product && l.available
                          ? `${money(l.product.sale_price)} × ${l.quantity} = ${money(l.product.sale_price * l.quantity)}`
                          : t('track.unavailable')}
                        {l.product && l.product.regulatory_status !== 'free' && (
                          <>
                            {' '}
                            <Badge tone="danger">{t('pos.regulated')}</Badge>
                          </>
                        )}
                      </p>
                    </li>
                  );
                })}
              </ul>
              <p className="pos__total">
                <span>{t('pos.total')}</span> <b>{money(draftTotal)}</b>
              </p>
              <Button
                disabled={lines.length === 0}
                onClick={() =>
                  void step(
                    'quote',
                    {
                      lines: lines.map((l) => ({
                        product_id: l.product?.id,
                        label: l.label,
                        quantity: l.quantity,
                        available: l.available,
                        read_text: l.read_text || undefined,
                        read_confidence: l.read_confidence ?? undefined,
                        replaces: l.replaces || undefined,
                      })),
                    },
                    t('req.quoteSent'),
                  ).then((ok) => ok && setLines([]))
                }
              >
                {t('req.sendQuote')}
              </Button>
              <div className="actions">
                <Button
                  variant="secondary"
                  onClick={() => void step('retake', {}, t('req.retakeSent'))}
                >
                  {t('req.retake')}
                </Button>
                <Button variant="secondary" onClick={() => void step('release')}>
                  {t('req.release')}
                </Button>
              </div>
            </>
          )}

          {d.quote && (
            <>
              <h2>{t('req.quoteSentTitle')}</h2>
              <ul className="quote">
                {d.quote.lines.map((l) => (
                  <li
                    key={l.id}
                    className={l.available && l.accepted !== false ? undefined : 'is-off'}
                  >
                    <span>
                      {l.label} × {l.quantity}
                      {l.replaces && (
                        <span className="muted"> · {t('req.generic', { name: l.replaces })}</span>
                      )}
                      {l.read_text && l.corrected && (
                        <span className="muted"> · {t('req.read', { text: l.read_text })}</span>
                      )}
                    </span>
                    <b>{l.available ? money(l.total) : t('track.unavailable')}</b>
                  </li>
                ))}
              </ul>
              <p className="pos__total">
                <span>{t('pos.total')}</span> <b>{money(d.quote.total)}</b>
              </p>
              {d.status === 'quoted' && (
                <p className="muted">
                  {t('req.waiting', { date: when(d.quote.valid_until, locale) })}
                </p>
              )}
            </>
          )}
          {d.status === 'accepted' && (
            <Button onClick={() => void step('ready', {}, t('req.readyDone'))}>
              {t('req.ready')}
            </Button>
          )}
          {d.status === 'ready' && <p className="notice">{t('req.readyHint')}</p>}
          {['received', 'processing', 'photo_needed', 'quoted', 'accepted', 'ready'].includes(
            d.status,
          ) &&
            (!d.locked_by || mine) && (
              <button type="button" className="link" onClick={() => setCancelling('')}>
                {t('req.cancel')}
              </button>
            )}
        </section>
      </div>

      {cancelling !== null && (
        <Modal title={t('req.cancel')} onClose={() => setCancelling(null)}>
          <div className="stack">
            <Field
              label={t('track.reason')}
              value={cancelling}
              onChange={(e) => setCancelling(e.target.value)}
            />
            <div className="actions">
              <Button variant="secondary" onClick={() => setCancelling(null)}>
                {t('common.cancel')}
              </Button>
              <Button
                variant="danger"
                disabled={!cancelling.trim()}
                onClick={() =>
                  void step('cancel', { reason: cancelling }).then(
                    (ok) => ok && setCancelling(null),
                  )
                }
              >
                {t('req.cancel')}
              </Button>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}

/** T308 : sur le poste hors ligne, les demandes des patients n'arrivent pas : on le dit. */
function OnlineOnly({ children }: { children: React.ReactNode }) {
  const { t } = useT();
  const { status } = useDesktop();
  if (status && !status.online)
    return (
      <div className="stack">
        <h1>{t('req.title')}</h1>
        <p className="notice">{t('req.offline')}</p>
      </div>
    );
  return <>{children}</>;
}

export function RequestsPage() {
  return (
    <OnlineOnly>
      <RequestsList />
    </OnlineOnly>
  );
}

export function RequestPage() {
  return (
    <OnlineOnly>
      <RequestDetail />
    </OnlineOnly>
  );
}
