import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import type { ReferenceProduct, ReferenceSnapshot } from '@sanara/sync';
import { SqliteReference, openLocalDatabase, type LocalDatabase } from '.';

const product = (id: string, name: string, over: Partial<ReferenceProduct> = {}) => ({
  id,
  name,
  dci: null,
  form: null,
  strength: null,
  regulatoryStatus: 'free',
  salePrice: 1500,
  vatBp: 1925,
  active: true,
  barcodes: [],
  unassignedQty: 0,
  ...over,
});

const snapshot = (over: Partial<ReferenceSnapshot> = {}): ReferenceSnapshot => ({
  full: true,
  customers: [],
  serverTime: '2026-11-26T18:00:00.000Z',
  settings: { maxDiscountBp: 800, allowNegativeStock: true, saleCounters: {} },
  products: [
    product('P1', 'Paracétamol 500', { dci: 'paracétamol', barcodes: ['340001', '340002'] }),
    product('P2', 'Amoxicilline', { regulatoryStatus: 'prescription_only' }),
    product('P3', 'Retiré 100%', { active: false }),
  ],
  lots: [
    {
      id: 'L1',
      productId: 'P1',
      lotNumber: 'A1',
      expiry: '2030-01-01',
      status: 'available',
      receivedAt: '2026-11-01 08:00:00.000',
      quantity: 12,
    },
  ],
  ...over,
});

let dir: string;
let db: LocalDatabase;
let reference: SqliteReference;
beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-ref-'));
  db = openLocalDatabase(path.join(dir, 'sanara.db'));
  reference = new SqliteReference(db);
});
afterEach(() => {
  db.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

describe('référentiel hors-ligne', () => {
  it('est vide avant la première synchronisation', () => {
    expect(reference.settings()).toBeNull();
    expect(reference.search('para')).toEqual([]);
  });

  it('retrouve un produit actif par nom, DCI ou code-barres, sans tenir compte des accents', () => {
    reference.replace(snapshot());
    expect(reference.search('PARACETAMOL').map((p) => p.id)).toEqual(['P1']);
    expect(reference.search('340002').map((p) => p.id)).toEqual(['P1']);
    expect(reference.search('retiré')).toEqual([]);
    expect(reference.search('%')).toEqual([]);
    expect(reference.search('  ')).toEqual([]);
    expect(reference.byBarcode('340001')).toEqual(snapshot().products[0]);
    expect(reference.byBarcode('999')).toBeNull();
  });

  it('rend les lots et les réglages', () => {
    reference.replace(snapshot());
    expect(reference.lots('P1')).toEqual(snapshot().lots);
    expect(reference.settings()).toEqual({
      maxDiscountBp: 800,
      allowNegativeStock: true,
      saleCounters: {},
    });
    expect(reference.syncedAt()).toBe('2026-11-26T18:00:00.000Z');
  });

  it('est remplacé entièrement, jamais à moitié', () => {
    reference.replace(snapshot());
    const duplicated = snapshot({ products: [product('P9', 'Neuf'), product('P9', 'Doublon')] });
    expect(() => reference.replace(duplicated)).toThrow();
    expect(reference.search('para')).toHaveLength(1);

    reference.replace(snapshot({ products: [product('P9', 'Neuf')], lots: [] }));
    expect(reference.search('para')).toEqual([]);
    expect(reference.lots('P1')).toEqual([]);
    expect(reference.search('neuf').map((p) => p.id)).toEqual(['P9']);
  });
});

describe('fusion des changements et clients', () => {
  const awa = {
    id: 'C1',
    name: 'Awa Béllo',
    phone: '690',
    creditLimit: 5000,
    balance: 0,
    active: true,
  };

  it('ne touche qu’aux produits reçus, code-barres déplacé compris', () => {
    reference.replace(snapshot({ customers: [awa] }));
    reference.merge(
      snapshot({
        full: false,
        serverTime: '2026-11-27T10:00:00.000Z',
        products: [
          product('P2', 'Amoxicilline', { salePrice: 999, barcodes: ['340001'] }),
          product('P1', 'Paracétamol 500', { barcodes: [] }),
        ],
        lots: [],
        customers: [{ ...awa, balance: 700 }],
      }),
    );
    expect(reference.byBarcode('340001')?.id).toBe('P2');
    expect(reference.byBarcode('340002')).toBeNull();
    expect(reference.product('P2')?.salePrice).toBe(999);
    expect(reference.product('P3')).not.toBeNull();
    expect(reference.lots('P1')).toEqual([]);
    expect(reference.customer('C1')?.balance).toBe(700);
    expect(reference.searchCustomers('bello')).toHaveLength(1);
    expect(reference.searchCustomers('69')).toHaveLength(1);
  });

  it('redemande tout après un jour, ou sans copie', () => {
    let now = new Date('2026-11-26T08:00:00.000Z');
    const timed = new SqliteReference(db, () => now);
    expect(timed.since()).toBeUndefined();
    timed.replace(snapshot({ serverTime: '2026-11-26T08:00:00.000Z' }));
    expect(timed.since()).toBe('2026-11-26T08:00:00.000Z');
    now = new Date('2026-11-26T20:00:00.000Z');
    timed.merge(snapshot({ full: false, serverTime: '2026-11-26T20:00:00.000Z' }));
    expect(timed.since()).toBe('2026-11-26T20:00:00.000Z');
    now = new Date('2026-11-27T08:00:01.000Z');
    expect(timed.since()).toBeUndefined();
  });
});
