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
