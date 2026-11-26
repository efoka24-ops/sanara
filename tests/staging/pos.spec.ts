import { expect, test, type APIRequestContext, type Page } from '@playwright/test';

/** Parcours connectés sur la préproduction, avec la pharmacie de démonstration (données fictives). */
const PW = process.env.STAGING_DEMO_PASSWORD;
const run = Date.now().toString(36);
test.skip(!PW, 'STAGING_DEMO_PASSWORD non fourni');
test.describe.configure({ mode: 'serial' });

// Un jeton par rôle : l'API limite les connexions à 10 par minute et par adresse IP.
const tokens = new Map<string, string>();
async function api(request: APIRequestContext, role: string) {
  let token = tokens.get(role);
  if (!token) {
    const login = await request.post('/api/auth/login', {
      data: { email: `${role}@demo.sanara.test`, password: PW },
    });
    expect(login.ok(), `connexion ${role} : HTTP ${login.status()}`).toBeTruthy();
    token = ((await login.json()) as { access: string }).access;
    tokens.set(role, token);
  }
  return async <T = Record<string, unknown>>(
    method: string,
    path: string,
    data?: unknown,
  ): Promise<T> => {
    // Comme le client web : l'hébergeur refuse PUT, PATCH et DELETE
    const overridden = ['PUT', 'PATCH', 'DELETE'].includes(method);
    const r = await request.fetch(`/api${path}`, {
      method: overridden ? 'POST' : method,
      data,
      headers: {
        Authorization: `Bearer ${token}`,
        ...(overridden ? { 'X-HTTP-Method-Override': method } : {}),
      },
    });
    return r.status() === 204 ? ({} as T) : ((await r.json()) as T);
  };
}
async function closeOpenSession(request: APIRequestContext, role: string) {
  const call = await api(request, role);
  const cur = await call<{ session: { id: string } | null }>('GET', '/app/cash/current');
  if (!cur.session) return;
  const prev = await call<{ totals: { expected_cash: number } }>(
    'GET',
    `/app/cash/sessions/${cur.session.id}/preview`,
  );
  const e = prev.totals.expected_cash;
  await call('POST', `/app/cash/sessions/${cur.session.id}/close`, {
    counts: e > 0 ? [{ denomination: 1, qty: e }] : [],
    reason: 'Nettoyage du test',
  });
}
async function login(page: Page, role: string) {
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill(`${role}@demo.sanara.test`);
  await page.getByLabel('Mot de passe').fill(PW!);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible({
    timeout: 30_000,
  });
}

test('le caissier vend en espèces sur la préproduction puis clôture sa caisse', async ({
  page,
  request,
}) => {
  await closeOpenSession(request, 'cashier');
  const owner = await api(request, 'owner');
  const name = `Paracétamol ${run}`;
  const p = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: 'free',
    sale_price: 1500,
    purchase_price: 1000,
    vat_bp: 1925,
    min_stock: 3,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: p.id,
    lot_number: `L-${run}`,
    expiry: '2031-03-31',
    quantity: 20,
    unit_cost: 1000,
  });

  await login(page, 'cashier');
  await page.getByRole('link', { name: 'Caisse' }).click();
  await page.getByLabel('Nom du poste').fill('Staging');
  await page.getByRole('button', { name: 'Ouvrir la caisse' }).click();
  await page.getByLabel('Scanner ou chercher un produit (F1)').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await page.getByLabel(`Qté ${name}`).fill('2');
  await page.keyboard.press('F9');
  const pay = page.getByRole('dialog', { name: 'Règlement' });
  await pay.getByLabel('Montant').fill('5000');
  await expect(pay.getByText(/Rendu monnaie/)).toBeVisible();
  await pay.getByRole('button', { name: 'Valider la vente' }).click();
  const ticket = page.frameLocator('iframe[title^="V-STAGING-"]');
  await expect(ticket.getByText('TOTAL TTC')).toBeVisible();
  await expect(ticket.getByText(name)).toBeVisible();
  await page.getByRole('button', { name: 'Fermer' }).click();

  await page.getByRole('button', { name: /Clôturer la caisse/ }).click();
  const close = page.getByRole('dialog', { name: 'Clôture de caisse' });
  await close.getByLabel('Coupure 1 000').fill('2'); // 3 000 attendus, 2 000 comptés : l'écart rend le motif obligatoire
  await close
    .getByLabel(/Coupure 5 ?000$/)
    .first()
    .fill('0');
  await close.getByLabel('Motif de l’écart').fill('Écart de test');
  await close.getByRole('button', { name: 'Clôturer', exact: true }).click();
  await expect(
    page.frameLocator('iframe[title="Rapport Z"]').getByText(/Rapport Z Z-STAGING-/),
  ).toBeVisible();
  expect(
    (await owner<{ on_hand: number }>('GET', `/app/stock/products/${p.id}/on-hand`)).on_hand,
  ).toBe(18);
});

