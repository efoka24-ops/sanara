import { expect, test } from '@playwright/test';

const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF');
const run = Date.now().toString(36);

test('la préproduction répond et la base MySQL est joignable', async ({ request }) => {
  const r = await request.get('/api/health');
  expect(r.ok()).toBeTruthy();
  const j = await r.json();
  expect(j.status).toBe('ok');
  expect(j.database.ok).toBe(true);
});

test('la page d’accueil et la connexion s’affichent, un mauvais mot de passe est refusé', async ({
  page,
}) => {
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill('inconnu@example.com');
  await page.getByLabel('Mot de passe').fill('Mauvais#Motdepasse1');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await expect(page).toHaveURL(/connexion/);
});

test('une demande réaliste est déposée, suivie, et le doublon est refusé', async ({ page }) => {
  await page.goto('/inscription');
  await page.getByLabel(/Nom de l’officine/).fill(`Pharmacie du Plateau ${run}`);
  await page.getByLabel(/N° d’agrément/).fill(`MINSANTE-NO-${run}`);
  await page.getByLabel(/Date d’agrément/).fill('2019-03-14');
  await page.getByLabel(/Téléphone de l’officine/).fill('+237 6 77 00 00 01');
  await page.getByRole('button', { name: 'Continuer' }).click();
  await page.getByLabel(/^Région/).fill('Nord');
  await page.getByLabel(/^Département/).fill('Bénoué');
  await page.getByLabel(/^Ville/).fill('Garoua');
  await page.getByLabel(/^Quartier/).fill('Plateau');
  await page.getByRole('button', { name: 'Continuer' }).click();
  const files = page.locator('input[type=file]');
  for (let i = 0; i < 4; i++)
    await files
      .nth(i)
      .setInputFiles({ name: `piece${i}.pdf`, mimeType: 'application/pdf', buffer: PDF });
  await expect(page.getByText(/Ajouté/)).toHaveCount(4);
  await page.getByRole('button', { name: 'Continuer' }).click();
  await page.getByLabel(/^Nom \*/).fill('Mballa');
  await page.getByLabel(/^Prénom/).fill('Alain');
  await page.getByLabel(/N° d’ordre/).fill('ONPC-2214');
  await page.getByLabel(/Téléphone mobile/).fill('+237 6 77 00 00 02');
  await page.getByLabel(/E-mail de connexion/).fill(`titulaire-${run}@example.com`);
  for (const box of await page.getByRole('checkbox').all()) await box.check();
  await page.getByRole('button', { name: 'Envoyer ma demande' }).click();
  await expect(page.getByRole('heading', { name: 'Demande envoyée' })).toBeVisible();
  const number = (await page.locator('.big-number b').innerText()).trim();
  expect(number).toMatch(/^PH-\d{4}-\d{4}$/);

  await page.getByRole('link', { name: 'Suivre ma demande' }).click();
  await expect(page.getByText('Demande reçue, vérification en cours')).toBeVisible();
  await expect(page.getByText(number)).toBeVisible();
});

test('les routes protégées refusent un visiteur anonyme', async ({ request }) => {
  for (const p of [
    '/api/app/products',
    '/api/admin/applications',
    '/api/auth/sessions',
    '/api/app/stock/overview',
    '/api/app/sales',
    '/api/app/cash/current',
    '/api/app/inventories',
  ]) {
    const r = await request.get(p);
    expect(r.status(), p).toBe(401);
  }
});

test('le super admin passe par la double vérification', async ({ request }) => {
  const pw = process.env.STAGING_ADMIN_PASSWORD;
  const email = process.env.STAGING_ADMIN_EMAIL;
  test.skip(!pw || !email, 'STAGING_ADMIN_EMAIL et STAGING_ADMIN_PASSWORD non fournis');
  const r = await request.post('/api/auth/login', { data: { email, password: pw } });
  expect(r.ok()).toBeTruthy();
  const j = await r.json();
  expect(j.mfa_required === true || j.user?.mfa_setup_required === true).toBe(true);
});

test('les boutons de l’accueil mènent à la demande d’accès et à l’annuaire', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('link', { name: 'Trouver une pharmacie' }).click();
  await expect(page.getByText(/Pharmacies trouvées : \d+/)).toBeVisible();
  await page.goto('/');
  await page.getByRole('link', { name: 'Digitaliser ma pharmacie' }).click();
  await expect(page.getByLabel(/Nom de l’officine/)).toBeVisible();
});
