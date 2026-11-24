export type LotStatus = 'available' | 'quarantine' | 'expired' | 'destroyed';

export interface StockLot {
  id: string;
  productId: string;
  expiry: string;
  status: LotStatus;
  receivedAt: string;
}

export interface StockMovement {
  id: string;
  productId: string;
  lotId: string;
  qty: number;
  kind: string;
  createdAt: string;
  reversalOf?: string;
}

export interface FefoInput {
  productId: string;
  qty: number;
  lots: StockLot[];
  movements: StockMovement[];
  now: string;
  allowNegativeStock?: boolean;
}

export interface FefoAllocation {
  lotId: string;
  qty: number;
}

export function applyMovement(
  movements: StockMovement[],
  movement: StockMovement,
): StockMovement[] {
  if (!Number.isInteger(movement.qty) || movement.qty === 0) {
    throw new Error('invalid_quantity');
  }
  return [...movements, movement];
}

export function computeLotBalances(movements: StockMovement[]): Record<string, number> {
  const balances: Record<string, number> = {};
  for (const m of movements) {
    if (!Number.isInteger(m.qty) || m.qty === 0) {
      throw new Error('invalid_quantity');
    }
    balances[m.lotId] = (balances[m.lotId] ?? 0) + m.qty;
  }
  return balances;
}

export function computeProductOnHand(movements: StockMovement[], productId: string): number {
  return movements.filter((m) => m.productId === productId).reduce((sum, m) => sum + m.qty, 0);
}

export function allocateFefo(input: FefoInput): FefoAllocation[] {
  if (!Number.isInteger(input.qty) || input.qty <= 0) {
    throw new Error('invalid_quantity');
  }

  const balances = computeLotBalances(
    input.movements.filter((m) => m.productId === input.productId),
  );
  const eligible = input.lots
    .filter((l) => l.productId === input.productId)
    .filter((l) => l.status === 'available')
    .filter((l) => l.expiry >= input.now.slice(0, 10))
    .map((l) => ({ ...l, availableQty: balances[l.id] ?? 0 }))
    .filter((l) => l.availableQty > 0)
    .sort((a, b) => {
      if (a.expiry === b.expiry) {
        return a.receivedAt.localeCompare(b.receivedAt);
      }
      return a.expiry.localeCompare(b.expiry);
    });

  const out: FefoAllocation[] = [];
  let remaining = input.qty;
  for (const lot of eligible) {
    if (remaining <= 0) {
      break;
    }
    const take = Math.min(remaining, lot.availableQty);
    if (take > 0) {
      out.push({ lotId: lot.id, qty: take });
      remaining -= take;
    }
  }

  if (remaining > 0 && input.allowNegativeStock !== true) {
    throw new Error('insufficient_stock');
  }

  if (remaining > 0) {
    out.push({ lotId: 'unallocated', qty: remaining });
  }

  return out;
}
