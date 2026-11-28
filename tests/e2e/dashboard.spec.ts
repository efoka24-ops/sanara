import { expect, test } from '@playwright/test';
import { apiAs, loginAs } from './helpers';

const stamp = Date.now().toString(36);

test('tableaux de bord : le titulaire voit ses ventes, le caissier sa caisse, le magasinier son stock', async ({
  page,
  request,
}) => {
  const owner = await apiAs(request, 'owner@demo.test');
  const p = await owner<{ id: string }>('POST', '/app/products', {
    name: `Tableau ${stamp}`,
    regulatory_status: 'free',
    sale_price: 1200,
    purchase_price: 800,
    vat_bp: 0,
    min_stock: 50,
  });
  await owner('POST', '/app/stock/receipts', {
    product_id: p.id,
    lot_number: `TB-${stamp}`,
    expiry: '2031-01-01',
    quantity: 10,
    unit_cost: 800,
  });
  const cashier = await apiAs(request, 'cashier@demo.test');
  const { session } = await cashier<{ session: { id: string } | null }>('GET', '/app/cash/current');
  if (!session) await cashier('POST', '/app/cash/open', { station: 'TB1', opening_float: 0 });
  await cashier('POST', '/app/sales', {
    lines: [{ product_id: p.id, qty: 2 }],
    payments: [{ method: 'cash', amount: 2400 }],
  });

  await loginAs(page, 'owner@demo.test');
  await expect(page.getByText('Ventes du jour')).toBeVisible();
  await expect(page.getByRole('img', { name: /Ventes des 12 derniers jours/ })).toBeVisible();
  await expect(page.getByText('Alertes stock')).toBeVisible();
  await expect(page.getByText(/Stock bas : 8/).first()).toBeVisible();
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/tdb-titulaire.png`, fullPage: true });

  await loginAs(page, 'cashier@demo.test');
  await expect(page.getByText('Ma caisse').first()).toBeVisible();
  await expect(page.getByText('Ventes du jour')).toHaveCount(0);
  await expect(page.getByText('Commandes prêtes à remettre')).toBeVisible();
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/tdb-caissier.png`, fullPage: true });

  await loginAs(page, 'storekeeper@demo.test');
  await expect(page.getByText('Stock à surveiller')).toBeVisible();
  await expect(page.getByText('Ma caisse')).toHaveCount(0);
  if (process.env.SHOTS)
    await page.screenshot({ path: `${process.env.SHOTS}/tdb-magasinier.png`, fullPage: true });

  // La caisse ouverte ici ne gêne pas les autres scénarios.
  const open = await cashier<{ session: { id: string } | null }>('GET', '/app/cash/current');
  if (open.session)
    await cashier('POST', `/app/cash/sessions/${open.session.id}/close`, {
      counts: [],
      reason: 'test',
    });
});
