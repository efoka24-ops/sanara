import { expect, test } from '@playwright/test';
import { E2E } from '../../playwright.config';
import { mailFor } from './helpers';
import { totp } from './totp';

async function login(page: import('@playwright/test').Page, email: string, password: string) {
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(password);
  await page.getByRole('button', { name: 'Se connecter' }).click();
}

test('US5 : activation de la double vérification, connexion en deux étapes, code de secours à usage unique', async ({
  page,
}) => {
  await login(page, E2E.admin.email, E2E.admin.password);
  await expect(page.getByRole('heading', { name: 'Demandes d’accès' })).toBeVisible();

  await page.getByRole('link', { name: 'Sécurité' }).click();
  await page.getByRole('button', { name: 'Configurer' }).click();
  const secret = (await page.locator('code').first().innerText()).trim();
  await page.getByLabel(/Code à 6 chiffres/).fill(totp(secret));
  await page.getByRole('button', { name: 'Activer', exact: true }).click();
  await expect(page.locator('.codes code')).toHaveCount(8);
  const backup = (await page.locator('.codes code').allInnerTexts()).map((s) => s.trim());
  expect(backup).toHaveLength(8);

  // Nouvelle connexion : le mot de passe ne suffit plus
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await login(page, E2E.admin.email, E2E.admin.password);
  await expect(page.getByRole('heading', { name: 'Double vérification' })).toBeVisible();
  await page.getByLabel(/Code à 6 chiffres/).fill('000000');
  await page.getByRole('button', { name: 'Valider' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
  await page.getByLabel(/Code à 6 chiffres/).fill(totp(secret, Math.floor(Date.now() / 1000) + 30)); // pas suivant : le code déjà utilisé est refusé
  await page.getByRole('button', { name: 'Valider' }).click();
  await expect(page.getByRole('heading', { name: 'Demandes d’accès' })).toBeVisible();

  // Code de secours : fonctionne une fois seulement
  await page.context().clearCookies();
  await page.goto('/connexion');
  await login(page, E2E.admin.email, E2E.admin.password);
  await page.getByLabel(/Code à 6 chiffres/).fill(backup[0]!);
  await page.getByRole('button', { name: 'Valider' }).click();
  await expect(page.getByRole('heading', { name: 'Demandes d’accès' })).toBeVisible();
  await page.goto('/connexion');
  await login(page, E2E.admin.email, E2E.admin.password);
  await page.getByLabel(/Code à 6 chiffres/).fill(backup[0]!);
  await page.getByRole('button', { name: 'Valider' }).click();
  await expect(page.getByRole('alert')).toBeVisible();
});

test('US5 : mot de passe oublié, lien à usage unique, ancien mot de passe refusé', async ({
  page,
  request,
}) => {
  await page.goto('/mot-de-passe-oublie');
  await page.getByLabel('E-mail').fill(E2E.admin.email);
  await page.getByRole('button', { name: 'Envoyer le lien' }).click();
  await expect(page.getByText(/Si un compte existe/)).toBeVisible();

  const mail = await mailFor(request, E2E.admin.email, /Réinitialisation/);
  const link = mail.text.match(/http:\/\/127\.0\.0\.1:5173\/reinitialiser\/[\w-]+/)?.[0];
  expect(link).toBeTruthy();
  await page.goto(link!);
  await page.getByLabel('Mot de passe').fill(E2E.admin.password);
  await page.getByRole('button', { name: 'Changer le mot de passe' }).click();
  await expect(page.getByText('Ce mot de passe a déjà été utilisé récemment.')).toBeVisible();
  await page.getByLabel('Mot de passe').fill('Nouveau#E2e-Pass2');
  await page.getByRole('button', { name: 'Changer le mot de passe' }).click();
  await expect(page.getByText('Mot de passe changé')).toBeVisible();
  await page.goto(link!);
  await expect(page.getByText('Ce lien est invalide ou expiré.')).toBeVisible();
});
