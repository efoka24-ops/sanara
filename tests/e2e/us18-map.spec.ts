import { expect, test, type Page } from '@playwright/test';
import { apiAs } from './helpers';

// Image d'un pixel : les tests ne téléchargent aucune tuile.
const TILE = Buffer.from(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
  'base64',
);
const offlineTiles = (page: Page) =>
  page.route('https://tile.openstreetmap.org/**', (r) =>
    r.fulfill({ contentType: 'image/png', body: TILE }),
  );

/** Ouverte tous les jours, toute la journée : le résultat ne dépend pas de l'heure du test. */
const ALWAYS = [1, 2, 3, 4, 5, 6, 7].map((weekday) => ({
  weekday,
  is_open: true,
  opens: '00:00',
  closes: '23:59',
}));

test.beforeEach(async ({ page, request }) => {
  await offlineTiles(page);
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });
  await owner('PUT', '/app/opening-hours', { days: ALWAYS });
});

test.afterEach(async ({ request }) => {
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: false });
});

test('US18 : « Ma position » trie par distance, avec le statut d’ouverture et un repère sur la carte', async ({
  page,
  context,
}) => {
  await context.grantPermissions(['geolocation']);
  await context.setGeolocation({ latitude: 9.3062, longitude: 13.3921 });

  await page.goto('/');
  await page.getByLabel('Ville ou quartier').fill('démo');
  await page.getByRole('button', { name: 'Ma position' }).click();

  await expect(page).toHaveURL(/\/pharmacies\?q=d%C3%A9mo$/); // la position n'est pas dans l'adresse
  const card = page.getByRole('listitem').filter({ hasText: 'Pharmacie Démo' });
  await expect(card.getByText('480 m')).toBeVisible();
  await expect(card.getByText(/Ouverte jusqu’à 23 h 59/)).toBeVisible();
  await expect(card.getByRole('link', { name: 'Itinéraire' })).toHaveAttribute(
    'href',
    /directions\?to=9\.3017%2C13\.3921/,
  );
  await expect(page.getByRole('button', { name: 'Plus proche' })).toHaveAttribute(
    'aria-pressed',
    'true',
  );

  const marker = page.locator('.map-pin');
  await expect(marker).toHaveCount(1);
  await marker.hover();
  await expect(page.locator('.leaflet-tooltip')).toContainText('Pharmacie Démo');
  await expect(page.locator('.leaflet-tooltip')).toContainText('480 m');
  await marker.click();
  await expect(card).toHaveClass(/found--on/);
  if (process.env.SHOTS) await page.screenshot({ path: `${process.env.SHOTS}/recherche.png` });
});

test('US18 : sans position, le visiteur est invité à saisir son quartier ; filtre « Ouvert maintenant »', async ({
  page,
  request,
}) => {
  await page.goto('/pharmacies');
  await page.getByRole('button', { name: 'Plus proche' }).click(); // permission non accordée
  await expect(page.getByText(/Indiquez votre quartier/)).toBeVisible();
  await expect(page.getByLabel('Votre quartier')).toBeVisible();

  await page.getByRole('button', { name: 'Ouvert maintenant' }).click();
  await expect(page).toHaveURL(/open=1/);
  await expect(page.getByRole('heading', { name: 'Pharmacie Démo' })).toBeVisible();

  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/opening-hours', {
    days: ALWAYS.map((d) => ({ weekday: d.weekday, is_open: false })),
  });
  await page.reload();
  await expect(page.getByText('Pharmacies trouvées : 0')).toBeVisible();
});

test('P1 : l’accueil sur téléphone garde la recherche et la demande d’accès', async ({ page }) => {
  await page.setViewportSize({ width: 360, height: 740 });
  await page.goto('/');
  await expect(page.getByRole('button', { name: 'Rechercher une pharmacie' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Demander mon accès' })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Pharmacies de garde' })).toBeVisible();
  // Aucun défilement horizontal.
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(360);
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/accueil-mobile.png`, fullPage: true });
  await page.setViewportSize({ width: 1280, height: 800 });
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/accueil-large.png`, fullPage: true });
});
