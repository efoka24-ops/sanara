import { expect, test } from '@playwright/test';
import { loginAs } from './helpers';

test('un bouton « Retour » ramène à l’écran précédent, ou au parent d’un écran ouvert directement', async ({
  page,
}) => {
  const back = page.getByRole('button', { name: '← Retour' });

  // Site public : présent partout sauf à l'accueil.
  await page.goto('/');
  await expect(page.getByRole('heading', { name: /Trouvez votre pharmacie/ })).toBeVisible();
  await expect(back).toHaveCount(0);
  await page.getByRole('link', { name: 'Trouver une pharmacie' }).click();
  await back.click();
  await expect(page.getByRole('heading', { name: /Trouvez votre pharmacie/ })).toBeVisible();
  await page.goto('/pharmacies');
  await back.click();
  await expect(page).toHaveURL(/\/$/);

  // Demande d'accès : un seul retour, qui recule d'une étape puis quitte le formulaire.
  await page.getByRole('link', { name: 'Digitaliser ma pharmacie' }).click();
  await expect(page.getByRole('button', { name: /Retour/ })).toHaveCount(1);
  await page.getByRole('button', { name: 'Retour', exact: true }).click();
  await expect(page.getByRole('heading', { name: /Trouvez votre pharmacie/ })).toBeVisible();

  // Espace pharmacie : rien sur le tableau de bord, retour ailleurs.
  await loginAs(page, 'owner@demo.test');
  await expect(back).toHaveCount(0);
  await page.getByRole('link', { name: 'Produits' }).click();
  await page.getByRole('link', { name: 'Stock' }).click();
  await back.click();
  await expect(page).toHaveURL(/\/app\/produits$/);
  await back.click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
  if (process.env.SHOTS) {
    await page.getByRole('link', { name: 'Clients et crédit' }).click();
    await page.screenshot({ path: `${process.env.SHOTS}/retour.png` });
  }
});