test('un produit sous ordonnance est refusé sans pharmacien, accepté avec son PIN', async ({
  page,
  request,
}) => {
  await closeOpenSession(request, 'cashier');
  const owner = await api(request, 'owner');
  const name = `Amoxicilline ${run}`;
  const p = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: 'prescription_only',
    sale_price: 2800,
    purchase_price: 1900,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: p.id,
    lot_number: `A-${run}`,
    expiry: '2031-03-31',
    quantity: 5,
    unit_cost: 1900,
  });
  const pharma = await api(request, 'pharmacist');
  await pharma('PUT', '/auth/pin', { pin: '2468' });

  const cashier = await api(request, 'cashier');
  await cashier('POST', '/app/cash/open', { station: 'RX', opening_float: 0 });
  const refused = await cashier<{ error: { code: string } }>('POST', '/app/sales', {
    lines: [{ product_id: p.id, qty: 1 }],
    payments: [{ method: 'cash', amount: 2800 }],
  });
  expect(refused.error.code).toBe('prescription_required');
  await closeOpenSession(request, 'cashier');

  await login(page, 'cashier');
  await page.getByRole('link', { name: 'Caisse' }).click();
  await page.getByLabel('Nom du poste').fill('RX');
  await page.getByRole('button', { name: 'Ouvrir la caisse' }).click();
  await page.getByLabel('Scanner ou chercher un produit (F1)').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await page.keyboard.press('F9');
  const pay = page.getByRole('dialog', { name: 'Règlement' });
  await pay.getByLabel('Montant').fill('2800');
  await pay.getByLabel('N° d’ordonnance').fill(`ORD-${run}`);
  await pay.getByLabel('Prescripteur').fill('Dr Mballa');
  await pay.getByLabel('Patient').fill('Aïcha Bello');
  await pay
    .getByLabel('Pharmacien délivrant')
    .selectOption({ label: 'pharmacist@demo.sanara.test' });
  await pay.getByLabel('Code PIN du responsable').fill('2468');
  await pay.getByRole('button', { name: 'Valider la vente' }).click();
  await expect(
    page.frameLocator('iframe[title^="V-RX-"]').getByText(`Ordonnance ORD-${run}`),
  ).toBeVisible();
  await closeOpenSession(request, 'cashier');
});

test('le magasinier réceptionne et le titulaire valide un inventaire', async ({
  page,
  request,
}) => {
  const owner = await api(request, 'owner');
  const name = `Sérum ${run}`;
  const p = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: 'free',
    sale_price: 1000,
    purchase_price: 600,
  });
  const keeper = await api(request, 'storekeeper');
  await keeper('POST', '/app/stock/receipts', {
    product_id: p.id,
    lot_number: `S-${run}`,
    expiry: '2031-03-31',
    quantity: 10,
    unit_cost: 600,
  });
  const inv = await keeper<{ id: string }>('POST', '/app/inventories', {
    kind: 'full',
    blind: true,
  });

  await login(page, 'owner');
  await page.getByRole('link', { name: 'Stock' }).click();
  await page.getByRole('link', { name: 'Inventaire' }).click();
  await page
    .getByRole('button', { name: new RegExp(`I-\\d{4}-`) })
    .first()
    .click();
  await page.getByLabel('Scanner un code-barres ou taper un nom').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  const qty = page.getByLabel(new RegExp(`Compté ${name}`));
  await qty.fill('9');
  await qty.press('Enter');
  await page.getByRole('button', { name: 'Envoyer pour validation' }).click();
  await page.getByRole('button', { name: 'Valider et ajuster le stock' }).click();
  await expect(page.getByText('Validé').first()).toBeVisible();
  expect(
    (await owner<{ on_hand: number }>('GET', `/app/stock/products/${p.id}/on-hand`)).on_hand,
  ).toBe(9);
  expect(inv.id).toBeTruthy();
});
