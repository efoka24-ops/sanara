import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const stamp = Date.now().toString(36);

test('US13 : compte client, vente à crédit plafonnée et règlement avec reçu', async ({
  page,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  const product = `Crédit ${stamp}`;
  const { id } = await owner<{ id: string }>('POST', '/app/products', {
    name: product,
    regulatory_status: 'free',
    sale_price: 1000,
    purchase_price: 600,
    vat_bp: 0,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: id,
    lot_number: `C-${stamp}`,
    expiry: '2031-01-01',
    quantity: 10,
    unit_cost: 600,
  });
  const client = `Client ${stamp}`;

  await loginAs(page, 'owner@demo.test');
  await page.getByRole('navigation').getByRole('link', { name: 'Clients et crédit' }).click();
  await page.getByRole('button', { name: 'Nouveau client' }).click();
  const form = page.getByRole('dialog', { name: 'Nouveau client' });
  await form.getByLabel('Nom').fill(client);
  await form.getByLabel('Plafond de crédit').fill('2500');
  await form.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByRole('row', { name: new RegExp(client) })).toBeVisible();

  await page.getByRole('navigation').getByRole('link', { name: 'Caisse' }).click();
  const open = page.getByRole('button', { name: 'Ouvrir la caisse' });
  const search = page.getByLabel('Scanner ou chercher un produit (F1)');
  await expect(open.or(search)).toBeVisible();
  if (await open.isVisible()) {
    await page.getByLabel('Nom du poste').fill('Credit');
    await open.click();
  }
  const add = async () => {
    await search.fill(product);
    await page
      .getByRole('button', { name: new RegExp(product) })
      .first()
      .click();
  };
  await add();
  await page.getByLabel(`Qté ${product}`).fill('2');
  await page.keyboard.press('F8');
  const picker = page.getByRole('dialog', { name: /Client/ });
  await picker.getByRole('textbox').fill(client);
  await picker.getByRole('button', { name: 'Choisir' }).click();
  await page.keyboard.press('F9');
  const pay = page.getByRole('dialog', { name: 'Règlement' });
  await pay.getByRole('combobox').first().selectOption('credit');
  await expect(pay.getByText(/disponible 2\s500/)).toBeVisible();
  await pay.getByRole('button', { name: 'Valider la vente' }).click();
  await page.getByRole('button', { name: 'Fermer' }).click();

  // Au-delà du plafond, la vente est refusée.
  await add();
  await page.keyboard.press('F8');
  await picker.getByRole('textbox').fill(client);
  await picker.getByRole('button', { name: 'Choisir' }).click();
  await page.keyboard.press('F9');
  await pay.getByRole('combobox').first().selectOption('credit');
  await pay.getByRole('button', { name: 'Valider la vente' }).click();
  await expect(pay.getByText('Plafond de crédit dépassé.')).toBeVisible();
  await pay.getByRole('button', { name: 'Annuler' }).click();

  await page.getByRole('navigation').getByRole('link', { name: 'Clients et crédit' }).click();
  await page.getByLabel('Nom ou téléphone').fill(client);
  await page
    .getByRole('row', { name: new RegExp(client) })
    .getByRole('button')
    .click();
  const statement = page.getByRole('dialog', { name: `Relevé · ${client}` });
  await expect(statement.locator('.pos__total b')).toContainText(/2\s000/);
  await statement.getByLabel('Montant').fill('1500');
  await statement.getByRole('button', { name: 'Enregistrer le règlement' }).click();
  await expect(page.frameLocator('iframe').getByText(/Reçu REC-/)).toBeVisible();
  await page.getByRole('button', { name: 'Fermer' }).click();
  await expect(statement.locator('.pos__total b')).toContainText(/500/);

  // La caisse est rendue dans son état initial pour les scénarios suivants.
  const { session } = await owner<{ session: { id: string } | null }>('GET', '/app/cash/current');
  if (session) {
    const { totals } = await owner<{ totals: { expected_cash: number } }>(
      'GET',
      `/app/cash/sessions/${session.id}/preview`,
    );
    await owner('POST', `/app/cash/sessions/${session.id}/close`, {
      counts: [{ denomination: 500, qty: totals.expected_cash / 500 }],
    });
  }
});
