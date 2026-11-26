import { describe, expect, it } from 'vitest';
import {
  LocalQueue,
  MemoryApplied,
  SCHEMA_VERSION,
  applyBatch,
  resolveServerWins,
  type SyncOperation,
} from '../src';

const op = (id: string, over: Partial<SyncOperation> = {}): SyncOperation => ({
  id,
  deviceId: 'desktop-01',
  userId: 'u1',
  type: 'ref.update',
  payload: { id, status: 'updated' },
  schemaVersion: SCHEMA_VERSION,
  createdAt: '2026-11-24T10:00:00.000Z',
  ...over,
});

describe('Scenarios offline', () => {
  it('interruption/reprise rejoue sans doublon (idempotence)', async () => {
    const queue = new LocalQueue();
    queue.append(op('01A', { createdAt: '2026-11-24T10:00:00.000Z' }));
    queue.append(op('01B', { createdAt: '2026-11-24T10:01:00.000Z' }));

    const appliedStore = new MemoryApplied();
    const seen: string[] = [];
    const handler = async (syncOp: SyncOperation) => {
      seen.push(syncOp.id);
    };

    const firstBatch = queue.pending();
    const interrupted = await applyBatch(firstBatch, appliedStore, handler);
    expect(interrupted.applied).toEqual(['01A', '01B']);

    // Reprise réseau: le même lot est renvoyé depuis la file locale
    const resumed = await applyBatch(firstBatch, appliedStore, handler);
    expect(resumed.duplicates).toEqual(['01A', '01B']);
    expect(seen).toEqual(['01A', '01B']);
  });

  it('en conflit sur référentiel, la version serveur est conservée', () => {
    const local = {
      id: 'catalog-item-1',
      version: 3,
      data: { label: 'Local draft', price: 11 },
    };
    const server = {
      id: 'catalog-item-1',
      version: 4,
      data: { label: 'Server truth', price: 10 },
    };

    const resolved = resolveServerWins(local, server);

    expect(resolved).toEqual(server);
  });
});
