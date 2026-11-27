/** T136 : envoi des opérations par lots et réception du référentiel. Voir apps/api/app/Modules/Sync. */
import type { OperationQueue, SyncOperation } from './index';

export interface SyncConflict {
  kind: string;
  details: Record<string, unknown>;
}

export interface PushRequest {
  deviceId: string;
  sentAt: string;
  operations: SyncOperation[];
}

export interface PushResponse {
  serverTime: string;
  clockSkewSeconds: number | null;
  results: {
    id: string;
    status: 'applied' | 'duplicate' | 'rejected';
    code?: string;
    conflicts: SyncConflict[];
  }[];
}

export interface ReferenceSettings {
  maxDiscountBp: number;
  allowNegativeStock: boolean;
  /** Dernier numéro de ticket connu du serveur, par « POSTE:année ». */
  saleCounters: Record<string, number>;
  /** En-tête des tickets imprimés sur le poste. */
  pharmacy?: PharmacyHeader;
}
export interface PharmacyHeader {
  name: string;
  address: string;
  phone: string;
  licenseNumber: string;
  currency: string;
  ticketFooter: string;
  ticketWidth: string;
}
export interface ReferenceProduct {
  id: string;
  name: string;
  dci: string | null;
  form: string | null;
  strength: string | null;
  regulatoryStatus: string;
  salePrice: number;
  vatBp: number;
  active: boolean;
  barcodes: string[];
  unassignedQty: number;
}
export interface ReferenceLot {
  id: string;
  productId: string;
  lotNumber: string;
  expiry: string;
  status: string;
  receivedAt: string;
  quantity: number;
}
export interface PullPage {
  serverTime: string;
  settings: ReferenceSettings | null;
  products: ReferenceProduct[];
  lots: ReferenceLot[];
  nextCursor: string | null;
}
export interface ReferenceSnapshot {
  serverTime: string;
  settings: ReferenceSettings;
  products: ReferenceProduct[];
  lots: ReferenceLot[];
}

export interface SyncTransport {
  push(request: PushRequest): Promise<PushResponse>;
  pull(cursor: string): Promise<PullPage>;
}

export interface ReferenceSink {
  /** Remplace tout le référentiel local, en une seule transaction. */
  replace(snapshot: ReferenceSnapshot): void;
}

export interface SyncIdentity {
  deviceId: string;
  userId: string;
  batchSize?: number;
  /** Opérations traitées sur le total à envoyer, avant puis après chaque lot. */
  onProgress?: (sent: number, total: number) => void;
}

export interface PushReport {
  /** Faux si le serveur n'a pas pu être joint : ce qui n'est pas acquitté sera renvoyé. */
  online: boolean;
  applied: number;
  rejected: { id: string; code: string }[];
  conflicts: (SyncConflict & { operationId: string })[];
  clockSkewSeconds: number | null;
  waitingForOtherUsers: number;
}

export async function pushPending(
  queue: OperationQueue,
  transport: SyncTransport,
  { deviceId, userId, batchSize = 100, onProgress }: SyncIdentity,
): Promise<PushReport> {
  const pending = queue.pending();
  const mine = pending.filter((o) => o.userId === userId);
  const report: PushReport = {
    online: true,
    applied: 0,
    rejected: [],
    conflicts: [],
    clockSkewSeconds: null,
    waitingForOtherUsers: pending.length - mine.length,
  };
  // Au moins un appel, même vide : il donne l'écart d'horloge avec le serveur.
  if (mine.length > 0) onProgress?.(0, mine.length);
  for (let i = 0; i === 0 || i < mine.length; i += batchSize) {
    let response: PushResponse;
    try {
      response = await transport.push({
        deviceId,
        sentAt: new Date().toISOString(),
        operations: mine.slice(i, i + batchSize),
      });
    } catch {
      return { ...report, online: false };
    }
    report.clockSkewSeconds = response.clockSkewSeconds;
    const done: string[] = [];
    for (const r of response.results) {
      if (r.status === 'rejected') {
        const code = r.code ?? 'server_error';
        queue.markRejected(r.id, code);
        report.rejected.push({ id: r.id, code });
        continue;
      }
      done.push(r.id);
      report.applied += r.status === 'applied' ? 1 : 0;
      report.conflicts.push(...r.conflicts.map((c) => ({ ...c, operationId: r.id })));
    }
    queue.acknowledge(done);
    if (mine.length > 0) onProgress?.(Math.min(i + batchSize, mine.length), mine.length);
  }
  return report;
}

export async function pullReference(transport: SyncTransport): Promise<ReferenceSnapshot> {
  let cursor = '';
  let settings: ReferenceSettings | null = null;
  let serverTime = '';
  const products: ReferenceProduct[] = [];
  const lots: ReferenceLot[] = [];
  for (;;) {
    const page = await transport.pull(cursor);
    settings ??= page.settings;
    serverTime ||= page.serverTime;
    products.push(...page.products);
    lots.push(...page.lots);
    if (page.nextCursor === null) break;
    if (page.nextCursor <= cursor) throw new Error('curseur de synchronisation bloqué');
    cursor = page.nextCursor;
  }
  if (!settings) throw new Error('réglages absents de la première page');
  return { serverTime, settings, products, lots };
}

/** Envoie d'abord, puis rafraîchit le référentiel, qui tient ainsi compte de ce qui vient de partir. */
export async function synchronise(
  queue: OperationQueue,
  transport: SyncTransport,
  sink: ReferenceSink,
  identity: SyncIdentity,
): Promise<PushReport & { refreshed: boolean }> {
  const report = await pushPending(queue, transport, identity);
  if (!report.online) return { ...report, refreshed: false };
  let snapshot: ReferenceSnapshot;
  try {
    snapshot = await pullReference(transport);
  } catch {
    return { ...report, refreshed: false };
  }
  sink.replace(snapshot);
  return { ...report, refreshed: true };
}

export function createHttpTransport(options: {
  baseUrl: string;
  token: () => string;
  fetch?: typeof fetch;
}): SyncTransport {
  const send = options.fetch ?? fetch;
  async function call<T>(path: string, body?: unknown): Promise<T> {
    const response = await send(`${options.baseUrl}${path}`, {
      method: body === undefined ? 'GET' : 'POST',
      headers: {
        Accept: 'application/json',
        Authorization: `Bearer ${options.token()}`,
        ...(body === undefined ? {} : { 'Content-Type': 'application/json' }),
      },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (!response.ok) throw new Error(`synchronisation refusée : HTTP ${response.status}`);
    // Une page d'erreur de l'hébergeur n'est pas du JSON : json() lève, rien n'est acquitté.
    return (await response.json()) as T;
  }
  return {
    push: (request) => call<PushResponse>('/sync/push', request),
    pull: (cursor) => call<PullPage>(`/sync/pull?cursor=${encodeURIComponent(cursor)}`),
  };
}
