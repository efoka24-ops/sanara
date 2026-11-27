import { createRequire } from 'node:module';
import fs from 'node:fs';
import net from 'node:net';
import os from 'node:os';
import path from 'node:path';
import { _electron, expect, test, type ElectronApplication, type Page } from '@playwright/test';
import { DEMO_PW, apiAs } from '../e2e/helpers';

const root = path.resolve(__dirname, '..', '..');
const desktop = path.join(root, 'apps', 'desktop');
const electron = createRequire(path.join(desktop, 'package.json'))('electron') as string;
const stamp = Date.now().toString(36);
const station = `B${stamp.slice(-6)}`.toUpperCase();

/** Relais TCP vers l'API : le couper simule la panne de réseau du poste. */
function network(port: number) {
  const sockets = new Set<net.Socket>();
  let server: net.Server | null = null;
  return {
    async up() {
      server = net.createServer((client) => {
        const upstream = net.connect(8081, '127.0.0.1');
        for (const s of [client, upstream]) {
          sockets.add(s);
          s.on('error', () => s.destroy());
          s.on('close', () => sockets.delete(s));
        }
        client.pipe(upstream).pipe(client);
      });
      await new Promise<void>((done) => server!.listen(port, '127.0.0.1', done));
    },
    async down() {
      sockets.forEach((s) => s.destroy());
      await new Promise<void>((done) => (server ? server.close(() => done()) : done()));
      server = null;
    },
  };
}

async function launch(data: string, port: number) {
  const app = await _electron.launch({
    // SANARA_EXE : rejoue le test sur l'application empaquetée.
    executablePath: process.env.SANARA_EXE ?? electron,
    args: process.env.SANARA_EXE ? [] : [path.join(desktop, 'dist', 'main.cjs')],
    env: {
      ...(process.env as Record<string, string>),
      SANARA_DATA: data,
      SANARA_API: `http://127.0.0.1:${port}`,
    },
  });
  const page = await app.firstWindow();
  return { app, page };
}

async function signIn(page: Page, station?: string) {
  if (station) {
    await expect(page.getByRole('heading', { name: 'Connectez ce poste' })).toBeVisible();
    await page.getByLabel('Nom du poste').fill(station);
  }
  await page.getByLabel('E-mail').fill('cashier@demo.test');
  await page.getByLabel('Mot de passe').fill(DEMO_PW);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
}

async function sell(page: Page, name: string) {
  await page.getByLabel('Scanner ou chercher un produit (F1)').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await page.keyboard.press('F9');
  await page.getByRole('dialog', { name: 'Règlement' }).getByLabel('Montant').fill('1000');
  await page.getByRole('button', { name: 'Valider la vente' }).click();
  const ticket = page.frameLocator(`iframe[title^="V-${station}-"]`);
  await expect(ticket.getByText('TOTAL TTC')).toBeVisible();
  await expect(ticket.getByText(name)).toBeVisible();
  await page.getByRole('button', { name: 'Fermer' }).click();
}

test('poste de bureau : vente en ligne puis hors-ligne, redémarrage sans réseau, synchronisation', async ({
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  const name = `Bureau ${stamp}`;
  const { id } = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: 'free',
    sale_price: 700,
    purchase_price: 400,
    vat_bp: 0,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: id,
    lot_number: `B-${stamp}`,
    expiry: '2031-01-01',
    quantity: 10,
    unit_cost: 400,
  });
  const onHand = async () =>
    (await owner<{ on_hand: number }>('GET', `/app/stock/products/${id}/on-hand`)).on_hand;

  const data = fs.mkdtempSync(path.join(os.tmpdir(), 'sanara-poste-'));
  const port = 8092;
  const link = network(port);
  await link.up();
  let running: ElectronApplication | null = null;
  try {
    let { app, page } = await launch(data, port);
    running = app;
    await page.screenshot({ path: path.join(root, 'test-results', 'poste-activation.png') });
    await signIn(page, station);
    await expect(page.getByText('En ligne', { exact: true })).toBeVisible();
    await page.getByRole('link', { name: 'Caisse' }).click();
    await expect(page.getByLabel('Nom du poste')).toHaveValue(station);
    await page.getByRole('button', { name: 'Ouvrir la caisse' }).click();
    await sell(page, name);
    await expect(page.getByText('Tout est envoyé')).toBeVisible({ timeout: 20_000 });
    expect(await onHand()).toBe(9);

    // Panne : la vente continue.
    await link.down();
    await sell(page, name);
    await expect(page.getByText('Mode hors ligne')).toBeVisible();
    await page.screenshot({ path: path.join(root, 'test-results', 'poste-hors-ligne.png') });
    await expect(page.getByText('Opérations à envoyer : 1')).toBeVisible();

    // Coupure de courant et redémarrage, toujours sans réseau.
    await app.close();
    ({ app, page } = await launch(data, port));
    running = app;
    await signIn(page);
    await page.getByRole('link', { name: 'Caisse' }).click();
    await sell(page, name);
    await expect(page.getByText('Opérations à envoyer : 2')).toBeVisible();
    expect(await onHand()).toBe(9);

    // Retour du réseau.
    await link.up();
    await page.getByRole('button', { name: 'Synchroniser' }).click();
    await expect(page.getByText('Tout est envoyé')).toBeVisible({ timeout: 20_000 });
    expect(await onHand()).toBe(7);

    await page.getByRole('button', { name: /Clôturer la caisse/ }).click();
    const close = page.getByRole('dialog', { name: 'Clôture de caisse' });
    await close.getByLabel(/Coupure 100$/).fill('21');
    await close.getByRole('button', { name: 'Clôturer', exact: true }).click();
    await expect(
      page.frameLocator('iframe[title="Rapport Z"]').getByText(/Rapport Z/),
    ).toBeVisible();
    await page.getByRole('button', { name: 'Fermer' }).click();
    await expect(page.getByText('Tout est envoyé')).toBeVisible({ timeout: 20_000 });
    await page.screenshot({ path: path.join(root, 'test-results', 'poste.png') });

    const sales = await owner<{ items: { number: string }[] }>(
      'GET',
      `/app/sales?date=${new Date().toISOString().slice(0, 10)}`,
    );
    expect(sales.items.filter((s) => s.number.startsWith(`V-${station}-`))).toHaveLength(3);
  } finally {
    await running?.close();
    await link.down();
    fs.rmSync(data, { recursive: true, force: true });
  }
});
