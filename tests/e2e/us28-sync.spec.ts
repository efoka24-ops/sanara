import { expect, test, type APIRequestContext } from '@playwright/test';
import { ulid } from '../../packages/shared/src/ids';
import {
  LocalQueue,
  SCHEMA_VERSION,
  createHttpTransport,
  pullReference,
  pushPending,
  type SyncTransport,
} from '../../packages/sync/src';
import { DEMO_PW, apiAs } from './helpers';

const API = 'http://127.0.0.1:8081';
const stamp = Date.now().toString(36);
const SALES = 20;
const FLOAT = 10000;
const PRICE = 500;
const STOCK = 30;

/** Un poste resté hors-ligne 8 heures : ouverture, vingt ventes, clôture. */
async function offlineStation(
  request: APIRequestContext,
  email: string,
  station: string,
  product: string,
) {
  const login = await request.post(`${API}/auth/login`, { data: { email, password: DEMO_PW } });
  const session = (await login.json()) as { access: string; user: { id: string } };
  const queue = new LocalQueue();
  const identity = { deviceId: `poste-${station}`, userId: session.user.id };
  const start = Date.now() - 8 * 3600_000;
  const add = (type: string, minute: number, payload: unknown) =>
    queue.append({
      id: ulid(),
      ...identity,
      type,
      payload,
      schemaVersion: SCHEMA_VERSION,
      createdAt: new Date(start + minute * 60_000).toISOString(),
    });

  const sessionId = ulid();
  add('cash.open', 0, { sessionId, station, openingFloat: FLOAT });
  for (let n = 1; n <= SALES; n++) {
    add('sale.create', n * 20, {
      saleId: ulid(),
      sessionId,
      number: `V-${station}-${new Date().getUTCFullYear()}-${String(n).padStart(5, '0')}`,
      lines: [{ productId: product, qty: 1, unitPrice: PRICE, discountBp: 0, vatBp: 0 }],
      payments: [{ method: 'cash', amount: PRICE }],
    });
  }
  add('cash.close', 470, {
    sessionId,
    counts: [{ denomination: 500, qty: (FLOAT + SALES * PRICE) / 500 }],
    reason: null,
  });

  const transport = createHttpTransport({
    baseUrl: API,
    token: () => session.access,
    fetch: async (url, init) => {
      const r = await request.fetch(String(url), {
        method: init?.method,
        headers: init?.headers as Record<string, string>,
        data: init?.body as string | undefined,
      });
      return new Response(await r.text(), { status: r.status() });
    },
  });
  return { queue, identity, transport };
}

test('US28 : deux postes coupés 8 h vendent le même produit ; reprise sans doublon, somme exacte', async ({
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  const { id: product } = await owner<{ id: string }>('POST', '/app/products', {
    name: `Hors-ligne ${stamp}`,
    regulatory_status: 'free',
    sale_price: PRICE,
    purchase_price: 300,
    vat_bp: 0,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: product,
    lot_number: `HL-${stamp}`,
    expiry: '2031-01-01',
    quantity: STOCK,
    unit_cost: 300,
  });

  const first = await offlineStation(request, 'cashier@demo.test', 'HL1', product);
  const second = await offlineStation(request, 'pharmacist@demo.test', 'HL2', product);

  // Le réseau retombe après le traitement du premier lot : la réponse est perdue.
  let calls = 0;
  const flaky: SyncTransport = {
    pull: first.transport.pull,
    async push(batch) {
      const response = await first.transport.push(batch);
      if (++calls === 1) throw new Error('réseau coupé');
      return response;
    },
  };
  const lost = await pushPending(first.queue, flaky, { ...first.identity, batchSize: 8 });
  expect(lost.online).toBe(false);
  expect(first.queue.pendingCount()).toBe(SALES + 2);

  const retried = await pushPending(first.queue, flaky, { ...first.identity, batchSize: 8 });
  expect(retried).toMatchObject({ online: true, rejected: [], applied: SALES + 2 - 8 });
  expect(first.queue.pendingCount()).toBe(0);

  const other = await pushPending(second.queue, second.transport, second.identity);
  expect(other).toMatchObject({ online: true, rejected: [], applied: SALES + 2 });
  const missing = other.conflicts
    .filter((c) => c.kind === 'stock_shortage')
    .reduce((sum, c) => sum + Number(c.details.missing), 0);
  expect(missing).toBe(2 * SALES - STOCK);

  const stock = await owner<{ on_hand: number }>('GET', `/app/stock/products/${product}/on-hand`);
  expect(stock.on_hand).toBe(STOCK - 2 * SALES);

  const snapshot = await pullReference(second.transport);
  expect(snapshot.products.find((p) => p.id === product)?.unassignedQty).toBe(STOCK - 2 * SALES);
  expect(snapshot.lots.find((l) => l.productId === product)?.quantity).toBe(0);
});
