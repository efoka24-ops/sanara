/**
 * Contrat de synchronisation (T037, T038). Journal d'opérations idempotent : les identifiants sont des ULID
 * générés côté poste, l'ordre est chronologique puis par identifiant, un lot rejoué n'a aucun effet.
 */
export const SCHEMA_VERSION = 1;
export const SYNC_PROTOCOL_VERSION = 1;

export interface SyncOperation {
  id: string;
  deviceId: string;
  type: string;
  payload: unknown;
  schemaVersion: number;
  createdAt: string;
}

const byOrder = (a: SyncOperation, b: SyncOperation) =>
  a.createdAt.localeCompare(b.createdAt) || a.id.localeCompare(b.id);

/** File des opérations en attente d'envoi. */
export interface OperationQueue {
  append(o: SyncOperation): void;
  pending(): SyncOperation[];
  pendingCount(): number;
  acknowledge(ids: string[]): void;
}

/** Version en mémoire (le desktop persiste la sienne dans SQLite : même interface). */
export class LocalQueue implements OperationQueue {
  private ops = new Map<string, SyncOperation>();
  append(o: SyncOperation): void {
    if (!this.ops.has(o.id)) this.ops.set(o.id, o);
  }
  pending(): SyncOperation[] {
    return [...this.ops.values()].sort(byOrder);
  }
  pendingCount(): number {
    return this.ops.size;
  }
  acknowledge(ids: string[]): void {
    ids.forEach((i) => this.ops.delete(i));
  }
}

export interface AppliedStore {
  has(id: string): Promise<boolean>;
  mark(id: string): Promise<void>;
}
export class MemoryApplied implements AppliedStore {
  private s = new Set<string>();
  async has(id: string) {
    return this.s.has(id);
  }
  async mark(id: string) {
    this.s.add(id);
  }
}

export interface BatchResult {
  applied: string[];
  duplicates: string[];
  rejected: { id: string; reason: 'schema_too_new' }[];
  failed: { id: string; reason: string }[];
}

export interface VersionedEntity<T = unknown> {
  id: string;
  version: number;
  data: T;
}

export function resolveServerWins<T>(
  local: VersionedEntity<T>,
  server: VersionedEntity<T>,
): VersionedEntity<T> {
  return server.version >= local.version ? server : local;
}

export async function applyBatch(
  batch: SyncOperation[],
  store: AppliedStore,
  handler: (o: SyncOperation) => Promise<void>,
): Promise<BatchResult> {
  const r: BatchResult = { applied: [], duplicates: [], rejected: [], failed: [] };
  for (const o of [...batch].sort(byOrder)) {
    if (o.schemaVersion > SCHEMA_VERSION) {
      r.rejected.push({ id: o.id, reason: 'schema_too_new' });
      continue;
    }
    if (await store.has(o.id)) {
      r.duplicates.push(o.id);
      continue;
    }
    try {
      await handler(o);
      await store.mark(o.id);
      r.applied.push(o.id);
    } catch (e) {
      r.failed.push({ id: o.id, reason: e instanceof Error ? e.message : 'error' });
    }
  }
  return r;
}
