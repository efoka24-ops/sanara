import { describe, expect, it } from 'vitest';
import {
  LocalQueue,
  SCHEMA_VERSION,
  createHttpTransport,
  pullReference,
  pushPending,
  synchronise,
  type PullPage,
  type PushRequest,
  type PushResponse,
  type SyncOperation,
  type SyncTransport,
} from '../src';

const op = (n: number, userId = 'u1'): SyncOperation => ({
  id: `op-${String(n).padStart(3, '0')}`,
  deviceId: 'poste-1',
  userId,
  type: 'sale.create',
  payload: { n },
  schemaVersion: SCHEMA_VERSION,
  createdAt: `2026-11-26T09:${String(n).padStart(2, '0')}:00.000Z`,
});

const page = (products: string[], nextCursor: string | null, first = false): PullPage => ({
  serverTime: '2026-11-26T18:00:00.000Z',
  settings: first ? { maxDiscountBp: 1000, allowNegativeStock: false, saleCounters: {} } : null,
  products: products.map((id) => ({
    id,
    name: id,
    dci: null,
    form: null,
    strength: null,
    regulatoryStatus: 'free',
    salePrice: 100,
    vatBp: 0,
    active: true,
    barcodes: [],
    unassignedQty: 0,
  })),
  lots: [],
  nextCursor,
});

/** Serveur de test : applique tout, sauf les identifiants listés comme refusés. */
function server(options: { refused?: Record<string, string>; failOnBatch?: number } = {}) {
  const seen = new Set<string>();
  const batches: PushRequest[] = [];
  const transport: SyncTransport = {
    async push(request) {
      batches.push(request);
      if (batches.length === options.failOnBatch) throw new Error('réseau coupé');
      const results: PushResponse['results'] = request.operations.map((o) => {
        const code = options.refused?.[o.id];
        if (code) return { id: o.id, status: 'rejected', code, conflicts: [] };
        const status = seen.has(o.id) ? 'duplicate' : 'applied';
        seen.add(o.id);
        return {
          id: o.id,
          status,
          conflicts: o.id === 'op-002' ? [{ kind: 'stock_shortage', details: {} }] : [],
        };
      });
      return { serverTime: '2026-11-26T18:00:00.000Z', clockSkewSeconds: 3, results };
    },
    async pull(cursor) {
      return cursor === '' ? page(['A', 'B'], 'B', true) : page(['C'], null);
    },
  };
  return { transport, batches, seen };
}

const queueOf = (...ops: SyncOperation[]) => {
  const q = new LocalQueue();
  ops.forEach((o) => q.append(o));
  return q;
};
const who = { deviceId: 'poste-1', userId: 'u1' };

