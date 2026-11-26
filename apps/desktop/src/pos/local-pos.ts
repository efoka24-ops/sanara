/**
 * T135 : caisse du poste quand le réseau est absent. Chaque action écrit, dans une même transaction,
 * l'état local et l'opération à envoyer. Les refus reprennent ceux du serveur (SalesService::recordOffline)
 * pour qu'une vente acceptée ici le soit aussi à la synchronisation.
 */
import { saleLine, saleNumber, settle, ulid, type PayMethod } from '@sanara/shared';
import { SCHEMA_VERSION, type ReferenceProduct } from '@sanara/sync';
import { SqliteQueue, SqliteReference, SqliteState, type LocalDatabase } from '../local-db';

export interface PosUser {
  id: string;
  permissions: string[];
}
export interface SaleInput {
  lines: { productId: string; qty: number; discountBp?: number }[];
  payments: { method: PayMethod; amount: number; reference?: string }[];
  customerName?: string;
  prescription?: { ref: string; prescriber: string; patientName: string };
}
export interface LocalSale {
  saleId: string;
  number: string;
  total: number;
  vatTotal: number;
  change: number;
}
export interface LocalSession {
  id: string;
  station: string;
  openingFloat: number;
  expectedCash: number;
  /** Ouverte un jour précédent : à clôturer avant de vendre. */
  stale: boolean;
}
/** Vente conservée sur le poste pour la réimpression et le rapport Z. */
export interface SaleRecord {
  number: string;
  createdAt: string;
  total: number;
  vatTotal: number;
  discountTotal: number;
  change: number;
  lines: { name: string; qty: number; unitPrice: number; discountBp: number; total: number }[];
  payments: { method: string; amount: number; tendered: number }[];
  prescription?: { ref: string; prescriber: string };
}
export interface SessionReport {
  id: string;
  station: string;
  openedAt: string;
  closedAt: string | null;
  openingFloat: number;
  expectedCash: number;
  counted: number | null;
  gapReason: string | null;
  salesCount: number;
  salesTotal: number;
  vatTotal: number;
  byMethod: Record<string, number>;
}
export interface HeldCart {
  id: string;
  label: string;
  cart: unknown;
  created_at: string;
}

/** Code d'erreur identique à ceux de l'API, traduit par l'interface. */
export class PosError extends Error {
  constructor(
    public readonly code: string,
    public readonly details: Record<string, unknown> = {},
  ) {
    super(code);
  }
}

const invalid = (field: string) => new PosError('validation_failed', { field });
const filled = (s: string | undefined) => (s ?? '').trim() !== '';

export class LocalPos {
  private readonly queue: SqliteQueue;
  private readonly reference: SqliteReference;
  private readonly state: SqliteState;

  constructor(
    private readonly db: LocalDatabase,
    private readonly context: {
      deviceId: string;
      user: PosUser;
      now?: () => Date;
      /** Le Mobile Money exige le réseau. */
      online?: () => boolean;
    },
  ) {
    this.queue = new SqliteQueue(db);
    this.reference = new SqliteReference(db);
    this.state = new SqliteState(db);
  }

  currentSession(): LocalSession | null {
    const row = this.db
      .prepare(
        'SELECT id, station, opening_float, cash_total, opened_at FROM pos_sessions WHERE user_id = ? AND closed_at IS NULL',
      )
      .get(this.context.user.id) as
      | {
          id: string;
          station: string;
          opening_float: number;
          cash_total: number;
          opened_at: string;
        }
      | undefined;
    return row
      ? {
          id: row.id,
          station: row.station,
          openingFloat: row.opening_float,
          expectedCash: row.opening_float + row.cash_total,
          stale: row.opened_at.slice(0, 10) < this.now().toISOString().slice(0, 10),
        }
      : null;
  }

