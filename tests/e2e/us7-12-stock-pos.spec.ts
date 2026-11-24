import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const stamp = Date.now().toString(36);

async function productWithStock(
  request: Parameters<typeof apiAs>[0],
  name: string,
  status: string,
  price: number,
  qty: number,
) {
  const owner = await apiAs(request, 'owner@demo.test');
  const p = await owner<{ id: string }>('POST', '/app/products', {
    name,
    regulatory_status: status,
    sale_price: price,
    purchase_price: Math.round(price * 0.7),
    vat_bp: 1925,
    min_stock: 5,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: p.id,
    lot_number: `LOT-${stamp}`,
    expiry: '2031-06-30',
    quantity: qty,
    unit_cost: Math.round(price * 0.7),
  });
  return p.id;
}

test('US10-US11 : le caissier ouvre sa caisse, vend en espèces, imprime le ticket et clôture', async ({
  page,
  request,
}) => {
  const name = `Paracétamol ${stamp}`;
  await productWithStock(request, name, 'free', 1500, 12);
  await loginAs(page, 'cashier@demo.test');
  await page.getByRole('link', { name: 'Caisse' }).click();

  await expect(page.getByText('Ouvrez votre caisse pour commencer à vendre.')).toBeVisible();
  await page.getByLabel('Nom du poste').fill('Caisse 1');
  await page.getByLabel('Fond de caisse').fill('5000');
  await page.getByRole('button', { name: 'Ouvrir la caisse' }).click();

  await page.getByLabel('Scanner ou chercher un produit (F1)').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await expect(page.getByRole('button', { name: name })).toBeVisible();
  await page.getByLabel(`Qté ${name}`).fill('3');
  await expect(page.locator('.pos__total b').first()).toContainText(/4\s500/);

  await page.keyboard.press('F9');
  const pay = page.getByRole('dialog', { name: 'Règlement' });
  await pay.getByLabel('Montant').fill('5000');
  await expect(pay.getByText(/Rendu monnaie/)).toBeVisible();
  await pay.getByRole('button', { name: 'Valider la vente' }).click();

  const ticket = page.frameLocator('iframe[title^="V-CAISSE1-"]');
  await expect(ticket.getByText('TOTAL TTC')).toBeVisible();
  await expect(ticket.getByText(name)).toBeVisible();
  await page.getByRole('button', { name: 'Fermer' }).click();
  await expect(page.getByText(/Vente enregistrée V-CAISSE1-\d{4}-00001/)).toBeVisible();

  // Clôture : 5 000 de fond + 4 500 de ventes = 9 500 en espèces (5 000 + 2 000×2 + 500)
  await page.getByRole('button', { name: /Clôturer la caisse/ }).click();
  const close = page.getByRole('dialog', { name: 'Clôture de caisse' });
  await close.getByLabel('Coupure 5 000').fill('1');
  await close.getByLabel('Coupure 2 000').fill('2');
  await close.getByLabel(/Coupure 500$/).fill('1');
  await expect(close.getByText('0 FCFA').first()).toBeVisible();
  await close.getByRole('button', { name: 'Clôturer', exact: true }).click();
  await expect(
    page.frameLocator('iframe[title="Rapport Z"]').getByText(/Rapport Z Z-CAISSE1-/),
  ).toBeVisible();
});

test('US10 : un produit sous ordonnance exige ordonnance, pharmacien et PIN', async ({
  page,
  request,
}) => {
  const name = `Amoxicilline ${stamp}`;
  await productWithStock(request, name, 'prescription_only', 2800, 10);
  const pharma = await apiAs(request, 'pharmacist@demo.test');
  await pharma('PUT', '/auth/pin', { pin: '2468' });
  await loginAs(page, 'cashier@demo.test');
  await page.getByRole('link', { name: 'Caisse' }).click();
  await page.getByLabel('Nom du poste').fill('Caisse 2');
  await page.getByRole('button', { name: 'Ouvrir la caisse' }).click();
  await page.getByLabel('Scanner ou chercher un produit (F1)').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await expect(page.getByText('Produit réglementé')).toBeVisible();
  await page.keyboard.press('F9');
  const pay = page.getByRole('dialog', { name: 'Règlement' });
  await pay.getByLabel('Montant').fill('2800');
  await pay.getByRole('button', { name: 'Valider la vente' }).click();
  await expect(pay.getByRole('alert')).toContainText('Ordonnance et pharmacien délivrant requis.');
  await pay.getByLabel('N° d’ordonnance').fill('ORD-118');
  await pay.getByLabel('Prescripteur').fill('Dr Mballa');
  await pay.getByLabel('Patient').fill('Aïcha Bello');
  await pay.getByLabel('Pharmacien délivrant').selectOption({ label: 'pharmacist@demo.test' });
  await pay.getByLabel('Code PIN du responsable').fill('2468');
  await pay.getByRole('button', { name: 'Valider la vente' }).click();
  await expect(
    page.frameLocator('iframe[title^="V-CAISSE2-"]').getByText('Ordonnance ORD-118'),
  ).toBeVisible();
});

