import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { SCHEMA_VERSION, applyBatch, type SyncOperation } from '@sanara/sync';
import { SqliteApplied, SqliteQueue, SqliteState, migrate, openLocalDatabase } from '.';

const op = (id: string, over: Partial<SyncOperation> = {}): SyncOperation => ({
  id,
  deviceId: 'poste-1',
  type: 'sale.create',
  payload: { id, libellé: 'Paracétamol d’essai', total: 1500 },
  schemaVersion: SCHEMA_VERSION,
  createdAt: '2026-11-26T10:00:00.000Z',
  ...over,
});

let dir: string;
const open: ReturnType<typeof openLocalDatabase>[] = [];
const database = () => {
  const db = openLocalDatabase(path.join(dir, 'sanara.db'));
  open.push(db);
  return db;
};

beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-localdb-'));
});
afterEach(() => {
  open.splice(0).forEach((db) => db.close());
  fs.rmSync(dir, { recursive: true, force: true });
});

describe('base locale du poste', () => {
  it('écrit de façon durable (WAL, synchronisation complète)', () => {
    const db = database();
    expect(db.pragma('journal_mode', { simple: true })).toBe('wal');
    expect(db.pragma('synchronous', { simple: true })).toBe(2);
  });

  it('applique chaque migration une seule fois', () => {
    database().close();
    const db = database();
    expect(db.prepare('SELECT name FROM schema_migrations').all()).toEqual([
      { name: '0001_init.sql' },
    ]);
  });

  it('annule entièrement une migration qui échoue', () => {
    const db = database();
    const bad = path.join(dir, 'migrations');
    fs.mkdirSync(bad);
    fs.writeFileSync(
      path.join(bad, '0002_casse.sql'),
      'CREATE TABLE a_moitie (id TEXT);\nINSERT INTO absente VALUES (1);',
    );
    expect(() => migrate(db, bad)).toThrow(/absente/);
    expect(db.prepare("SELECT name FROM sqlite_master WHERE name = 'a_moitie'").all()).toEqual([]);
    expect(db.prepare('SELECT COUNT(*) AS n FROM schema_migrations').get()).toEqual({ n: 1 });
  });
});

describe('file d’opérations persistante', () => {
  it('conserve les opérations en attente après fermeture, dans l’ordre', () => {
    const first = database();
    const queue = new SqliteQueue(first);
    queue.append(op('01B', { createdAt: '2026-11-26T10:01:00.000Z' }));
    queue.append(op('01C'));
    queue.append(op('01A'));
    first.close();

    const reopened = new SqliteQueue(database());
    expect(reopened.pending().map((o) => o.id)).toEqual(['01A', '01C', '01B']);
    expect(reopened.pending()[0]).toEqual(op('01A'));
    expect(reopened.pendingCount()).toBe(3);
  });

  it('ignore une opération déjà connue, même déjà acquittée', () => {
    const queue = new SqliteQueue(database());
    queue.append(op('01A'));
    queue.append(op('01A', { payload: { total: 9 } }));
    expect(queue.pending()).toEqual([op('01A')]);

    queue.acknowledge(['01A']);
    queue.append(op('01A'));
    expect(queue.pending()).toEqual([]);
    expect(queue.pendingCount()).toBe(0);
  });

  it('refuse une opération incomplète au lieu de la perdre en silence', () => {
    const queue = new SqliteQueue(database());
    expect(() => queue.append(op('01A', { payload: undefined }))).toThrow();
    expect(queue.pendingCount()).toBe(0);
  });

  it('retient les opérations déjà appliquées entre deux démarrages', async () => {
    const seen: string[] = [];
    const handler = async (o: SyncOperation) => void seen.push(o.id);
    const first = database();
    await applyBatch([op('01A')], new SqliteApplied(first), handler);
    first.close();

    const result = await applyBatch([op('01A'), op('01B')], new SqliteApplied(database()), handler);
    expect(result.duplicates).toEqual(['01A']);
    expect(seen).toEqual(['01A', '01B']);
  });

  it('garde le curseur de synchronisation', () => {
    const first = database();
    new SqliteState(first).set('pull_cursor', '41');
    new SqliteState(first).set('pull_cursor', '42');
    first.close();
    const state = new SqliteState(database());
    expect(state.get('pull_cursor')).toBe('42');
    expect(state.get('inconnu')).toBeNull();
  });
});
