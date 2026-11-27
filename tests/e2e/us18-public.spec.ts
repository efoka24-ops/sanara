import { expect, test } from '@playwright/test';
import { apiAs } from './helpers';

test('US18 : depuis l’accueil, un visiteur trouve une pharmacie publiée, pas une pharmacie masquée', async ({
  page,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('PUT', '/app/visibility', { visible: true });

  await page.goto('/');
  await page.getByRole('link', { name: 'Trouver une pharmacie' }).click();
  await expect(page.getByRole('heading', { name: 'Trouver une pharmacie' })).toBeVisible();
  await page.getByLabel('Nom, ville ou quartier').fill('démo');
  await expect(page.getByRole('heading', { name: 'Pharmacie Démo' })).toBeVisible();

  await page.getByLabel('Nom, ville ou quartier').fill('introuvable');
  await expect(page.getByText('Pharmacies trouvées : 0')).toBeVisible();

  await owner('PUT', '/app/visibility', { visible: false });
  await page.getByLabel('Nom, ville ou quartier').fill('démo');
  await expect(page.getByText('Pharmacies trouvées : 0')).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Pharmacie Démo' })).toHaveCount(0);
});

test('US1 : le bouton d’accueil ouvre le formulaire de demande d’accès', async ({ page }) => {
  await page.goto('/');
  await page.getByRole('link', { name: 'Digitaliser ma pharmacie' }).click();
  await expect(page).toHaveURL(/\/inscription$/);
  await expect(page.getByLabel(/Nom de l’officine/)).toBeVisible();
});

test('l’application Windows se télécharge depuis le site, avec sa version publiée', async ({
  page,
}) => {
  await page.route('**/desktop-releases/latest.yml', (r) =>
    r.fulfill({
      contentType: 'text/yaml',
      body: "version: 0.2.0\nfiles:\n  - url: Sanara-Setup-0.2.0.exe\n    size: 105906176\npath: Sanara-Setup-0.2.0.exe\nreleaseDate: '2026-11-27T17:26:22.000Z'\n",
    }),
  );
  await page.goto('/');
  await page.getByRole('link', { name: 'Application Windows' }).first().click();
  await expect(page.getByRole('heading', { name: /Sanara sur votre ordinateur/ })).toBeVisible();
  await expect(
    page.getByRole('link', { name: 'Télécharger pour Windows (version 0.2.0)' }),
  ).toHaveAttribute('href', '/desktop-releases/Sanara-Setup-0.2.0.exe');
  await expect(page.getByText('Environ 101 Mo · publiée le 2026-11-27')).toBeVisible();

  await page.unroute('**/desktop-releases/latest.yml');
  await page.route('**/desktop-releases/latest.yml', (r) => r.fulfill({ status: 404, body: '' }));
  await page.reload();
  await expect(page.getByText('L’application sera bientôt disponible ici.')).toBeVisible();
});
