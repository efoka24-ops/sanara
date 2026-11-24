import { describe, expect, it } from 'vitest';
import {
  applyMovement,
  computeLotBalances,
  computeProductOnHand,
  type StockLot,
  type StockMovement,
  allocateFefo,
} from './index';

const lot = (p: Partial<StockLot>): StockLot => ({
  id: p.id ?? 'lot-1',
  productId: p.productId ?? 'prod-1',
  expiry: p.expiry ?? '2026-12-31',
  status: p.status ?? 'available',
  receivedAt: p.receivedAt ?? '2026-01-01 10:00:00.000',
});

const mv = (p: Partial<StockMovement>): StockMovement => ({
  id: p.id ?? 'mov-1',
  productId: p.productId ?? 'prod-1',
  lotId: p.lotId ?? 'lot-1',
  qty: p.qty ?? 1,
  kind: p.kind ?? 'receipt',
  createdAt: p.createdAt ?? '2026-01-01 10:00:00.000',
});

describe('stock FEFO and ledger', () => {
  it('allocates FEFO across lots with earliest expiry first', () => {
    const lots = [lot({ id: 'l1', expiry: '2026-08-30' }), lot({ id: 'l2', expiry: '2026-07-30' })];
    const movements = [
      mv({ id: 'm1', lotId: 'l1', qty: 10 }),
      mv({ id: 'm2', lotId: 'l2', qty: 6 }),
    ];

    const out = allocateFefo({ productId: 'prod-1', qty: 12, lots, movements, now: '2026-06-01' });
    expect(out).toEqual([
      { lotId: 'l2', qty: 6 },
      { lotId: 'l1', qty: 6 },
    ]);
  });

  it('ignores expired and quarantined lots', () => {
    const lots = [
      lot({ id: 'expired', expiry: '2026-01-01' }),
      lot({ id: 'quarantine', expiry: '2026-08-01', status: 'quarantine' }),
      lot({ id: 'ok', expiry: '2026-09-01' }),
    ];
    const movements = [
      mv({ id: 'm1', lotId: 'expired', qty: 10 }),
      mv({ id: 'm2', lotId: 'quarantine', qty: 10 }),
      mv({ id: 'm3', lotId: 'ok', qty: 10 }),
    ];
    const out = allocateFefo({ productId: 'prod-1', qty: 7, lots, movements, now: '2026-06-01' });
    expect(out).toEqual([{ lotId: 'ok', qty: 7 }]);
  });

  it('rejects insufficient stock when negative stock is disabled', () => {
    const lots = [lot({ id: 'l1', expiry: '2026-08-30' })];
    const movements = [mv({ id: 'm1', lotId: 'l1', qty: 2 })];
    expect(() =>
      allocateFefo({
        productId: 'prod-1',
        qty: 3,
        lots,
        movements,
        now: '2026-06-01',
        allowNegativeStock: false,
      }),
    ).toThrow('insufficient_stock');
  });

  it('supports correction by inverse movement (never mutate history)', () => {
    const moves: StockMovement[] = [
      mv({ id: 'm1', qty: 20 }),
      mv({ id: 'm2', qty: -5, kind: 'sale_out' }),
      mv({ id: 'm3', qty: 5, kind: 'correction', reversalOf: 'm2' }),
    ];

    expect(computeLotBalances(moves)).toEqual({ 'lot-1': 20 });
    expect(computeProductOnHand(moves, 'prod-1')).toBe(20);
  });

  it('validates movement quantities are integers and non-zero', () => {
    expect(() => applyMovement([], mv({ qty: 0 }))).toThrow('invalid_quantity');
    expect(() => applyMovement([], mv({ qty: 1.5 }))).toThrow('invalid_quantity');
  });
});
