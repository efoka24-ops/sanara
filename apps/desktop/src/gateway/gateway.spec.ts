import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { openLocalDatabase, type LocalDatabase } from '../local-db';
import { Gateway } from './gateway';

const PASSWORD = 'Motdepasse#2026';
const SERVER = 'http://serveur/api';

/** Serveur de test : assez de l'API pour la connexion et la synchronisation. */
function fakeServer() {
  const state = {
    down: false,
    mfa: false,
    password: PASSWORD,
    logins: 0,
    refreshes: 0,
    expired: new Set<string>(),
    pushed: [] as { id: string; type: string }[],
    forwarded: [] as { method: string; path: string; token: string; override: string | null }[],
    token: 0,
  };
  const json = (body: unknown, status = 200) =>
    new Response(JSON.stringify(body), {
      status,
      headers: { 'Content-Type': 'application/json' },
    });
  const tokens = () => {
    state.token++;
    return {
      access: `srv-a${state.token}`,
      refresh: `srv-r${state.token}`,
      user: { id: 'u-cashier', role: 'cashier', tenant_id: 'T1', email: 'c@t.cm' },
    };
  };
  const fetch = async (url: string, init: RequestInit = {}): Promise<Response> => {
    if (state.down) throw new TypeError('fetch failed');
    const { pathname, search } = new URL(url);
    const route = pathname.replace(/^\/api/, '');
    const headers = new Headers(init.headers);
    const token = (headers.get('Authorization') ?? '').replace('Bearer ', '');
    const body = init.body ? JSON.parse(String(init.body)) : {};
    if (route === '/auth/login') {
      state.logins++;
      if (body.password !== state.password) {
        return json({ error: { code: 'invalid_credentials' } }, 401);
      }
      if (state.mfa) return json({ mfa_required: true, mfa_token: 'm' });
      return json(tokens());
    }
    if (route === '/auth/mfa/verify') return json(tokens());
    if (route === '/auth/refresh') {
      state.refreshes++;
      return json(tokens());
    }
    if (state.expired.has(token)) return json({ error: { code: 'invalid_token' } }, 401);
    if (route === '/auth/me') {
      return json({
        id: 'u-cashier',
        role: 'cashier',
        tenant_id: 'T1',
        permissions: ['sales.create', 'sales.discount', 'products.read'],
        mfa_setup_required: false,
        mfa_enabled: state.mfa,
      });
    }
    if (route === '/sync/device') {
      if (body.name === 'PRIS') return json({ error: { code: 'station_name_taken' } }, 409);
      return json({ id: 'D1', name: String(body.name).toUpperCase() });
    }
    if (route === '/sync/push') {
      state.pushed.push(...body.operations);
      return json({
        serverTime: new Date().toISOString(),
        clockSkewSeconds: 0,
        results: body.operations.map((o: { id: string }) => ({
          id: o.id,
          status: 'applied',
          conflicts: [],
        })),
      });
    }
    if (route === '/sync/pull') {
      const sold = state.pushed.filter((o) => o.type === 'sale.create').length;
      return json({
        serverTime: new Date().toISOString(),
        settings: {
          maxDiscountBp: 1000,
          allowNegativeStock: false,
          saleCounters: {},
          pharmacy: {
            name: 'Pharmacie <du> Plateau',
            address: 'Rue 12',
            phone: '+237 6',
            licenseNumber: 'AG-1',
            currency: 'XAF',
            ticketFooter: 'À bientôt',
            ticketWidth: '58',
          },
        },
        products: [
          {
            id: 'para',
            name: 'Paracétamol',
            dci: 'paracétamol',
            form: 'cp',
            strength: '500 mg',
            regulatoryStatus: 'free',
            salePrice: 1500,
            vatBp: 1925,
            active: true,
            barcodes: ['340001'],
            unassignedQty: 0,
          },
        ],
        lots: [
          {
            id: 'L1',
            productId: 'para',
            lotNumber: 'A',
            expiry: '2031-01-01',
            status: 'available',
            receivedAt: '2026-01-01 00:00:00.000',
            quantity: 10 - sold * 2,
          },
        ],
        nextCursor: null,
      });
    }
    state.forwarded.push({
      method: init.method ?? 'GET',
      path: route + search,
      token,
      override: headers.get('X-HTTP-Method-Override'),
    });
    return json({ from: 'server' });
  };
  return { state, fetch };
}

let dir: string;
let db: LocalDatabase;
let server: ReturnType<typeof fakeServer>;
let gateway: Gateway;
let clock: Date;
const make = () =>
  new Gateway({
    db,
    deviceId: 'poste-1',
    serverUrl: SERVER,
    version: '9.9.9',
    fetch: server.fetch,
    now: () => clock,
  });

