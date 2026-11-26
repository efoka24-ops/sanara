import { expect, test } from '@playwright/test';
import { E2E } from '../../playwright.config';
import { mailFor, submitApplication } from './helpers';

test('US2 : soumission, validation par le super admin, e-mail d’activation, première connexion', async ({
  page,
  request,
}) => {
  const number = await submitApplication(page, 'us2');

  // Super admin : connexion puis examen de la demande
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill(E2E.admin.email);
  await page.getByLabel('Mot de passe').fill(E2E.admin.password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
  await page.getByRole('link', { name: 'Demandes d’accès' }).click();
  await expect(page.getByRole('heading', { name: 'Demandes d’accès' })).toBeVisible();
  await page
    .getByRole('row', { name: new RegExp(number) })
    .getByRole('link', { name: /Examiner/ })
    .click();
  await expect(page.getByRole('heading', { name: /Pharmacie E2E us2/ })).toBeVisible();
  await expect(page.getByRole('link', { name: 'Agrément d’ouverture' })).toBeVisible();
  await page.getByRole('button', { name: 'Valider et envoyer l’accès' }).click();
  await expect(page.getByText('Décision enregistrée.')).toBeVisible();

  // E-mail d'activation reçu (lien valable 48 h), puis activation du compte
  const mail = await mailFor(request, 'owner-us2@e2e.test', /accès Sanara est prêt/);
  const link = mail.text.match(/http:\/\/127\.0\.0\.1:5173\/activer\/[\w-]+/)?.[0];
  expect(link, 'lien d’activation dans l’e-mail').toBeTruthy();
  await page.context().clearCookies();
  await page.goto(link!);
  await page.getByLabel('Mot de passe', { exact: true }).fill('Pharma#Secure2026');
  await page.getByLabel('Confirmation').fill('Pharma#Secure2026');
  await page.getByRole('button', { name: 'Activer mon compte' }).click();
  await expect(page.getByText('Compte activé')).toBeVisible();

  // Première connexion du titulaire : tableau de bord avec la configuration à terminer
  await page.getByRole('link', { name: 'Se connecter' }).click();
  await page.getByLabel('E-mail').fill('owner-us2@e2e.test');
  await page.getByLabel('Mot de passe').fill('Pharma#Secure2026');
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('link', { name: 'Reprendre la configuration' })).toBeVisible();

  // Le lien est à usage unique
  await page.goto(link!);
  await expect(page.getByText('Ce lien est invalide ou expiré.')).toBeVisible();
});

test('US2 : un lien expiré peut être renvoyé sans révéler si le compte existe', async ({
  page,
}) => {
  await page.goto('/activer/jeton-invalide');
  await expect(page.getByText('Ce lien est invalide ou expiré.')).toBeVisible();
  await page.getByLabel('E-mail').fill('inconnu@e2e.test');
  await page.getByRole('button', { name: 'Envoyer un nouveau lien' }).click();
  await expect(page.getByText(/Si un compte est en attente/)).toBeVisible();
});
