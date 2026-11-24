import { expect, test } from '@playwright/test';
import { mailFor, submitApplication } from './helpers';

test('US1 : un visiteur dépose une demande complète, la suit et reçoit l’accusé', async ({
  page,
  request,
}) => {
  const number = await submitApplication(page, 'us1');
  expect(number).toMatch(/^PH-\d{4}-\d{4}$/);

  await page.getByRole('link', { name: 'Suivre ma demande' }).click();
  await expect(page.getByText('Demande reçue, vérification en cours')).toBeVisible();
  await expect(page.getByText(number)).toBeVisible();

  const mail = await mailFor(request, 'owner-us1@e2e.test', /bien reçue/);
  expect(mail.text).toContain(number);
});

test('US1 : les champs obligatoires bloquent l’étape et un doublon est refusé', async ({
  page,
}) => {
  await page.goto('/inscription');
  await page.getByRole('button', { name: 'Continuer' }).click();
  await expect(page.getByText('Champ obligatoire').first()).toBeVisible();
  await expect(page.getByRole('heading', { name: 'Présentez votre officine' })).toBeVisible();
});

test('US1 : une réponse invalide de l’API n’affiche jamais « Demande envoyée »', async ({
  page,
}) => {
  await page.route('**/api/public/applications', (route) =>
    route.fulfill({ status: 200, contentType: 'text/html', body: '<b>Fatal error</b>' }),
  );
  await submitApplication(page, 'echec', { stopBeforeSubmit: true });
  await page.getByRole('button', { name: 'Envoyer ma demande' }).click();
  await expect(page.getByRole('alert')).toContainText('Vérifiez les champs signalés');
  await expect(page.getByRole('heading', { name: 'Demande envoyée' })).toHaveCount(0);
});