beforeEach(() => {
  dir = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-gw-'));
  db = openLocalDatabase(path.join(dir, 'sanara.db'));
  server = fakeServer();
  clock = new Date('2026-11-27T08:00:00.000Z');
  gateway = make();
});
afterEach(async () => {
  await gateway.idle();
  db.close();
  fs.rmSync(dir, { recursive: true, force: true });
});

interface Reply {
  access: string;
  refresh: string;
  error: { code: string; details?: Record<string, unknown> };
  id: string;
  session: Record<string, unknown>;
  items: unknown[];
  on_hand: number;
  totals: { expected_cash: number };
  role: string;
  permissions: string[];
  mfa_required: boolean;
  valid: boolean;
}

async function call(method: string, route: string, body?: unknown, token?: string) {
  const headers: Record<string, string> = {};
  if (token) headers.Authorization = `Bearer ${token}`;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  let sent = method;
  if (['PUT', 'PATCH', 'DELETE'].includes(method)) {
    headers['X-HTTP-Method-Override'] = method;
    sent = 'POST';
  }
  const response = await gateway.handle(
    new Request(`app://sanara/api${route}`, {
      method: sent,
      headers,
      body: body === undefined ? undefined : JSON.stringify(body),
    }),
  );
  const text = await response.text();
  let parsed = {} as Reply;
  try {
    parsed = JSON.parse(text);
  } catch {
    /* page HTML */
  }
  return { status: response.status, body: parsed, text };
}

async function signIn(password = PASSWORD) {
  const r = await call('POST', '/auth/login', { email: 'c@t.cm', password });
  await gateway.idle();
  return r;
}
const sale = {
  lines: [{ product_id: 'para', qty: 2 }],
  payments: [{ method: 'cash', amount: 5000 }],
};

describe('connexion', () => {
  it('ne remet jamais les jetons du serveur à l’interface et télécharge le référentiel', async () => {
    const r = await signIn();
    expect(r.status).toBe(200);
    expect(r.body.access).toMatch(/^local\./);
    expect(r.body.refresh).toMatch(/^localr\./);
    expect(JSON.stringify(r.body)).not.toContain('srv-');
    expect(gateway.status()).toMatchObject({ online: true, ready: true, pending: 0 });
  });

  it('relaie les autres appels avec le jeton du serveur, et le renouvelle seul', async () => {
    const { access, refresh } = (await signIn()).body;
    await call('PUT', '/app/settings', { settings: {} }, access);
    expect(server.state.forwarded.at(-1)).toMatchObject({
      method: 'POST',
      path: '/app/settings',
      override: 'PUT',
    });

    server.state.expired.add(server.state.forwarded.at(-1)!.token);
    expect((await call('GET', '/app/team', undefined, access)).body).toEqual({ from: 'server' });
    expect(server.state.refreshes).toBe(1);
    // L'interface garde ses jetons locaux : son renouvellement ne touche pas au serveur.
    expect((await call('POST', '/auth/refresh', { refresh })).body).toEqual({ access, refresh });
    expect(server.state.refreshes).toBe(1);
  });

  it('permet la connexion hors-ligne après une connexion réussie, avec le même mot de passe', async () => {
    await signIn();
    gateway = make(); // application redémarrée, sans réseau
    server.state.down = true;
    expect((await signIn('Faux#Motdepasse1')).status).toBe(401);
    const r = await signIn();
    expect(r.status).toBe(200);
    const me = await call('GET', '/auth/me', undefined, r.body.access);
    expect(me.body).toMatchObject({ role: 'cashier', id: 'u-cashier' });
    expect(me.body.permissions).toContain('sales.create');
    expect(gateway.status().online).toBe(false);
  });

  it('ne garde aucun mot de passe en clair sur le disque', async () => {
    await signIn();
    db.pragma('wal_checkpoint(TRUNCATE)');
    expect(fs.readFileSync(path.join(dir, 'sanara.db')).includes(PASSWORD)).toBe(false);
  });

  it('bloque après cinq mots de passe faux hors-ligne', async () => {
    await signIn();
    server.state.down = true;
    for (let i = 0; i < 5; i++) expect((await signIn('Faux#Motdepasse1')).status).toBe(401);
    expect((await signIn()).body.error.code).toBe('account_locked');
    clock = new Date('2026-11-27T08:16:00.000Z');
    expect((await signIn()).status).toBe(200);
  });

  it('refuse hors-ligne un compte jamais connecté, trop ancien, avec 2FA ou désactivé', async () => {
    server.state.down = true;
    expect((await signIn()).body.error.code).toBe('offline');

    server.state.down = false;
    await signIn();
    clock = new Date('2027-01-05T08:00:00.000Z');
    server.state.down = true;
    expect((await signIn()).body.error.code).toBe('offline');

    // Mot de passe refusé par le serveur : la copie locale est effacée.
    server.state.down = false;
    await signIn();
    server.state.password = 'Nouveau#Motdepasse2';
    expect((await signIn()).status).toBe(401);
    server.state.down = true;
    expect((await signIn()).body.error.code).toBe('offline');

    server.state.down = false;
    server.state.mfa = true;
    server.state.password = PASSWORD;
    expect((await signIn()).body.mfa_required).toBe(true);
    await call('POST', '/auth/mfa/verify', { mfa_token: 'm', code: '1' });
    server.state.down = true;
    expect((await signIn()).body.error.code).toBe('offline');
  });

  it('répond hors-ligne pour ce qui exige le réseau, et 401 sans session', async () => {
    const { access } = (await signIn()).body;
    server.state.down = true;
    const r = await call('GET', '/app/team', undefined, access);
    expect([r.status, r.body.error.code]).toEqual([503, 'offline']);
    expect((await call('GET', '/app/cash/current', undefined, 'inconnu')).status).toBe(401);
  });
});

