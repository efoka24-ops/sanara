import fs from 'node:fs';
import { expect, type APIRequestContext, type Page } from '@playwright/test';
import { E2E } from '../../playwright.config';

export const PDF = Buffer.from('%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF');

/** Remplit le formulaire d'accès en 4 étapes (US1) et retourne le numéro de demande. */
export async function submitApplication(
  page: Page,
  suffix: string,
  opts: { stopBeforeSubmit?: boolean } = {},
): Promise<string> {
  await page.goto('/inscription');
  await page.getByLabel(/Nom de l’officine/).fill(`Pharmacie E2E ${suffix}`);
  await page.getByLabel(/N° d’agrément/).fill(`E2E-${suffix}`);
  await page.getByLabel(/Date d’agrément/).fill('2019-03-14');
  await page.getByLabel(/Téléphone de l’officine/).fill('+237 6 00 00 00 01');
  await page.getByRole('button', { name: 'Continuer' }).click();
  await page.getByLabel(/^Région/).fill('Nord');
  await page.getByLabel(/^Département/).fill('Bénoué');
  await page.getByLabel(/^Ville/).fill('Garoua');
  await page.getByLabel(/^Quartier/).fill('Plateau');
  await page.getByRole('button', { name: 'Continuer' }).click();
  const files = page.locator('input[type=file]');
  for (let i = 0; i < 4; i++)
    await files
      .nth(i)
      .setInputFiles({ name: `doc${i}.pdf`, mimeType: 'application/pdf', buffer: PDF });
  await expect(page.getByText(/Ajouté/)).toHaveCount(4);
  await page.getByRole('button', { name: 'Continuer' }).click();
  await page.getByLabel(/^Nom \*/).fill('Mballa');
  await page.getByLabel(/^Prénom/).fill('Alain');
  await page.getByLabel(/N° d’ordre/).fill('ONPC-2214');
  await page.getByLabel(/Téléphone mobile/).fill('+237 6 00 00 00 02');
  await page.getByLabel(/E-mail de connexion/).fill(`owner-${suffix}@e2e.test`);
  for (const box of await page.getByRole('checkbox').all()) await box.check();
  if (opts.stopBeforeSubmit) return '';
  await page.getByRole('button', { name: 'Envoyer ma demande' }).click();
  await expect(page.getByRole('heading', { name: 'Demande envoyée' })).toBeVisible();
  return (await page.locator('.big-number b').innerText()).trim();
}

/** Déclenche le worker puis retourne le dernier e-mail pour ce destinataire et ce sujet (équivalent MailHog). */
export async function mailFor(
  request: APIRequestContext,
  to: string,
  subject: RegExp,
): Promise<{ text: string; subject: string }> {
  const r = await request.get(`http://127.0.0.1:8081/internal/run-jobs?key=${E2E.cronKey}`);
  expect(r.ok()).toBeTruthy();
  const files = fs.existsSync(E2E.mail) ? fs.readdirSync(E2E.mail).sort().reverse() : [];
  for (const f of files) {
    const m = JSON.parse(fs.readFileSync(`${E2E.mail}/${f}`, 'utf8')) as {
      to: string;
      subject: string;
      text: string;
    };
    if (m.to === to && subject.test(m.subject)) return m;
  }
  throw new Error(`Aucun e-mail « ${subject} » pour ${to}`);
}

const API = 'http://127.0.0.1:8081';
export const DEMO_PW = 'Demo#Motdepasse1';

/** Appels API directs pour préparer des données (le navigateur garde son jeton en mémoire seulement). */
export async function apiAs(request: APIRequestContext, email: string) {
  const login = await request.post(`${API}/auth/login`, { data: { email, password: DEMO_PW } });
  const token = ((await login.json()) as { access: string }).access;
  return async <T = Record<string, unknown>>(
    method: string,
    path: string,
    data?: unknown,
  ): Promise<T> => {
    const r = await request.fetch(`${API}${path}`, {
      method,
      data,
      headers: { Authorization: `Bearer ${token}` },
    });
    if (r.status() === 204) return {} as T;
    return (await r.json()) as T;
  };
}

export async function loginAs(page: Page, email: string): Promise<void> {
  await page.goto('/connexion');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel('Mot de passe').fill(DEMO_PW);
  await page.getByRole('button', { name: 'Se connecter' }).click();
  await expect(page.getByRole('heading', { name: 'Tableau de bord' })).toBeVisible();
}
