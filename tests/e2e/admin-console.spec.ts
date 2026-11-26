import { expect, test } from '@playwright/test';
import { E2E } from '../../playwright.config';
import { apiAs } from './helpers';

const stamp = Date.now().toString(36);

async function adminLogin(page: import('@playwright/test').Page) {
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill(E2E.admin.email);
  await page.getByLabel('Mot de passe').fill(E2E.admin.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
}

test('console : chaque entrée du menu ouvre son écran et le bouton retour revient à la liste', async ({
  page,
}) => {
  await adminLogin(page);
  for (const [link, heading] of [
    ['Demandes d’accès', 'Demandes d’accès'],
    ['Pharmacies', 'Pharmacies'],
    ['Abonnements', 'Formules d’abonnement'],
    ['Facturation', 'Facturation'],
    ['Signalements', 'Signalements et modération'],
    ['Modèles d’e-mails', 'Modèles d’e-mails et de messages'],
    ['Journal d’audit', 'Journal d’audit'],
    ['Pays et zones', 'Pays et zones'],
    ['Paramètres', 'Paramètres globaux'],
  ] as const) {
    await page.getByRole('link', { name: link, exact: true }).click();
    await expect(page.getByRole('heading', { name: heading, exact: true })).toBeVisible();
  }
  await page.getByRole('link', { name: 'Pharmacies', exact: true }).click();
  await page.getByRole('link', { name: 'Pharmacie Démo' }).click();
  await expect(page.getByRole('heading', { name: /Pharmacie Démo/ })).toBeVisible();
  await page
    .getByRole('button', { name: /Pharmacies/ })
    .first()
    .click();
  await expect(page.getByRole('heading', { name: 'Pharmacies', exact: true })).toBeVisible();
});

test('console : suspension d’une pharmacie, facture saisie à la main, modèle d’e-mail modifié', async ({
  page,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  expect(owner).toBeTruthy();
  await adminLogin(page);

  // Formule, abonnement et facture
  await page.getByRole('link', { name: 'Abonnements' }).click();
  await page.getByRole('button', { name: '+ Nouvelle formule' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Code').fill(`net${stamp}`.slice(0, 20));
  await dlg.getByLabel('Nom', { exact: true }).fill(`Réseau ${stamp}`);
  await dlg.getByLabel('Prix mensuel (FCFA)').fill('90000');
  await dlg.getByLabel('Postes inclus').fill('12');
  await dlg.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText(`Réseau ${stamp}`)).toBeVisible();

  await page.getByRole('link', { name: 'Pharmacies', exact: true }).click();
  await page.getByRole('link', { name: 'Pharmacie Démo' }).click();
  // Formules triées par prix : « Essentiel » (1 poste) puis « Standard » (3 postes)
  await page.getByLabel('Formule').selectOption({ index: 1 });
  await page.getByLabel('Postes', { exact: true }).fill('3');
  await expect(page.getByRole('alert')).toContainText('entre 1 et 1');
  await expect(page.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
  await page.getByLabel('Formule').selectOption({ index: 2 });
  await expect(page.getByText('3 au maximum')).toBeVisible();
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Paramètres enregistrés.')).toBeVisible();

  await page.getByRole('link', { name: 'Facturation' }).click();
  await page.getByRole('button', { name: '+ Nouvelle facture' }).click();
  await page.getByRole('dialog').getByLabel('Pharmacie').selectOption({ label: 'Pharmacie Démo' });
  await page.getByRole('dialog').getByLabel('Montant (FCFA)').fill('90000');
  await page.getByRole('dialog').getByRole('button', { name: 'Enregistrer' }).click();
  await page.getByRole('button', { name: 'Enregistrer un paiement' }).first().click();
  await page
    .getByRole('dialog')
    .getByLabel('Référence')
    .fill('MP-' + stamp);
  await page.getByRole('dialog').getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('MP-' + stamp)).toBeVisible();

  // Modèle d'e-mail : une variable inconnue est refusée, un texte valide est enregistré
  await page.getByRole('link', { name: 'Modèles d’e-mails' }).click();
  await page.getByLabel('Message').fill('Bonjour {{inconnue}}');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByRole('alert')).toContainText('Variable inconnue : inconnue');
  await page
    .getByLabel('Message')
    .fill('Bonjour {{responsable}}, activez {{pharmacie}} : {{lien_activation}} ({{duree_lien}})');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Modèle enregistré.')).toBeVisible();
  await page.getByRole('button', { name: 'Aperçu' }).click();
  await expect(page.getByLabel('Aperçu')).toContainText('Bonjour [responsable]');
  await page.getByRole('button', { name: 'Rétablir le texte d’origine' }).click();

  // Suspension puis réactivation
  await page.getByRole('link', { name: 'Pharmacies', exact: true }).click();
  await page.getByRole('link', { name: 'Pharmacie Démo' }).click();
  await page.getByRole('button', { name: 'Suspendre la pharmacie' }).click();
  await page.getByRole('dialog').getByLabel('Motif de la suspension').fill('Impayés répétés');
  await page.getByRole('dialog').getByRole('button', { name: 'Suspendre la pharmacie' }).click();
  await expect(page.getByText('Suspendue').first()).toBeVisible();
  const login = await request.post('http://127.0.0.1:8081/auth/login', {
    data: { email: 'owner@demo.test', password: 'Demo#Motdepasse1' },
  });
  expect(login.status()).toBe(403);
  await page.getByRole('button', { name: 'Réactiver' }).click();
  await expect(page.getByRole('button', { name: 'Suspendre la pharmacie' })).toBeVisible();

  // Le journal garde la trace des décisions
  await page.getByRole('link', { name: 'Journal d’audit' }).click();
  await page.getByLabel('Action (début du nom)').fill('pharmacy.');
  await expect(page.getByText('pharmacy.suspend')).toBeVisible();
});