test('US7-US8 : réception, quarantaine, inventaire à l’aveugle puis validation', async ({
  page,
  request,
}) => {
  const name = `Sérum ${stamp}`;
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('POST', '/app/products', {
    name,
    regulatory_status: 'free',
    sale_price: 1000,
    purchase_price: 600,
    min_stock: 2,
  });

  await loginAs(page, 'owner@demo.test');
  await page.getByRole('link', { name: 'Stock' }).click();
  await page.getByRole('button', { name: '+ Réceptionner' }).click();
  const dlg = page.getByRole('dialog');
  await dlg.getByLabel('Choisir un produit').fill(name);
  await dlg
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  await dlg.getByLabel('Lot', { exact: true }).fill(`S-${stamp}`);
  await dlg.getByLabel('Péremption').fill('2026-12-20');
  await dlg.getByLabel('Quantité').fill('10');
  await dlg.getByLabel('Prix d’achat unitaire').fill('600');
  await dlg.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Attention : ce lot expire dans moins de 90 jours.')).toBeVisible();

  await page.getByRole('button', { name: name }).click();
  await page.getByRole('button', { name: 'Mettre en quarantaine' }).click();
  await page.getByRole('dialog').getByLabel('Motif').fill('Rappel du fabricant');
  await page.getByRole('button', { name: 'Enregistrer' }).click();
  await expect(page.getByText('Quarantaine').first()).toBeVisible();
  await page.getByRole('button', { name: 'Libérer' }).click();
  await expect(page.getByText('Disponible').first()).toBeVisible();
  await page.getByRole('button', { name: 'Fermer' }).click();

  // Inventaire à l'aveugle : 8 comptés pour 10 attendus
  await page.getByRole('link', { name: 'Inventaire' }).click();
  await page.getByRole('button', { name: '+ Nouvel inventaire' }).click();
  await page.getByRole('dialog').getByRole('button', { name: 'Enregistrer' }).click();
  await page.getByLabel('Scanner un code-barres ou taper un nom').fill(name);
  await page
    .getByRole('button', { name: new RegExp(name) })
    .first()
    .click();
  const qty = page.getByLabel(new RegExp(`Compté ${name}`));
  await expect(page.locator('td', { hasText: '·' }).first()).toBeVisible();
  await qty.fill('8');
  await qty.press('Enter');
  await page.getByRole('button', { name: 'Envoyer pour validation' }).click();
  await expect(page.getByText(/−2|-2/).first()).toBeVisible();
  await page.getByRole('button', { name: 'Valider et ajuster le stock' }).click();
  await expect(page.getByText('Validé').first()).toBeVisible();
});

test('US12 : retour partiel d’une vente avec avoir imprimable', async ({ page, request }) => {
  const name = `Gel ${stamp}`;
  const pid = await productWithStock(request, name, 'free', 2000, 10);
  const owner = await apiAs(request, 'owner@demo.test');
  await owner('POST', '/app/cash/open', { station: 'OWN', opening_float: 0 });
  const sale = await owner<{ number: string }>('POST', '/app/sales', {
    lines: [{ product_id: pid, qty: 3 }],
    payments: [{ method: 'cash', amount: 6000 }],
  });

  await loginAs(page, 'owner@demo.test');
  await page.getByRole('link', { name: 'Ventes et retours' }).click();
  await page.getByRole('button', { name: sale.number }).click();
  await page.getByRole('button', { name: 'Retour ou annulation' }).click();
  await page.getByLabel(new RegExp(`${name} · Quantité rendue`)).fill('1');
  await page.getByLabel('Motif').fill('Emballage ouvert');
  await page.getByRole('dialog').getByRole('button', { name: 'Retour ou annulation' }).click();
  await expect(page.getByText('Retour enregistré.')).toBeVisible();
  await page.getByRole('button', { name: 'Voir l’avoir' }).click();
  await expect(
    page.frameLocator('iframe[title^="AV-"]').getByText('Total de l’avoir'),
  ).toBeVisible();
});
