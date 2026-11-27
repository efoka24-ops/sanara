import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const PHOTO = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);
const stamp = Date.now().toString(36);

test.afterEach(async ({ request }) => {
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: false });
});

test('US21-US23 : ordonnance envoyée, devis du pharmacien, validation, retrait au comptoir avec le code', async ({
  page,
  browser,
  request,
}) => {
  const name = `Amoxicilline sirop ${stamp}`;
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });
  const product = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: 'free',
    sale_price: 2500,
    purchase_price: 1500,
    vat_bp: 0,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: product.id,
    lot_number: `RQ-${stamp}`,
    expiry: '2031-06-30',
    quantity: 5,
    unit_cost: 1500,
  });
  const found = await (
    await request.get('http://127.0.0.1:8081/public/pharmacies?q=d%C3%A9mo')
  ).json();
  const pharmacy = found.items[0].id as string;

  // Patient : depuis la fiche, envoi de la photo avec son accord.
  await page.goto(`/pharmacies/${pharmacy}`);
  await page.getByRole('link', { name: 'Envoyer mon ordonnance' }).click();
  await expect(page.getByText(/je suis l’assistant de Pharmacie Démo/)).toBeVisible();
  await page.locator('input[type=file]').setInputFiles({
    name: 'ordonnance.png',
    mimeType: 'image/png',
    buffer: PHOTO,
  });
  await expect(page.getByText(/ordonnance\.png/)).toBeVisible();
  await page.getByLabel('Votre prénom').fill('Aïssatou');
  await page.getByLabel('Votre téléphone').fill('+237 690 11 22 33');
  await page.getByRole('button', { name: 'Envoyer au pharmacien' }).click();
  await expect(page.getByRole('alert')).toContainText('Votre accord est nécessaire');
  await page.getByLabel(/J’accepte que mon ordonnance/).check();
  await page.getByRole('button', { name: 'Envoyer au pharmacien' }).click();

  await expect(page).toHaveURL(/\/suivi\/[0-9A-F]{40}$/);
  await expect(page.getByRole('heading', { name: 'Demande reçue' })).toBeVisible();
  await expect(page.getByText('Votre ordonnance est bien envoyée.')).toBeVisible();
  const number = (await page.locator('.eyebrow').first().innerText()).replace(/^DEMANDE\s+/i, '');
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/suivi.png`, fullPage: true });

  // Pharmacien : file des demandes, document, devis.
  const staff = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
  await loginAs(staff, 'pharmacist@demo.test');
  await staff.getByRole('link', { name: 'Demandes patients' }).click();
  await staff.getByRole('link', { name: number }).click();
  await expect(staff.locator('img.rx-doc')).toBeVisible();
  await staff.getByRole('button', { name: 'Prendre en charge' }).click();
  await staff.getByLabel('Ajouter un produit du catalogue').fill(name);
  await staff
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await staff.getByLabel('Qté').fill('2');
  await staff.getByLabel('Lu sur l’ordonnance').fill('Amoxicilline 250');
  await staff.getByLabel('Produit absent du catalogue').fill('Sirop introuvable');
  await staff.getByRole('button', { name: 'Ajouter comme indisponible' }).click();
  if (process.env.SHOTS)
    await staff.screenshot({ path: `${process.env.SHOTS}/traitement.png`, fullPage: true });
  await staff.getByRole('button', { name: 'Envoyer le devis au patient' }).click();
  await expect(staff.getByText('Devis envoyé au patient.')).toBeVisible();

  // Patient : devis puis code de retrait.
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Votre devis est prêt' })).toBeVisible();
  await expect(page.getByText('Indisponible')).toBeVisible();
  await expect(page.locator('.pos__total b')).toContainText(/5\s000/);
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/devis.png`, fullPage: true });
  await page.getByRole('button', { name: 'Valider le devis' }).click();
  await expect(page.getByRole('heading', { name: 'Devis validé' })).toBeVisible();
  const code = (await page.locator('.pickup__code b').innerText()).trim();
  expect(code).toMatch(/^[A-Z]\d{4}$/);

  await staff.getByRole('link', { name: 'Demandes patients' }).click();
  await staff.getByRole('link', { name: number }).click();
  await staff.getByRole('button', { name: 'Commande préparée : prévenir le patient' }).click();
  await expect(staff.getByText(/En attente du retrait/)).toBeVisible();
  await page.reload();
  await expect(page.getByRole('heading', { name: 'Commande prête' })).toBeVisible();
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/code.png`, fullPage: true });

  // Caissier : la commande retrouvée par son code, encaissée sans ressaisie.
  const till = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
  await loginAs(till, 'cashier@demo.test');
  await till.getByRole('link', { name: 'Caisse' }).click();
  const station = till.getByLabel('Nom du poste');
  await expect(station.or(till.getByLabel('Scanner ou chercher un produit (F1)'))).toBeVisible();
  if (await station.isVisible()) {
    await station.fill('Retraits');
    await till.getByRole('button', { name: 'Ouvrir la caisse' }).click();
  }
  await till.getByRole('button', { name: 'Retrait d’une commande' }).click();
  await till.getByLabel('Code du patient').fill('Z0000');
  await till.getByRole('button', { name: 'Retrouver la commande' }).click();
  await expect(till.getByText('Code inconnu, déjà utilisé ou expiré.')).toBeVisible();
  await till.getByLabel('Code du patient').fill(code.toLowerCase());
  await till.getByRole('button', { name: 'Retrouver la commande' }).click();
  await expect(till.getByText(`Retrait ${number} · Aïssatou`)).toBeVisible();
  await expect(till.getByLabel(`Qté ${name}`)).toBeDisabled();
  await expect(till.locator('.pos__total b').first()).toContainText(/5\s000/);
  await till.keyboard.press('F9');
  await till
    .getByRole('dialog', { name: 'Règlement' })
    .getByRole('button', { name: 'Valider la vente' })
    .click();
  await till.getByRole('button', { name: 'Fermer' }).click();
  await expect(till.getByText(/Vente enregistrée/)).toBeVisible();

  await page.reload();
  await expect(page.getByRole('heading', { name: 'Commande retirée' })).toBeVisible();
  await expect(page.locator('.pickup__code')).toHaveCount(0);

  // La caisse ouverte ici ne doit pas gêner les autres scénarios.
  const cashier = await apiAs(request, 'cashier@demo.test');
  const { session } = await cashier<{ session: { id: string } | null }>('GET', '/app/cash/current');
  if (session)
    await cashier('POST', `/app/cash/sessions/${session.id}/close`, {
      counts: [],
      reason: 'Fin du scénario de test',
    });
  expect((await cashier<{ session: unknown }>('GET', '/app/cash/current')).session).toBeNull();
});

test('US21 : des signes graves déclenchent le message d’urgence, sans aucun avis', async ({
  page,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });
  const found = await (
    await request.get('http://127.0.0.1:8081/public/pharmacies?q=d%C3%A9mo')
  ).json();
  await page.goto(`/pharmacies/${found.items[0].id}/envoyer`);
  await page.locator('input[type=file]').setInputFiles({
    name: 'photo.png',
    mimeType: 'image/png',
    buffer: PHOTO,
  });
  await page.getByLabel('Votre prénom').fill('Paul');
  await page.getByLabel('Votre téléphone').fill('+237 690 00 00 01');
  await page
    .getByLabel(/Message au pharmacien/)
    .fill('Mon fils a du mal à respirer depuis ce matin');
  await page.getByLabel(/J’accepte que mon ordonnance/).check();
  await page.getByRole('button', { name: 'Envoyer au pharmacien' }).click();
  await expect(page.getByRole('alert').filter({ hasText: 'Urgence' })).toContainText(
    'appelez immédiatement les services d’urgence',
  );
  await expect(page.getByText('Urgence signalée à la pharmacie')).toBeVisible();
});