  openCash(station: string, openingFloat: number): string {
    if (!Number.isInteger(openingFloat) || openingFloat < 0) throw invalid('openingFloat');
    return this.db.transaction(() => {
      if (this.currentSession()) throw new PosError('cash_session_open');
      const id = ulid();
      // Même normalisation que CashService::station côté serveur.
      const name = saleNumber(station, 0, 0).split('-')[1]!;
      this.db
        .prepare(
          'INSERT INTO pos_sessions (id, user_id, station, opening_float, opened_at) VALUES (?, ?, ?, ?, ?)',
        )
        .run(id, this.context.user.id, name, openingFloat, this.now().toISOString());
      this.record('cash.open', { sessionId: id, station: name, openingFloat });
      return id;
    })();
  }

  /** Stock vendable : lots disponibles et non périmés, moins les ventes pas encore reflétées. */
  available(productId: string): number {
    const today = this.now().toISOString().slice(0, 10);
    const stock = this.reference
      .lots(productId)
      .filter((l) => l.status === 'available' && l.expiry >= today)
      .reduce((sum, l) => sum + l.quantity, 0);
    const unassigned = this.reference.product(productId)?.unassignedQty ?? 0;
    const sold = this.db
      .prepare('SELECT COALESCE(SUM(qty), 0) AS n FROM pos_consumption WHERE product_id = ?')
      .get(productId) as { n: number };
    return stock + unassigned - sold.n;
  }

  sell(input: SaleInput): LocalSale {
    return this.db.transaction(() => {
      const session = this.currentSession();
      if (!session) throw new PosError('cash_session_required');
      if (session.stale) throw new PosError('cash_session_stale');
      const settings = this.reference.settings();
      if (!settings) throw new PosError('reference_missing');
      if (input.lines.length === 0) throw invalid('lines');

      const can = (p: string) => this.context.user.permissions.includes(p);
      const wanted = new Map<string, number>();
      const lines = input.lines.map(({ productId, qty, discountBp = 0 }) => {
        if (!Number.isInteger(qty) || qty <= 0) throw invalid('lines');
        if (!Number.isInteger(discountBp) || discountBp < 0 || discountBp > 10000) {
          throw invalid('lines');
        }
        const product: ReferenceProduct | null = this.reference.product(productId);
        if (!product || !product.active) throw new PosError('not_found', { productId });
        if (discountBp > 0 && !can('sales.discount')) throw new PosError('forbidden');
        if (discountBp > settings.maxDiscountBp && !can('sales.discount_override')) {
          throw new PosError('discount_needs_approval');
        }
        wanted.set(productId, (wanted.get(productId) ?? 0) + qty);
        return { product, qty, discountBp };
      });

      const regulated = lines.some((l) => l.product.regulatoryStatus !== 'free');
      const p = input.prescription;
      if (
        regulated &&
        !(
          can('narcotics.dispense') &&
          p &&
          filled(p.ref) &&
          filled(p.prescriber) &&
          filled(p.patientName)
        )
      ) {
        throw new PosError('prescription_required');
      }
      if (!settings.allowNegativeStock) {
        for (const [productId, qty] of wanted) {
          const missing = qty - this.available(productId);
          if (missing > 0) throw new PosError('insufficient_stock', { productId, missing });
        }
      }

      for (const payment of input.payments) {
        const mobile = payment.method.startsWith('momo_');
        if (mobile && this.context.online?.() !== true) {
          throw new PosError('payment_offline_forbidden');
        }
        if ((mobile || payment.method === 'card') && !filled(payment.reference)) {
          throw invalid('payments.reference');
        }
        if (payment.method === 'credit' && !filled(input.customerName)) {
          throw invalid('customerName');
        }
      }
      const amounts = lines.map((l) =>
        saleLine(l.qty, l.product.salePrice, l.discountBp, l.product.vatBp),
      );
      const total = amounts.reduce((s, a) => s + a.total, 0);
      const settlement = settle(total, input.payments);
      if (!settlement) throw new PosError('payment_insufficient', { total });

      const saleId = ulid();
      const number = this.nextNumber(session.station, settings.saleCounters);
      const operationId = this.record('sale.create', {
        saleId,
        sessionId: session.id,
        number,
        lines: lines.map((l) => ({
          productId: l.product.id,
          qty: l.qty,
          unitPrice: l.product.salePrice,
          discountBp: l.discountBp,
          vatBp: l.product.vatBp,
        })),
        payments: input.payments,
        ...(filled(input.customerName) ? { customerName: input.customerName } : {}),
        ...(regulated ? { prescription: p } : {}),
      });
      const consume = this.db.prepare(
        'INSERT INTO pos_consumption (operation_id, product_id, qty) VALUES (?, ?, ?)',
      );
      for (const [productId, qty] of wanted) consume.run(operationId, productId, qty);
      const cash = settlement.applied
        .filter((a) => a.method === 'cash')
        .reduce((s, a) => s + a.amount, 0);
      this.db
        .prepare('UPDATE pos_sessions SET cash_total = cash_total + ? WHERE id = ?')
        .run(cash, session.id);

      const vatTotal = amounts.reduce((s, a) => s + a.vat, 0);
      const record: SaleRecord = {
        number,
        createdAt: this.now().toISOString(),
        total,
        vatTotal,
        discountTotal: amounts.reduce((s, a) => s + a.discount, 0),
        change: settlement.change,
        lines: lines.map((l, i) => ({
          name: l.product.name,
          qty: l.qty,
          unitPrice: l.product.salePrice,
          discountBp: l.discountBp,
          total: amounts[i]!.total,
        })),
        payments: settlement.applied,
        ...(regulated && p ? { prescription: { ref: p.ref, prescriber: p.prescriber } } : {}),
      };
      this.db
        .prepare('INSERT INTO pos_sales (id, session_id, data_json) VALUES (?, ?, ?)')
        .run(saleId, session.id, JSON.stringify(record));

      return { saleId, number, total, vatTotal, change: settlement.change };
    })();
  }

