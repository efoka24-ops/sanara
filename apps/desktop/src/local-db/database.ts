import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import Database from 'better-sqlite3';

export type LocalDatabase = Database.Database;

const MIGRATIONS = fileURLToPath(new URL('./migrations', import.meta.url));

/** Applique les fichiers `*.sql` pas encore passés, dans l'ordre, chacun dans sa transaction. */
export function migrate(db: LocalDatabase, dir: string = MIGRATIONS): void {
  db.exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (name TEXT PRIMARY KEY, applied_at TEXT NOT NULL)',
  );
  const done = db.prepare('SELECT 1 FROM schema_migrations WHERE name = ?');
  for (const name of fs
    .readdirSync(dir)
    .filter((f) => f.endsWith('.sql'))
    .sort()) {
    if (done.get(name)) continue;
    db.transaction(() => {
      db.exec(fs.readFileSync(path.join(dir, name), 'utf8'));
      db.prepare('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)').run(
        name,
        new Date().toISOString(),
      );
    })();
  }
}

/** Ouvre la base du poste. Une vente validée doit survivre à une coupure de courant. */
export function openLocalDatabase(file: string): LocalDatabase {
  const db = new Database(file);
  db.pragma('journal_mode = WAL');
  db.pragma('synchronous = FULL');
  db.pragma('foreign_keys = ON');
  migrate(db);
  return db;
}