describe('barre d’état et activation du poste', () => {
  it('annonce la pharmacie, le compte, la version et l’imprimante', async () => {
    expect(gateway.status()).toMatchObject({ user: null, pharmacy: null, station: null });
    await signIn();
    gateway.setPrinters(2);
    expect(gateway.status()).toMatchObject({
      user: { email: 'c@t.cm', role: 'cashier' },
      pharmacy: 'Pharmacie <du> Plateau',
      station: null,
      version: '9.9.9',
      printers: 2,
      progress: null,
    });
  });

  it('enregistre le poste auprès du serveur et garde son nom', async () => {
    const { access } = (await signIn()).body;
    const taken = await call('POST', '/_desktop/station', { name: 'PRIS' }, access);
    expect([taken.status, taken.body.error.code]).toEqual([409, 'station_name_taken']);
    expect(gateway.status().station).toBeNull();

    const done = await call('POST', '/_desktop/station', { name: 'caisse2' }, access);
    expect(done.status).toBe(200);
    gateway = make();
    expect(gateway.status().station).toBe('CAISSE2');
  });

  it('refuse l’enregistrement sans session ou sans réseau', async () => {
    expect((await call('POST', '/_desktop/station', { name: 'A' })).status).toBe(401);
    const { access } = (await signIn()).body;
    server.state.down = true;
    const r = await call('POST', '/_desktop/station', { name: 'A' }, access);
    expect([r.status, r.body.error.code]).toEqual([503, 'offline']);
  });
});

describe('mise à jour de l’application (T128)', () => {
  it('annonce la mise à jour et refuse de l’installer pendant qu’une caisse est ouverte', async () => {
    const { access } = (await signIn()).body;
    let installed = 0;
    gateway.setUpdates({
      state: () => ({ status: 'ready', version: '0.2.0', reason: null }),
      install: () => void installed++,
    });
    expect(gateway.status().update).toEqual({ status: 'ready', version: '0.2.0', reason: null });

    await call('POST', '/app/cash/open', { station: 'C1', opening_float: 0 }, access);
    const refused = await call('POST', '/_desktop/update/install', {}, access);
    expect([refused.status, refused.body.error.code, installed]).toEqual([409, 'till_open', 0]);

    const { session } = (await call('GET', '/app/cash/current', undefined, access)).body;
    await call('POST', `/app/cash/sessions/${session.id}/close`, { counts: [] }, access);
    const done = await call('POST', '/_desktop/update/install', {}, access);
    expect([done.status, installed]).toEqual([200, 1]);
    expect((await call('POST', '/_desktop/update/install', {})).status).toBe(401);
  });
});

