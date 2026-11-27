import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const stamp = Date.now().toString(36);
const CATALOG = 1500;
const BUDGET_MS = 200;
const SCANS = 5;

/** T111 : au comptoir, un article scanné apparaît dans le panier en moins de 200 ms. */
test('US10 : ajout d’un article scanné en moins de 200 ms sur un catalogue de 1 500 produits', async ({
  page,
  request,
}) => {
  test.setTimeout(120_000);
  const owner = await apiAs(request, 'owner@demo.test');
  const code = (n: number) => `61${Date.now().toString().slice(-8)}${String(n).padStart(3, '0')}`;
  const scanned = Array.from({ length: SCANS }, (_, i) => ({
    name: `Scan ${stamp} ${i}`,
    barcode: code(i),
  }));
  const rows = Array.from({ length: CATALOG }, (_, i) => `Volume ${stamp} ${i};free;100;`);
  const csv = [
    'name;status;sale;barcode',
    ...rows,
    ...scanned.map((p) => `${p.name};free;500;${p.barcode}`),
  ].join('\n');
  const report = await owner<{ created: number }>('POST', '/app/products/import', { csv });
  expect(report.created).toBe(CATALOG + SCANS);

  await loginAs(page, 'cashier@demo.test');
  await page.getByRole('link', { name: 'Caisse' }).click();
  const open = page.getByRole('button', { name: 'Ouvrir la caisse' });
  const search = page.getByLabel('Scanner ou chercher un produit (F1)');
  await expect(open.or(search)).toBeVisible();
  if (await open.isVisible()) {
    await page.getByLabel('Nom du poste').fill('Perf');
    await open.click();
  }

  const times: number[] = [];
  for (const product of scanned) {
    // Comme un lecteur : les chiffres puis Entrée, sans pause.
    await search.pressSequentially(product.barcode, { delay: 2 });
    // Mesure prise dans la page : de la touche Entrée à la ligne du panier.
    await page.evaluate((name) => {
      const w = window as unknown as { added: Promise<number> };
      w.added = new Promise((resolve) => {
        let start = 0;
        document.addEventListener('keydown', () => (start ||= performance.now()), true);
        new MutationObserver((_, observer) => {
          const rows = [...document.querySelectorAll('table button')];
          if (start && rows.some((b) => b.textContent === name)) {
            observer.disconnect();
            resolve(performance.now() - start);
          }
        }).observe(document.body, { childList: true, subtree: true });
      });
    }, product.name);
    await search.press('Enter');
    times.push(
      Math.round(
        await page.evaluate(() => (window as unknown as { added: Promise<number> }).added),
      ),
    );
    // Dans le panier, et non dans la liste des résultats.
    await expect(page.getByRole('button', { name: product.name, exact: true })).toBeVisible();
    await expect(search).toHaveValue('');
  }
  const median = [...times].sort((a, b) => a - b)[Math.floor(times.length / 2)]!;
  console.log(`ajout d'un article : ${times.join(', ')} ms (médiane ${median} ms)`);
  expect(median).toBeLessThan(BUDGET_MS);

  // La caisse est rendue dans son état initial pour les scénarios suivants.
  const cashier = await apiAs(request, 'cashier@demo.test');
  const { session } = await cashier<{ session: { id: string } | null }>('GET', '/app/cash/current');
  if (session) await cashier('POST', `/app/cash/sessions/${session.id}/close`, { counts: [] });
  expect((await cashier<{ session: unknown }>('GET', '/app/cash/current')).session).toBeNull();
});
