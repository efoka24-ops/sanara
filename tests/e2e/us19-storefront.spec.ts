import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const TILE = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);

/** Date du jour au format AAAA-MM-JJ, dans le fuseau de la pharmacie de démonstration. */
const todayInDouala = () =>
  new Intl.DateTimeFormat('en-CA', { timeZone: 'Africa/Douala' }).format(new Date());

/** Photo de façade ajoutée depuis « Ma vitrine ». */
async function staffPhoto(page: import('@playwright/test').Page) {
  await page
    .locator('input[type=file]')
    .setInputFiles({ name: 'facade.png', mimeType: 'image/png', buffer: TILE });
  await expect(page.getByText('Photo 1')).toBeVisible();
}

test.afterEach(async ({ request }) => {
  const owner = await apiAs(request, 'owner@demo.test');
  const store = await owner<{ duties: { id: string }[] }>('GET', '/app/storefront');
  for (const d of store.duties) await owner('DELETE', `/app/storefront/duties/${d.id}`);
  for (const p of (store as unknown as { photos: { id: string }[] }).photos)
    await owner('DELETE', `/app/storefront/photos/${p.id}`);
  await owner('PUT', '/app/visibility', { visible: false });
});

test('US19 : le titulaire tient sa vitrine et sa garde ; le visiteur les voit', async ({
  page,
}) => {
  await page.route('https://tile.openstreetmap.org/**', (r) =>
    r.fulfill({ contentType: 'image/png', body: TILE }),
  );
  await loginAs(page, 'owner@demo.test');
  await page.getByRole('link', { name: 'Ma vitrine' }).click();
  await expect(page.getByRole('heading', { name: 'Ma vitrine' })).toBeVisible();

  await page.getByLabel('Région').fill('Nord');
  await page.getByLabel('Département').fill('Bénoué');
  await page.getByLabel('Ville').fill('Garoua');
  await page.getByLabel('Quartier').fill('Marouaré');
  await page.getByLabel('Téléphone public').fill('+237 6 00 00 00 09');
  await page.getByLabel('Latitude').fill('9,3017');
  await page.getByLabel('Longitude').fill('95000');
  await page.getByRole('button', { name: 'Enregistrer la vitrine' }).click();
  await expect(page.getByText(/Point GPS invalide/)).toBeVisible();
  await page.getByLabel('Longitude').fill('13,3921');
  await page.getByLabel('Tests de paludisme').check();
  await page.getByLabel(/Assurances et mutuelles/).fill('Mutuelle de test');
  await page.getByRole('button', { name: 'Enregistrer la vitrine' }).click();
  await expect(page.getByText('Enregistré').first()).toBeVisible();

  await staffPhoto(page);

  // Garde qui couvre toute la journée en cours.
  await page.getByLabel('Date de début').fill(todayInDouala());
  await page.getByLabel('De', { exact: true }).fill('00:00');
  await page.getByLabel('À', { exact: true }).fill('00:00');
  await page.getByRole('button', { name: 'Ajouter la garde' }).click();
  await expect(page.getByRole('button', { name: 'Supprimer' })).toBeVisible();

  await page.getByRole('switch', { name: 'Afficher ma pharmacie sur la carte publique' }).click();
  await expect(
    page.getByRole('switch', { name: 'Afficher ma pharmacie sur la carte publique' }),
  ).toHaveAttribute('aria-checked', 'true');
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/vitrine.png`, fullPage: true });

  // Côté visiteur : page de garde puis fiche.
  await page.goto('/');
  await page.getByRole('link', { name: 'Pharmacies de garde' }).click();
  await page.getByLabel('Ville').selectOption('Garoua');
  await expect(page.getByRole('heading', { name: 'Pharmacies de garde à Garoua' })).toBeVisible();
  await expect(page.getByText(/De garde jusqu’au/)).toBeVisible();
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/gardes.png`, fullPage: true });
  await page.getByRole('link', { name: 'Pharmacie Démo' }).click();

  await expect(page.getByRole('heading', { name: 'Pharmacie Démo', level: 1 })).toBeVisible();
  await expect(page.getByText('De garde', { exact: true })).toBeVisible();
  await expect(page.getByText('Tests de paludisme')).toBeVisible();
  await expect(page.getByText('Mutuelle de test')).toBeVisible();
  await expect(page.getByRole('link', { name: '+237 6 00 00 00 09' })).toBeVisible();
  await expect(page.locator('.map-pin')).toHaveCount(1);
  await expect(page.getByRole('img', { name: 'Façade de Pharmacie Démo' })).toBeVisible();
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/fiche.png`, fullPage: true });

  // La recherche signale la garde et mène à la même fiche.
  await page.goto('/pharmacies?duty=1');
  await expect(page.getByRole('button', { name: 'De garde' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );
  await page.getByRole('combobox', { name: 'Assurances' }).selectOption('Mutuelle de test');
  await expect(page).toHaveURL(/insurance=Mutuelle/);
  await page.getByRole('link', { name: 'Pharmacie Démo' }).click();
  await expect(page).toHaveURL(/\/pharmacies\/[0-9A-Z]{26}$/);
});

test('US19 : l’adresse d’une pharmacie non publiée ne montre rien', async ({ page }) => {
  await page.goto('/pharmacies/00000000000000000000000000');
  await expect(page.getByRole('alert')).toContainText('pas ou plus publiée');
});
