import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const PHOTO = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);
const stamp = Date.now().toString(36);

// Les fenêtres du pharmacien et du caissier se ferment : sinon elles continuent de signaler leur présence.
test.afterEach(async ({ browser, page }) => {
  for (const c of browser.contexts()) if (c !== page.context()) await c.close();
});

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

test('T163 : le moteur Sanara lit une ordonnance sur le poste et retient le choix du pharmacien', async ({
  page,
  browser,
  request,
}) => {
  test.setTimeout(180_000);
  const name = `Ciprofloxacine 500 mg ${stamp}`;
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });
  const product = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: 'free',
    sale_price: 3000,
    purchase_price: 2000,
    vat_bp: 0,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: product.id,
    lot_number: `OCR-${stamp}`,
    expiry: '2031-06-30',
    quantity: 10,
    unit_cost: 2000,
  });
  const found = await (
    await request.get('http://127.0.0.1:8081/public/pharmacies?q=d%C3%A9mo')
  ).json();

  // Ordonnance dactylographiée, dessinée dans le navigateur (données fictives).
  await page.goto('/');
  const photo = Buffer.from(
    (
      await page.evaluate(() => {
        const c = document.createElement('canvas');
        c.width = 1400;
        c.height = 900;
        const g = c.getContext('2d')!;
        g.fillStyle = '#fff';
        g.fillRect(0, 0, c.width, c.height);
        g.fillStyle = '#000';
        g.font = '44px Arial';
        [
          'Dr Exemple, médecin',
          'Douala, le 12/11/2026',
          '1) Ciprofloxacine 500 mg comprimés',
          '1 cp matin et soir pendant 7 jours',
          '2 boîtes',
        ].forEach((t, i) => g.fillText(t, 80, 120 + i * 140));
        return c.toDataURL('image/png');
      })
    ).split(',')[1]!,
    'base64',
  );

  const send = async () => {
    await page.goto(`/pharmacies/${found.items[0].id}/envoyer`);
    await page
      .locator('input[type=file]')
      .setInputFiles({ name: 'ordo.png', mimeType: 'image/png', buffer: photo });
    await page.getByLabel('Votre prénom').fill('Awa');
    await page.getByLabel('Votre téléphone').fill('+237 690 00 00 02');
    await page.getByLabel(/J’accepte que mon ordonnance/).check();
    await page.getByRole('button', { name: 'Envoyer au pharmacien' }).click();
    await expect(page.getByRole('heading', { name: 'Demande reçue' })).toBeVisible();
    return (await page.locator('.eyebrow').first().innerText()).replace(/^DEMANDE\s+/i, '');
  };

  const staff = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
  await loginAs(staff, 'pharmacist@demo.test');
  const readAndQuote = async (number: string) => {
    await staff.getByRole('link', { name: 'Demandes patients' }).click();
    await staff.getByRole('link', { name: number }).click();
    await staff.getByRole('button', { name: 'Prendre en charge' }).click();
    await staff.getByRole('button', { name: 'Lire l’ordonnance' }).click();
    const proposal = staff.locator('.reader__line').filter({ hasText: /Ciprofloxacine 500 mg/i });
    await expect(proposal).toBeVisible({ timeout: 120_000 });
    await expect(proposal).toContainText('2 boîte(s)');
    return proposal;
  };

  const first = await readAndQuote(await send());
  if (process.env.SHOTS)
    await staff.screenshot({ path: `${process.env.SHOTS}/lecture.png`, fullPage: true });
  await first.getByRole('button', { name: new RegExp(name) }).click();
  await expect(staff.getByLabel('Lu sur l’ordonnance')).toHaveValue(/Ciprofloxacine 500 mg/i);
  await expect(staff.getByLabel('Qté')).toHaveValue('2');
  await staff.getByRole('button', { name: 'Envoyer le devis au patient' }).click();
  await expect(staff.getByText('Devis envoyé au patient.')).toBeVisible();

  // Deuxième ordonnance : le produit retenu est proposé comme déjà choisi.
  const second = await readAndQuote(await send());
  await expect(second.getByRole('button', { name: new RegExp(name) })).toContainText('déjà choisi');
  await expect(staff.getByText(/des lectures acceptées sans correction/)).toBeVisible();

  await owner('PUT', '/app/visibility', { visible: false });
});

