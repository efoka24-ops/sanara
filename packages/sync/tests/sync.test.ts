import { describe, expect, it } from 'vitest';
import { LocalQueue, applyBatch, MemoryApplied, SCHEMA_VERSION, type SyncOperation } from '../src';

const op = (id: string, over: Partial<SyncOperation> = {}): SyncOperation => ({
  id,
  deviceId: 'D1',
  type: 'sale.create',
  payload: { n: id },
  schemaVersion: SCHEMA_VERSION,
  createdAt: '2026-11-24T10:00:00.000Z',
  ...over,
});

describe('LocalQueue (file locale côté poste)', () => {
  it("conserve l'ordre de création et expose les opérations en attente", () => {
    const q = new LocalQueue();
    q.append(op('01B'));
    q.append(op('01A', { createdAt: '2026-11-24T09:00:00.000Z' }));
    expect(q.pending().map((o) => o.id)).toEqual(['01A', '01B']);
    expect(q.pendingCount()).toBe(2);
  });
  it('refuse deux fois la même opération (idempotence locale)', () => {
    const q = new LocalQueue();
    q.append(op('01A'));
    q.append(op('01A'));
    expect(q.pendingCount()).toBe(1);
  });
  it('retire uniquement les opérations acquittées', () => {
    const q = new LocalQueue();
    q.append(op('01A'));
    q.append(op('01B'));
    q.acknowledge(['01A']);
    expect(q.pending().map((o) => o.id)).toEqual(['01B']);
  });
});

describe('applyBatch (côté serveur)', () => {
  it('applique chaque opération une seule fois même si le lot est rejoué', async () => {
    const applied = new MemoryApplied();
    const seen: string[] = [];
    const handler = async (o: SyncOperation) => {
      seen.push(o.id);
    };
    const batch = [op('01A'), op('01B')];
    const r1 = await applyBatch(batch, applied, handler);
    const r2 = await applyBatch(batch, applied, handler);
    expect(seen).toEqual(['01A', '01B']);
    expect(r1.applied).toEqual(['01A', '01B']);
    expect(r2.duplicates).toEqual(['01A', '01B']);
  });
  it("traite dans l'ordre chronologique puis par identifiant", async () => {
    const seen: string[] = [];
    await applyBatch(
      [op('01C', { createdAt: '2026-11-24T11:00:00.000Z' }), op('01B'), op('01A')],
      new MemoryApplied(),
      async (o) => {
        seen.push(o.id);
      },
    );
    expect(seen).toEqual(['01A', '01B', '01C']);
  });
  it('rejette une version de schéma plus récente que le serveur', async () => {
    const r = await applyBatch(
      [op('01A', { schemaVersion: SCHEMA_VERSION + 1 })],
      new MemoryApplied(),
      async () => undefined,
    );
    expect(r.rejected).toEqual([{ id: '01A', reason: 'schema_too_new' }]);
  });
  it("accepte une version plus ancienne (rétrocompatibilité d'une version)", async () => {
    const r = await applyBatch(
      [op('01A', { schemaVersion: SCHEMA_VERSION - 1 })],
      new MemoryApplied(),
      async () => undefined,
    );
    expect(r.applied).toEqual(['01A']);
  });
  it("une opération en échec n'est pas marquée appliquée et peut être rejouée", async () => {
    const applied = new MemoryApplied();
    let fail = true;
    const handler = async () => {
      if (fail) throw new Error('boom');
    };
    const r1 = await applyBatch([op('01A')], applied, handler);
    expect(r1.failed).toEqual([{ id: '01A', reason: 'boom' }]);
    fail = false;
    const r2 = await applyBatch([op('01A')], applied, handler);
    expect(r2.applied).toEqual(['01A']);
  });
});
