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
    private readonly context: { deviceId: string; user: PosUser; now?: () => Date },
  ) {
    this.queue = new SqliteQueue(db);
    this.reference = new SqliteReference(db);
    this.state = new SqliteState(db);
  }

  currentSession(): LocalSession | null {
    const row = this.db
      .prepare(
        'SELECT id, station, opening_float, cash_total FROM pos_sessions WHERE user_id = ? AND closed_at IS NULL',
      )
      .get(this.context.user.id) as
      { id: string; station: string; opening_float: number; cash_total: number } | undefined;
    return row
      ? {
          id: row.id,
          station: row.station,
          openingFloat: row.opening_float,
          expectedCash: row.opening_float + row.cash_total,
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
          'INSERT INTO pos_sessions (id, user_id, station, opening_float) VALUES (?, ?, ?, ?)',
        )
        .run(id, this.context.user.id, name, openingFloat);
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
        if (payment.method.startsWith('momo_')) throw new PosError('payment_offline_forbidden');
        if (payment.method === 'card' && !filled(payment.reference)) {
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

      return {
        saleId,
        number,
        total,
        vatTotal: amounts.reduce((s, a) => s + a.vat, 0),
        change: settlement.change,
      };
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
        .prepare('UPDATE pos_sessions SET closed_at = ? WHERE id = ?')
        .run(this.now().toISOString(), session.id);
      this.record('cash.close', {
        sessionId: session.id,
        counts,
        reason: filled(reason) ? reason : null,
      });
      return { expected: session.expectedCash, counted, gap };
    })();
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