describe('envoi des opérations', () => {
  it('envoie par lots dans l’ordre, acquitte et rapporte les écarts', async () => {
    const q = queueOf(op(3), op(1), op(2));
    const s = server();
    const report = await pushPending(q, s.transport, { ...who, batchSize: 2 });

    expect(s.batches.map((b) => b.operations.map((o) => o.id))).toEqual([
      ['op-001', 'op-002'],
      ['op-003'],
    ]);
    expect(report).toMatchObject({ online: true, applied: 3, rejected: [], clockSkewSeconds: 3 });
    expect(report.conflicts).toEqual([
      { operationId: 'op-002', kind: 'stock_shortage', details: {} },
    ]);
    expect(q.pendingCount()).toBe(0);
  });

  it('après une coupure, reprend ce qui n’est pas acquitté, sans doublon', async () => {
    const q = queueOf(op(1), op(2), op(3));
    const s = server({ failOnBatch: 2 });
    const first = await pushPending(q, s.transport, { ...who, batchSize: 2 });
    expect(first).toMatchObject({ online: false, applied: 2 });
    expect(q.pending().map((o) => o.id)).toEqual(['op-003']);

    const second = await pushPending(q, s.transport, { ...who, batchSize: 2 });
    expect(second).toMatchObject({ online: true, applied: 1 });
    expect(s.seen.size).toBe(3);
  });

  it('garde une opération refusée avec son motif, jusqu’à ce qu’elle passe', async () => {
    const q = queueOf(op(1), op(2));
    const refused: Record<string, string> = { 'op-001': 'prescription_required' };
    const s = server({ refused });
    const report = await pushPending(q, s.transport, who);
    expect(report.rejected).toEqual([{ id: 'op-001', code: 'prescription_required' }]);
    expect(q.rejected()).toEqual([{ id: 'op-001', code: 'prescription_required' }]);
    expect(q.pendingCount()).toBe(1);

    delete refused['op-001'];
    await pushPending(q, s.transport, who);
    expect(q.rejected()).toEqual([]);
    expect(q.pendingCount()).toBe(0);
  });

  it('laisse en attente les opérations d’un autre compte', async () => {
    const q = queueOf(op(1, 'u2'), op(2, 'u1'));
    const s = server();
    const report = await pushPending(q, s.transport, who);
    expect(s.batches).toHaveLength(1);
    expect(s.batches[0]!.operations.map((o) => o.id)).toEqual(['op-002']);
    expect(report.waitingForOtherUsers).toBe(1);
    expect(q.pending().map((o) => o.id)).toEqual(['op-001']);
  });

  it('interroge le serveur même sans rien à envoyer, pour l’heure de référence', async () => {
    const s = server();
    const report = await pushPending(new LocalQueue(), s.transport, who);
    expect(s.batches).toHaveLength(1);
    expect(report.clockSkewSeconds).toBe(3);
  });
});

describe('réception du référentiel', () => {
  it('assemble toutes les pages', async () => {
    const snapshot = await pullReference(server().transport);
    expect(snapshot.products.map((p) => p.id)).toEqual(['A', 'B', 'C']);
    expect(snapshot.settings.maxDiscountBp).toBe(1000);
  });

  it('refuse un curseur qui n’avance pas', async () => {
    const transport: SyncTransport = {
      ...server().transport,
      pull: async () => page([], 'A', true),
    };
    await expect(pullReference(transport)).rejects.toThrow(/curseur/);
  });

  it('ne remplace le référentiel que si l’envoi et toutes les pages ont abouti', async () => {
    const stored: string[][] = [];
    const sink = {
      replace: (s: { products: { id: string }[] }) => void stored.push(s.products.map((p) => p.id)),
    };

    const cut = server({ failOnBatch: 1 });
    const offline = await synchronise(queueOf(op(1)), cut.transport, sink, who);
    expect(offline).toMatchObject({ online: false, refreshed: false });

    const broken: SyncTransport = {
      ...server().transport,
      pull: async (cursor) => {
        if (cursor !== '') throw new Error('coupure');
        return page(['A'], 'A', true);
      },
    };
    expect(await synchronise(queueOf(), broken, sink, who)).toMatchObject({ refreshed: false });
    expect(stored).toEqual([]);

    expect(await synchronise(queueOf(), server().transport, sink, who)).toMatchObject({
      online: true,
      refreshed: true,
    });
    expect(stored).toEqual([['A', 'B', 'C']]);
  });
});

describe('transport HTTP', () => {
  it('envoie le jeton, et traite une réponse non JSON ou une erreur comme un échec', async () => {
    const calls: { url: string; init: RequestInit }[] = [];
    let reply = new Response(JSON.stringify(page([], null, true)), { status: 200 });
    const transport = createHttpTransport({
      baseUrl: 'http://serveur/api',
      token: () => 'jeton',
      fetch: async (url, init) => {
        calls.push({ url: String(url), init: init ?? {} });
        return reply;
      },
    });
    await transport.pull('X Y');
    expect(calls[0]!.url).toBe('http://serveur/api/sync/pull?cursor=X%20Y');
    expect(calls[0]!.init.headers).toMatchObject({ Authorization: 'Bearer jeton' });

    reply = new Response('<html>403</html>', { status: 200 });
    await expect(transport.push({ deviceId: 'p', sentAt: '', operations: [] })).rejects.toThrow();
    reply = new Response('{"error":{"code":"unauthorized"}}', { status: 401 });
    await expect(transport.pull('')).rejects.toThrow(/401/);
  });
});
