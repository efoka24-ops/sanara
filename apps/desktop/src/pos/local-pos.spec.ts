import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ReferenceProduct, ReferenceSnapshot } from '@sanara/sync';
import { SqliteQueue, SqliteReference, openLocalDatabase, type LocalDatabase } from '../local-db';
import { LocalPos, type PosUser, type SaleInput } from './local-pos';

const CASHIER: PosUser = { id: 'u-cashier', permissions: ['sales.create', 'sales.discount'] };
const PHARMACIST: PosUser = {
  id: 'u-pharma',
  permissions: ['sales.create', 'sales.discount', 'sales.discount_override', 'narcotics.dispense'],
};

const product = (id: string, over: Partial<ReferenceProduct> = {}): ReferenceProduct => ({
  id,
  name: id,
  dci: null,
  form: null,
  strength: null,
  regulatoryStatus: 'free',
  salePrice: 1000,
  vatBp: 1925,
  active: true,
  barcodes: [],
  unassignedQty: 0,
  ...over,
});

const lot = (id: string, productId: string, quantity: number, over = {}) => ({
  id,
  productId,
  lotNumber: id,
  expiry: '2030-01-01',
  status: 'available',
  receivedAt: '2026-11-01 08:00:00.000',
  quantity,
  ...over,
});

const snapshot = (over: Partial<ReferenceSnapshot> = {}): ReferenceSnapshot => ({
  serverTime: '2026-11-26T08:00:00.000Z',
  settings: { maxDiscountBp: 1000, allowNegativeStock: false, saleCounters: { 'C1:2026': 41 } },
  products: [
    product('para'),
    product('amox', { regulatoryStatus: 'prescription_only' }),
    product('retire', { active: false }),
  ],
  lots: [
    lot('L1', 'para', 5),
    lot('perime', 'para', 50, { expiry: '2026-01-01' }),
    lot('bloque', 'para', 50, { status: 'quarantine' }),
    lot('L2', 'amox', 10),
  ],
  ...over,
});

let dir: string;
let db: LocalDatabase;
let queue: SqliteQueue;
let reference: SqliteReference;
let now: Date;
const pos = (user: PosUser = CASHIER) =>
  new LocalPos(db, { deviceId: 'poste-1', user, now: () => now });

const sale = (over: Partial<SaleInput> = {}): SaleInput => ({
  lines: [{ productId: 'para', qty: 2 }],
  payments: [{ method: 'cash', amount: 5000 }],
  ...over,
});
const prescription = { ref: 'ORD-1', prescriber: 'Dr Mballa', patientName: 'Aïcha Bello' };

beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-pos-'));
  db = openLocalDatabase(path.join(dir, 'sanara.db'));
  queue = new SqliteQueue(db);
  reference = new SqliteReference(db);
  reference.replace(snapshot());
  now = new Date('2026-11-26T09:00:00.000Z');
});
afterEach(() => {
  db.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

describe('caisse hors-ligne', () => {
  it('ouvre, vend avec rendu monnaie, clôture, et produit les opérations attendues par le serveur', () => {
    const till = pos();
    const sessionId = till.openCash('c 1', 10000);
    const done = till.sell(sale());
    expect(done).toMatchObject({ number: 'V-C1-2026-00042', total: 2000, change: 3000 });
    expect(done.vatTotal).toBe(323);
    expect(till.currentSession()).toMatchObject({
      id: sessionId,
      station: 'C1',
      expectedCash: 12000,
    });

    const closed = till.closeCash([{ denomination: 1000, qty: 12 }]);
    expect(closed).toEqual({ expected: 12000, counted: 12000, gap: 0 });
    expect(till.currentSession()).toBeNull();

    const ops = queue.pending();
    expect(ops.map((o) => [o.type, o.userId, o.deviceId])).toEqual(
      ['cash.open', 'sale.create', 'cash.close'].map((t) => [t, 'u-cashier', 'poste-1']),
    );
    expect(ops[0]!.payload).toEqual({ sessionId, station: 'C1', openingFloat: 10000 });
    expect(ops[1]!.payload).toEqual({
      saleId: done.saleId,
      sessionId,
      number: 'V-C1-2026-00042',
      lines: [{ productId: 'para', qty: 2, unitPrice: 1000, discountBp: 0, vatBp: 1925 }],
      payments: [{ method: 'cash', amount: 5000 }],
    });
    expect(ops[2]!.payload).toEqual({
      sessionId,
      counts: [{ denomination: 1000, qty: 12 }],
      reason: null,
    });
    // Ordre strict, même quand tout se passe dans la même milliseconde.
    expect(new Set(ops.map((o) => o.createdAt)).size).toBe(3);
  });

  it('exige une caisse ouverte, une seule par personne, et la base synchronisée', () => {
    const till = pos();
    expect(() => till.sell(sale())).toThrow('cash_session_required');
    till.openCash('C1', 0);
    expect(() => till.openCash('C2', 0)).toThrow('cash_session_open');
    db.exec("DELETE FROM sync_state WHERE key = 'reference_settings'");
    expect(() => till.sell(sale())).toThrow('reference_missing');
    expect(queue.pendingCount()).toBe(1);
  });

  it('ne vend que le stock vendable, ventes non synchronisées comprises', () => {
    const till = pos();
    till.openCash('C1', 0);
    expect(till.available('para')).toBe(5);
    till.sell(sale({ lines: [{ productId: 'para', qty: 4 }] }));
    expect(till.available('para')).toBe(1);
    expect(() => till.sell(sale({ lines: [{ productId: 'para', qty: 2 }] }))).toThrow(
      'insufficient_stock',
    );
    expect(() =>
      till.sell(
        sale({
          lines: [
            { productId: 'para', qty: 1 },
            { productId: 'para', qty: 1 },
          ],
        }),
      ),
    ).toThrow('insufficient_stock');
    expect(queue.pendingCount()).toBe(2);
  });

  it('garde la déduction tant que le référentiel ne reflète pas la vente', () => {
    const till = pos();
    till.openCash('C1', 0);
    till.sell(sale());
    queue.acknowledge(queue.pending().map((o) => o.id));
    expect(till.available('para')).toBe(3);
    // Nouvelle copie du serveur, qui compte déjà la vente : plus de double déduction.
    reference.replace(snapshot({ lots: [lot('L1', 'para', 3)] }));
    expect(till.available('para')).toBe(3);

    till.sell(sale({ lines: [{ productId: 'para', qty: 1 }] }));
    reference.replace(snapshot({ lots: [lot('L1', 'para', 3)] }));
    expect(till.available('para'), 'vente pas encore envoyée').toBe(2);
  });

  it('autorise le stock négatif seulement si la pharmacie le permet', () => {
    reference.replace(
      snapshot({ settings: { maxDiscountBp: 1000, allowNegativeStock: true, saleCounters: {} } }),
    );
    const till = pos();
    till.openCash('C1', 0);
    till.sell(
      sale({
        lines: [{ productId: 'para', qty: 8 }],
        payments: [{ method: 'cash', amount: 8000 }],
      }),
    );
    expect(till.available('para')).toBe(-3);
  });

  it('numérote sans reprendre un numéro, y compris après une nouvelle année', () => {
    const till = pos();
    till.openCash('C1', 0);
    const one = { lines: [{ productId: 'para', qty: 1 }] };
    expect(till.sell(sale(one)).number).toBe('V-C1-2026-00042');
    reference.replace(snapshot());
    expect(till.sell(sale(one)).number).toBe('V-C1-2026-00043');
    till.closeCash([], 'Fin d’année');
    now = new Date('2027-01-02T09:00:00.000Z');
    till.openCash('C1', 0);
    expect(till.sell(sale(one)).number).toBe('V-C1-2027-00001');
  });

  it('réserve les produits réglementés à un pharmacien, avec ordonnance', () => {
    const regulated = sale({ lines: [{ productId: 'amox', qty: 1 }] });
    const cashier = pos();
    cashier.openCash('C1', 0);
    expect(() => cashier.sell({ ...regulated, prescription })).toThrow('prescription_required');

    const pharmacist = pos(PHARMACIST);
    pharmacist.openCash('C2', 0);
    expect(() => pharmacist.sell(regulated)).toThrow('prescription_required');
    pharmacist.sell({ ...regulated, prescription });
    expect(queue.pending().at(-1)!.payload).toMatchObject({ prescription });
  });

  it('applique les limites de remise et de règlement du hors-ligne', () => {
    const till = pos();
    till.openCash('C1', 0);
    const line = (discountBp: number) => ({ lines: [{ productId: 'para', qty: 1, discountBp }] });
    expect(() => till.sell(sale(line(2000)))).toThrow('discount_needs_approval');
    expect(till.sell(sale(line(500))).total).toBe(950);
    expect(() =>
      till.sell(sale({ payments: [{ method: 'momo_mtn', amount: 2000, reference: 'x' }] })),
    ).toThrow('payment_offline_forbidden');
    expect(() => till.sell(sale({ payments: [{ method: 'card', amount: 2000 }] }))).toThrow(
      'validation_failed',
    );
    expect(() => till.sell(sale({ payments: [{ method: 'credit', amount: 2000 }] }))).toThrow(
      'validation_failed',
    );
    expect(() => till.sell(sale({ payments: [{ method: 'cash', amount: 100 }] }))).toThrow(
      'payment_insufficient',
    );
    expect(() => pos({ id: 'x', permissions: ['sales.create'] }).openCash('C9', -1)).toThrow(
      'validation_failed',
    );
  });

  it('refuse un produit inconnu ou retiré et une quantité invalide', () => {
    const till = pos();
    till.openCash('C1', 0);
    for (const lines of [
      [{ productId: 'absent', qty: 1 }],
      [{ productId: 'retire', qty: 1 }],
      [{ productId: 'para', qty: 0 }],
      [{ productId: 'para', qty: 1.5 }],
      [],
    ]) {
      expect(() => till.sell(sale({ lines }))).toThrow();
    }
    expect(queue.pendingCount()).toBe(1);
  });

  it('exige un motif quand le comptage diffère', () => {
    const till = pos();
    till.openCash('C1', 5000);
    expect(() => till.closeCash([{ denomination: 1000, qty: 4 }])).toThrow('validation_failed');
    expect(till.closeCash([{ denomination: 1000, qty: 4 }], 'Appoint manquant').gap).toBe(-1000);
  });
});