  closeCash(
    counts: { denomination: number; qty: number }[],
    reason?: string,
  ): { expected: number; counted: number; gap: number } {
    return this.db.transaction(() => {
      const session = this.currentSession();
      if (!session) throw new PosError('cash_session_required');
      let counted = 0;
      for (const c of counts) {
        if (!Number.isInteger(c.denomination) || !Number.isInteger(c.qty)) throw invalid('counts');
        if (c.denomination <= 0 || c.qty < 0) throw invalid('counts');
        counted += c.denomination * c.qty;
      }
      const gap = counted - session.expectedCash;
      if (gap !== 0 && !filled(reason)) throw invalid('reason');
      this.db
        .prepare('UPDATE pos_sessions SET closed_at = ?, counted = ?, gap_reason = ? WHERE id = ?')
        .run(this.now().toISOString(), counted, filled(reason) ? reason : null, session.id);
      this.record('cash.close', {
        sessionId: session.id,
        counts,
        reason: filled(reason) ? reason : null,
      });
      return { expected: session.expectedCash, counted, gap };
    })();
  }

  /** Rapport d'une session de ce poste tenue par la personne connectée. */
  report(sessionId: string): SessionReport | null {
    const s = this.db
      .prepare('SELECT * FROM pos_sessions WHERE id = ? AND user_id = ?')
      .get(sessionId, this.context.user.id) as
      | {
          id: string;
          station: string;
          opening_float: number;
          cash_total: number;
          opened_at: string;
          closed_at: string | null;
          counted: number | null;
          gap_reason: string | null;
        }
      | undefined;
    if (!s) return null;
    const sales = (
      this.db.prepare('SELECT data_json FROM pos_sales WHERE session_id = ?').all(sessionId) as {
        data_json: string;
      }[]
    ).map((r) => JSON.parse(r.data_json) as SaleRecord);
    const byMethod: Record<string, number> = {};
    for (const sale of sales) {
      for (const p of sale.payments) byMethod[p.method] = (byMethod[p.method] ?? 0) + p.amount;
    }
    return {
      id: s.id,
      station: s.station,
      openedAt: s.opened_at,
      closedAt: s.closed_at,
      openingFloat: s.opening_float,
      expectedCash: s.opening_float + s.cash_total,
      counted: s.counted,
      gapReason: s.gap_reason,
      salesCount: sales.length,
      salesTotal: sales.reduce((n, x) => n + x.total, 0),
      vatTotal: sales.reduce((n, x) => n + x.vatTotal, 0),
      byMethod,
    };
  }

