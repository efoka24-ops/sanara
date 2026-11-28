import { expect, test, type Page } from '@playwright/test';

async function loginOwner(page: Page, email = 'owner@demo.test') {
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Mot de passe').fill('Demo#Motdepasse1');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
}

test('US6 : création, recherche, changement de prix tracé, désactivation', async ({ page }) => {
  await loginOwner(page);
  await page.getByRole('navigation').getByRole('link', { name: 'Produits' }).click();
  await page.getByRole('button', { name: '+ Nouveau produit' }).click();
  await page.getByRole('dialog').getByLabel(/^Nom/).fill('Amoxicilline 500 mg');
  await page.getByLabel(/^DCI/).fill('Amoxicilline');
  await page.getByLabel(/Codes-barres/).fill('6000000000012');
  await page.getByLabel(/Statut réglementaire/).selectOption('prescription_only');
  await page.getByLabel(/Prix d’achat/).fill('1950');
  await page.getByLabel(/Prix de vente/).fill('2800');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByRole('button', { name: 'Amoxicilline 500 mg' })).toBeVisible();

  // Recherche par code-barres
  await page.getByLabel(/Nom, DCI ou code-barres/).fill('6000000000012');
  await expect(page.getByRole('button', { name: 'Amoxicilline 500 mg' })).toBeVisible();

  // Changement de prix avec motif, historique visible
  await page.getByRole('button', { name: 'Amoxicilline 500 mg' }).click();
  await page.getByRole('button', { name: 'Modifier' }).click();
  await page.getByLabel(/Prix de vente/).fill('3000');
  await page.getByLabel(/Motif du changement de prix/).fill('Hausse fournisseur');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await page.getByRole('button', { name: 'Amoxicilline 500 mg' }).click();
  await expect(page.getByText('Hausse fournisseur')).toBeVisible();
  await expect(page.getByText(/2\s800 → 3\s000/)).toBeVisible();

  // Doublon de code-barres refusé
  await page.getByRole('button', { name: 'Modifier' }).click();
  await page.getByRole('button', { name: 'Annuler' }).click();
  await page.keyboard.press('Escape');
  await page.getByRole('button', { name: '+ Nouveau produit' }).click();
  await page.getByRole('dialog').getByLabel(/^Nom/).fill('Autre');
  await page.getByLabel(/Codes-barres/).fill('6000000000012');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Ce code-barres est déjà utilisé.')).toBeVisible();
  await page.getByRole('button', { name: 'Annuler' }).click();

  // Désactivation : le produit disparaît de la recherche, revient avec « Afficher les inactifs »
  await page.getByRole('button', { name: 'Amoxicilline 500 mg' }).click();
  await page.getByRole('button', { name: 'Désactiver' }).click();
  await expect(page.getByRole('button', { name: 'Amoxicilline 500 mg' })).toHaveCount(0);
  await page.getByLabel('Afficher les inactifs').check();
  await expect(page.getByRole('button', { name: /Amoxicilline 500 mg/ })).toBeVisible();
});

test('US6 : catalogue Sanara et import CSV avec rapport', async ({ page }) => {
  await loginOwner(page);
  await page.getByRole('navigation').getByRole('link', { name: 'Produits' }).click();
  await page.getByRole('button', { name: 'Ajouter le catalogue Sanara' }).click();
  await expect(page.getByText(/produits ajoutés/)).toBeVisible();
  await page.getByLabel(/Nom, DCI ou code-barres/).fill('paracétamol');
  await expect(page.getByRole('button', { name: /Paracétamol 1 g/ })).toBeVisible();

  const csv = 'name;status;sale\nProduit importé;free;500\n;free;1\n';
  await page
    .locator('input[type=file]')
    .first()
    .setInputFiles({ name: 'c.csv', mimeType: 'text/csv', buffer: Buffer.from(csv) });
  await expect(page.getByText(/1 créés, 0 mis à jour, 1 rejetés/)).toBeVisible();
});

test('US6 : un caissier cherche mais ne modifie pas le catalogue', async ({ page }) => {
  await loginOwner(page, 'cashier@demo.test');
  await page.getByRole('navigation').getByRole('link', { name: 'Produits' }).click();
  await expect(page.getByLabel(/Nom, DCI ou code-barres/)).toBeVisible();
  await expect(page.getByRole('button', { name: '+ Nouveau produit' })).toHaveCount(0);
  await expect(page.getByRole('button', { name: 'Ajouter le catalogue Sanara' })).toHaveCount(0);
});
