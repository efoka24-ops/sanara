import type {
  ReferenceCustomer,
  ReferenceLot,
  ReferenceProduct,
  ReferenceSettings,
  ReferenceSink,
  ReferenceSnapshot,
} from '@sanara/sync';
import type { LocalDatabase } from './database';
import { SqliteState } from './stores';

/** Minuscules sans accents : la recherche au comptoir ne doit pas dépendre de la saisie. */
const fold = (s: string) =>
  s
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .toLowerCase();

/** Au-delà, une copie complète est redemandée : filet contre un changement manqué. */
const FULL_EVERY_MS = 24 * 3600_000;

export class SqliteReference implements ReferenceSink {
  private readonly state: SqliteState;
  constructor(
    private readonly db: LocalDatabase,
    private readonly now: () => Date = () => new Date(),
  ) {
    this.state = new SqliteState(db);
  }

  since(): string | undefined {
    const full = this.state.get('reference_full_at');
    if (!full || this.settings() === null) return undefined;
    if (this.now().getTime() - Date.parse(full) > FULL_EVERY_MS) return undefined;
    return this.syncedAt() ?? undefined;
  }

  replace(snapshot: ReferenceSnapshot): void {
    this.db.transaction(() => {
      this.db.exec(
        'DELETE FROM ref_products; DELETE FROM ref_barcodes; DELETE FROM ref_lots; DELETE FROM ref_customers;',
      );
      this.write(snapshot, 'INSERT');
      this.state.set('reference_full_at', this.now().toISOString());
    })();
  }

  merge(snapshot: ReferenceSnapshot): void {
    this.db.transaction(() => {
      // Tout retirer d'abord : un code-barres peut être passé d'un produit reçu à un autre.
      const forget = ['ref_barcodes', 'ref_lots'].map((table) =>
        this.db.prepare(`DELETE FROM ${table} WHERE product_id = ?`),
      );
      for (const p of snapshot.products) for (const statement of forget) statement.run(p.id);
      this.write(snapshot, 'INSERT OR REPLACE');
    })();
  }

  private write(snapshot: ReferenceSnapshot, verb: 'INSERT' | 'INSERT OR REPLACE'): void {
    const product = this.db.prepare(
      `${verb} INTO ref_products (id, search_text, active, data_json) VALUES (?, ?, ?, ?)`,
    );
    const barcode = this.db.prepare(`${verb} INTO ref_barcodes (code, product_id) VALUES (?, ?)`);
    for (const p of snapshot.products) {
      product.run(p.id, fold(`${p.name} ${p.dci ?? ''}`), p.active ? 1 : 0, JSON.stringify(p));
      for (const code of p.barcodes) barcode.run(code, p.id);
    }
    const lot = this.db.prepare(
      `${verb} INTO ref_lots (id, product_id, expiry, received_at, data_json) VALUES (?, ?, ?, ?, ?)`,
    );
    for (const l of snapshot.lots) {
      lot.run(l.id, l.productId, l.expiry, l.receivedAt, JSON.stringify(l));
    }
    const customer = this.db.prepare(
      `${verb} INTO ref_customers (id, search_text, data_json) VALUES (?, ?, ?)`,
    );
    for (const c of snapshot.customers) {
      customer.run(c.id, fold(`${c.name} ${c.phone ?? ''}`), JSON.stringify(c));
    }
    // La copie reçue compte déjà les ventes acquittées : on cesse de les déduire.
    for (const table of ['pos_consumption', 'pos_credit']) {
      this.db.exec(
        `DELETE FROM ${table} WHERE operation_id IN (SELECT id FROM operations WHERE acked_at IS NOT NULL)`,
      );
    }
    this.state.set('reference_settings', JSON.stringify(snapshot.settings));
    this.state.set('reference_synced_at', snapshot.serverTime);
  }

  customer(id: string): ReferenceCustomer | null {
    return (
      this.parse<ReferenceCustomer>(
        this.db.prepare('SELECT data_json FROM ref_customers WHERE id = ?').all(id),
      )[0] ?? null
    );
  }

  /** Comptes dont le nom ou le téléphone contient la saisie ; tous si elle est vide. */
  searchCustomers(query: string, limit = 50): ReferenceCustomer[] {
    return this.parse<ReferenceCustomer>(
      this.db
        .prepare(
          'SELECT data_json FROM ref_customers WHERE instr(search_text, ?) > 0 ORDER BY search_text LIMIT ?',
        )
        .all(fold(query.trim()), limit),
    );
  }

  settings(): ReferenceSettings | null {
    const raw = this.state.get('reference_settings');
    return raw === null ? null : (JSON.parse(raw) as ReferenceSettings);
  }

  syncedAt(): string | null {
    return this.state.get('reference_synced_at');
  }

  /** Produits actifs dont le nom ou la DCI contient la saisie, ou dont c'est le code-barres. */
  search(query: string, limit = 20): ReferenceProduct[] {
    const text = fold(query.trim());
    if (text === '') return [];
    return this.parse<ReferenceProduct>(
      this.db
        .prepare(
          `SELECT data_json FROM ref_products
           WHERE active = 1 AND (instr(search_text, ?) > 0
             OR id IN (SELECT product_id FROM ref_barcodes WHERE code = ?))
           ORDER BY search_text LIMIT ?`,
        )
        .all(text, query.trim(), limit),
    );
  }

  byBarcode(code: string): ReferenceProduct | null {
    return (
      this.parse<ReferenceProduct>(
        this.db
          .prepare(
            `SELECT p.data_json FROM ref_barcodes b JOIN ref_products p ON p.id = b.product_id
             WHERE b.code = ? AND p.active = 1`,
          )
          .all(code),
      )[0] ?? null
    );
  }

  product(id: string): ReferenceProduct | null {
    return (
      this.parse<ReferenceProduct>(
        this.db.prepare('SELECT data_json FROM ref_products WHERE id = ?').all(id),
      )[0] ?? null
    );
  }

  lots(productId: string): ReferenceLot[] {
    return this.parse<ReferenceLot>(
      this.db
        .prepare('SELECT data_json FROM ref_lots WHERE product_id = ? ORDER BY expiry, received_at')
        .all(productId),
    );
  }

  private parse<T>(rows: unknown[]): T[] {
    return (rows as { data_json: string }[]).map((r) => JSON.parse(r.data_json) as T);
  }
}
