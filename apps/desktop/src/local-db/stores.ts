import type { AppliedStore, OperationQueue, SyncOperation } from '@sanara/sync';
import type { LocalDatabase } from './database';

interface OperationRow {
  id: string;
  device_id: string;
  type: string;
  payload_json: string;
  schema_version: number;
  created_at: string;
}

/** File d'opérations persistante. Une opération acquittée reste en base : la rejouer est sans effet. */
export class SqliteQueue implements OperationQueue {
  constructor(private readonly db: LocalDatabase) {}

  append(o: SyncOperation): void {
    // « ON CONFLICT(id) » ne tolère que le doublon : une donnée manquante reste une erreur.
    this.db
      .prepare(
        `INSERT INTO operations (id, device_id, type, payload_json, schema_version, created_at)
         VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT(id) DO NOTHING`,
      )
      .run(o.id, o.deviceId, o.type, JSON.stringify(o.payload), o.schemaVersion, o.createdAt);
  }

  pending(): SyncOperation[] {
    const rows = this.db
      .prepare('SELECT * FROM operations WHERE acked_at IS NULL ORDER BY created_at, id')
      .all() as OperationRow[];
    return rows.map((r) => ({
      id: r.id,
      deviceId: r.device_id,
      type: r.type,
      payload: JSON.parse(r.payload_json) as unknown,
      schemaVersion: r.schema_version,
      createdAt: r.created_at,
    }));
  }

  pendingCount(): number {
    const row = this.db
      .prepare('SELECT COUNT(*) AS n FROM operations WHERE acked_at IS NULL')
      .get() as { n: number };
    return row.n;
  }

  acknowledge(ids: string[]): void {
    const ack = this.db.prepare(
      'UPDATE operations SET acked_at = ? WHERE id = ? AND acked_at IS NULL',
    );
    const now = new Date().toISOString();
    this.db.transaction(() => ids.forEach((id) => ack.run(now, id)))();
  }
}

export class SqliteApplied implements AppliedStore {
  constructor(private readonly db: LocalDatabase) {}

  async has(id: string): Promise<boolean> {
    return this.db.prepare('SELECT 1 FROM applied_operations WHERE id = ?').get(id) !== undefined;
  }

  async mark(id: string): Promise<void> {
    this.db
      .prepare(
        'INSERT INTO applied_operations (id, applied_at) VALUES (?, ?) ON CONFLICT(id) DO NOTHING',
      )
      .run(id, new Date().toISOString());
  }
}

export class SqliteState {
  constructor(private readonly db: LocalDatabase) {}

  get(key: string): string | null {
    const row = this.db.prepare('SELECT value FROM sync_state WHERE key = ?').get(key) as
      { value: string } | undefined;
    return row?.value ?? null;
  }

  set(key: string, value: string): void {
    this.db
      .prepare(
        `INSERT INTO sync_state (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
      )
      .run(key, value);
  }
}
