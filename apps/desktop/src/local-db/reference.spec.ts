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