  /** Compte l'impression d'un ticket : la deuxième est un duplicata. */
  print(saleId: string): { sale: SaleRecord; duplicate: boolean } | null {
    const row = this.db
      .prepare('SELECT data_json, printed_count FROM pos_sales WHERE id = ?')
      .get(saleId) as { data_json: string; printed_count: number } | undefined;
    if (!row) return null;
    this.db
      .prepare('UPDATE pos_sales SET printed_count = printed_count + 1 WHERE id = ?')
      .run(saleId);
    return { sale: JSON.parse(row.data_json) as SaleRecord, duplicate: row.printed_count > 0 };
  }

  held(): HeldCart[] {
    return (
      this.db
        .prepare('SELECT * FROM pos_held WHERE user_id = ? ORDER BY created_at, id')
        .all(this.context.user.id) as {
        id: string;
        label: string;
        cart_json: string;
        created_at: string;
      }[]
    ).map((h) => ({
      id: h.id,
      label: h.label,
      cart: JSON.parse(h.cart_json) as unknown,
      created_at: h.created_at,
    }));
  }

  hold(label: string, cart: unknown): string {
    const text = JSON.stringify(cart ?? null);
    if (typeof cart !== 'object' || cart === null || text.length > 20_000) throw invalid('cart');
    const id = ulid();
    this.db
      .prepare(
        'INSERT INTO pos_held (id, user_id, label, cart_json, created_at) VALUES (?, ?, ?, ?, ?)',
      )
      .run(
        id,
        this.context.user.id,
        (label.trim() || 'Panier').slice(0, 60),
        text,
        this.now().toISOString(),
      );
    return id;
  }

  resume(id: string): void {
    this.db
      .prepare('DELETE FROM pos_held WHERE id = ? AND user_id = ?')
      .run(id, this.context.user.id);
  }

  private now(): Date {
    return this.context.now?.() ?? new Date();
  }

  /** Repart après le plus grand des numéros connus du poste ou du serveur. */
  private nextNumber(station: string, server: Record<string, number>): string {
    const year = this.now().getUTCFullYear();
    const scope = `${station}:${year}`;
    const local = this.db.prepare('SELECT value FROM pos_counters WHERE scope = ?').get(scope) as
      { value: number } | undefined;
    const value = Math.max(local?.value ?? 0, server[scope] ?? 0) + 1;
    this.db
      .prepare(
        `INSERT INTO pos_counters (scope, value) VALUES (?, ?)
         ON CONFLICT(scope) DO UPDATE SET value = excluded.value`,
      )
      .run(scope, value);
    return saleNumber(station, year, value);
  }

  /** Ajoute l'opération à la file, avec un horodatage strictement croissant sur le poste. */
  private record(type: string, payload: unknown): string {
    const last = this.state.get('last_operation_at');
    let at = this.now().toISOString();
    if (last !== null && at <= last && Date.parse(last) - Date.parse(at) < 1000) {
      at = new Date(Date.parse(last) + 1).toISOString();
    }
    this.state.set('last_operation_at', at);
    const id = ulid();
    this.queue.append({
      id,
      deviceId: this.context.deviceId,
      userId: this.context.user.id,
      type,
      payload,
      schemaVersion: SCHEMA_VERSION,
      createdAt: at,
    });
    return id;
  }
}