test('US24 : le patient voit si un pharmacien est connecté ; une urgence alerte la pharmacie', async ({
  page,
  browser,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });
  await owner('PUT', '/app/opening-hours', {
    days: [1, 2, 3, 4, 5, 6, 7].map((weekday) => ({
      weekday,
      is_open: true,
      opens: '00:00',
      closes: '23:59',
    })),
  });
  const found = await (
    await request.get('http://127.0.0.1:8081/public/pharmacies?q=d%C3%A9mo')
  ).json();

  const staff = await (await browser.newContext({ locale: 'fr-FR' })).newPage();
  await loginAs(staff, 'pharmacist@demo.test');
  await expect(staff.getByText('Disponible pour les patients')).toBeVisible();

  const send = async (urgent: boolean) => {
    await page.goto(`/pharmacies/${found.items[0].id}/envoyer`);
    await page
      .locator('input[type=file]')
      .setInputFiles({ name: 'p.png', mimeType: 'image/png', buffer: PHOTO });
    await page.getByLabel('Votre prénom').fill('Ibrahim');
    await page
      .getByLabel('Votre téléphone')
      .fill(`+237 699 ${String(Date.now() % 100).padStart(2, '0')} 00 0${urgent ? 1 : 2}`);
    if (urgent) await page.getByLabel('C’est urgent').check();
    await page.getByLabel(/J’accepte que mon ordonnance/).check();
    await page.getByRole('button', { name: 'Envoyer au pharmacien' }).click();
    await expect(page.getByRole('heading', { name: 'Demande reçue' })).toBeVisible();
  };

  await send(false);
  await expect(page.getByText(/Un pharmacien est connecté/)).toBeVisible();

  await staff.getByRole('switch', { name: 'Disponible pour les demandes des patients' }).click();
  await expect(staff.getByText('Indisponible')).toBeVisible();
  await page.reload();
  await expect(page.getByText(/Aucun pharmacien n’est connecté/)).toBeVisible();

  await send(true);
  await expect(page.getByText(/les pharmaciens ont été alertés/)).toBeVisible();
  await staff.getByRole('link', { name: 'Demandes patients' }).click();
  await expect(staff.getByText('Urgent').first()).toBeVisible();

  await owner('PUT', '/app/visibility', { visible: false });
});

test('T175, P4 : générique proposé et accepté, coursier envoyé par le patient', async ({
  page,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });
  const generic = await owner<{ id: string }>('POST', '/app/products', {
    name: `Amoxicilline générique ${stamp}`,
    regulatory_status: 'free',
    sale_price: 900,
    purchase_price: 500,
    vat_bp: 0,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: generic.id,
    lot_number: `GEN-${stamp}`,
    expiry: '2031-06-30',
    quantity: 5,
    unit_cost: 500,
  });
  const found = await (
    await request.get('http://127.0.0.1:8081/public/pharmacies?q=d%C3%A9mo')
  ).json();

  await page.goto(`/pharmacies/${found.items[0].id}/envoyer`);
  await page
    .locator('input[type=file]')
    .setInputFiles({ name: 'g.png', mimeType: 'image/png', buffer: PHOTO });
  await page.getByLabel('Votre prénom').fill('Mariam');
  await page.getByLabel('Votre téléphone').fill('+237 690 55 44 33');
  await page.getByRole('button', { name: 'Je gère ma livraison' }).click();
  await expect(page.getByText(/il montre le code et paie au comptoir/)).toBeVisible();
  await page.getByLabel(/J’accepte que mon ordonnance/).check();
  await page.getByRole('button', { name: 'Envoyer au pharmacien' }).click();
  await expect(page.getByRole('heading', { name: 'Demande reçue' })).toBeVisible();
  const number = (await page.locator('.eyebrow').first().innerText()).replace(/^DEMANDE\s+/i, '');

  const pharmacist = await apiAs(request, 'pharmacist@demo.test');
  const queue = await pharmacist<{ items: { id: string; number: string }[] }>(
    'GET',
    '/app/requests',
  );
  const id = queue.items.find((i) => i.number === number)!.id;
  await pharmacist('POST', `/app/requests/${id}/take`, {});
  await pharmacist('POST', `/app/requests/${id}/quote`, {
    lines: [{ product_id: generic.id, quantity: 1, replaces: 'Clamoxyl 500 mg' }],
  });

  await page.reload();
  await expect(page.getByText(/Générique proposé à la place de Clamoxyl 500 mg/)).toBeVisible();
  await page.getByRole('button', { name: 'Valider le devis' }).click();
  await expect(
    page.getByText('Votre coursier montre ce code au caissier et paie au comptoir.'),
  ).toBeVisible();
  await owner('PUT', '/app/visibility', { visible: false });
});