describe('caisse du poste', () => {
  it('vend sans réseau puis synchronise au retour', async () => {
    const { access } = (await signIn()).body;
    server.state.down = true;

    const open = await call(
      'POST',
      '/app/cash/open',
      { station: 'c1', opening_float: 1000 },
      access,
    );
    expect(open.status).toBe(201);
    const current = await call('GET', '/app/cash/current', undefined, access);
    expect(current.body.session).toMatchObject({
      id: open.body.id,
      station: 'C1',
      opening_float: 1000,
      stale: false,
    });

    const found = await call('GET', '/app/products?q=para&limit=8&picker=1', undefined, access);
    expect(found.body.items[0]).toEqual({
      id: 'para',
      name: 'Paracétamol',
      dci: 'paracétamol',
      form: 'cp',
      strength: '500 mg',
      sale_price: 1500,
      vat_bp: 1925,
      regulatory_status: 'free',
    });

    const done = await call('POST', '/app/sales', sale, access);
    expect(done.status).toBe(201);
    expect(done.body).toMatchObject({ number: 'V-C1-2026-00001', total: 3000, change: 2000 });
    const stock = await call('GET', '/app/stock/products/para/on-hand?picker=1', undefined, access);
    expect(stock.body.on_hand).toBe(8);

    const ticket = await call('GET', `/app/sales/${done.body.id}/receipt`, undefined, access);
    expect(ticket.text).toContain('TOTAL TTC');
    expect(ticket.text).toContain('V-C1-2026-00001');
    expect(ticket.text).toContain('Pharmacie &lt;du&gt; Plateau');
    expect(ticket.text).toContain('58mm');
    expect(ticket.text).not.toContain('DUPLICATA');
    expect(
      (await call('GET', `/app/sales/${done.body.id}/receipt`, undefined, access)).text,
    ).toContain('DUPLICATA');

    const preview = await call(
      'GET',
      `/app/cash/sessions/${open.body.id}/preview`,
      undefined,
      access,
    );
    expect(preview.body.totals.expected_cash).toBe(4000);
    const closed = await call(
      'POST',
      `/app/cash/sessions/${open.body.id}/close`,
      { counts: [{ denomination: 1000, qty: 4 }] },
      access,
    );
    expect(closed.status).toBe(200);
    const z = await call('GET', `/app/cash/sessions/${open.body.id}/z`, undefined, access);
    expect(z.text).toContain('Rapport Z');
    expect(z.text).toContain('Espèces attendues');
    expect(gateway.status()).toMatchObject({ online: false, pending: 3 });

    server.state.down = false;
    await gateway.syncNow();
    expect(server.state.pushed.map((o) => o.type)).toEqual([
      'cash.open',
      'sale.create',
      'cash.close',
    ]);
    expect(gateway.status()).toMatchObject({ online: true, pending: 0, rejected: [] });
  });

  it('rend les erreurs au format de l’API', async () => {
    const { access } = (await signIn()).body;
    expect((await call('POST', '/app/sales', sale, access)).body.error.code).toBe(
      'cash_session_required',
    );
    await call('POST', '/app/cash/open', { station: 'C1', opening_float: 0 }, access);
    const short = await call(
      'POST',
      '/app/sales',
      { lines: [{ product_id: 'para', qty: 99 }], payments: [{ method: 'cash', amount: 999999 }] },
      access,
    );
    expect(short.status).toBe(409);
    expect(short.body.error).toMatchObject({
      code: 'insufficient_stock',
      details: { product_id: 'para', missing: 89 },
    });
  });

  it('n’accepte le Mobile Money que réseau présent', async () => {
    const { access } = (await signIn()).body;
    await call('POST', '/app/cash/open', { station: 'C1', opening_float: 0 }, access);
    const momo = {
      lines: [{ product_id: 'para', qty: 1 }],
      payments: [{ method: 'momo_mtn', amount: 1500, reference: 'MP-1' }],
    };
    await gateway.idle();
    expect((await call('POST', '/app/sales', momo, access)).status).toBe(201);
    await gateway.idle();
    server.state.down = true;
    await gateway.syncNow();
    expect((await call('POST', '/app/sales', momo, access)).body.error.code).toBe(
      'payment_offline_forbidden',
    );
  });

  it('garde les paniers en attente et le verrouillage par PIN sans réseau', async () => {
    const { access } = (await signIn()).body;
    await call('PUT', '/auth/pin', { pin: '2468' }, access);
    server.state.down = true;
    expect((await call('POST', '/auth/pin/verify', { pin: '2468' }, access)).body.valid).toBe(true);
    expect((await call('POST', '/auth/pin/verify', { pin: '0000' }, access)).body.valid).toBe(
      false,
    );

    const held = await call(
      'POST',
      '/app/pos/held',
      { label: 'Mme B', cart: { lines: [] } },
      access,
    );
    const list = await call('GET', '/app/pos/held', undefined, access);
    expect(list.body.items).toMatchObject([{ id: held.body.id, label: 'Mme B' }]);
    expect((await call('DELETE', `/app/pos/held/${held.body.id}`, undefined, access)).status).toBe(
      204,
    );
    expect((await call('GET', '/app/pos/held', undefined, access)).body.items).toEqual([]);
  });
});
