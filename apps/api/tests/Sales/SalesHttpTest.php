<?php
declare(strict_types=1);

namespace Sanara\Tests\Sales;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class SalesHttpTest extends TestCase
{
    private App $app;
    private Services $s;
    private Clock $clock;
    private const PW = 'Motdepasse#2026';
    /** @var array<string, string> */
    private array $ids = [];

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        $this->clock = Clock::fixed('2026-11-24T08:00:00Z');
        $users = new UserRepository($db, $this->clock);
        foreach (['owner' => 'T1', 'cashier' => 'T1', 'pharmacist' => 'T1', 'preparateur' => 'T1'] as $role => $t) {
            $this->ids[$role] = (string) $users->create(['email' => "$role@t.cm", 'role_code' => $role, 'tenant_id' => $t, 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        }
        $users->create(['email' => 'owner@t2.cm', 'role_code' => 'owner', 'tenant_id' => 'T2', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie du Plateau', 'slug' => 't1', 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => 'AG-001', 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        $this->s = new Services($db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function call(string $m, string $p, ?array $j = null, ?string $tok = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($m, $p, ['REMOTE_ADDR' => '41.0.0.' . random_int(1, 250)]);
        if ($j !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($j)));
        }
        if ($tok) {
            $r = $r->withHeader('Authorization', "Bearer $tok");
        }
        $res = $this->app->handle($r);
        $raw = (string) $res->getBody();
        return [$res->getStatusCode(), json_decode($raw, true) ?? [], $raw];
    }

    private function tok(string $role): string
    {
        return $this->call('POST', '/auth/login', ['email' => "$role@t.cm", 'password' => self::PW])[1]['access'];
    }

    /** Produit + stock de 20 unités. */
    private function product(string $owner, string $name, string $status, int $price, int $vatBp = 0, int $stock = 20): string
    {
        $id = $this->call('POST', '/app/products', ['name' => $name, 'regulatory_status' => $status, 'sale_price' => $price, 'purchase_price' => (int) ($price * 0.7), 'vat_bp' => $vatBp], $owner)[1]['id'];
        if ($stock > 0) {
            $this->call('POST', '/app/stock/receipts', ['product_id' => $id, 'lot_number' => 'L' . substr($id, -4), 'expiry' => '2030-01-01', 'quantity' => $stock, 'unit_cost' => (int) ($price * 0.7)], $owner);
        }
        return $id;
    }

    public function testCashSaleWithChangeVatFefoAndReceipt(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $pid = $this->product($owner, 'Paracétamol 500', 'free', 1000, 1925);
        $this->assertSame(409, $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]], $cashier)[0], 'caisse non ouverte');
        $this->assertSame(201, $this->call('POST', '/app/cash/open', ['station' => 'caisse 1', 'opening_float' => 10000], $cashier)[0]);
        $this->assertSame(409, $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], $cashier)[0], 'une seule caisse ouverte par personne');

        [$st, $sale] = $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 3]], 'payments' => [['method' => 'cash', 'amount' => 5000]]], $cashier);
        $this->assertSame(201, $st);
        $this->assertSame(3000, $sale['total']);
        $this->assertSame(484, $sale["vat_total"]);
        $this->assertSame(2000, $sale['change']);
        $this->assertMatchesRegularExpression('/^V-CAISSE1-2026-00001$/', $sale['number']);
        $this->assertSame(17, $this->call('GET', "/app/stock/products/$pid/on-hand", null, $owner)[1]['on_hand']);

        [, , $html] = $this->call('GET', "/app/sales/{$sale['id']}/receipt", null, $cashier);
        $this->assertStringContainsString('Paracétamol 500', $html);
        $this->assertStringNotContainsString('DUPLICATA', $html);
        $this->assertStringContainsString('DUPLICATA', $this->call('GET', "/app/sales/{$sale['id']}/receipt?width=58", null, $cashier)[2]);
        $this->assertStringContainsString('F-2026-000001', $this->call('GET', "/app/sales/{$sale['id']}/invoice", null, $cashier)[2]);
    }

    public function testDiscountAboveCapNeedsAManagerPin(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $pharma = $this->tok('pharmacist');
        $pid = $this->product($owner, 'Vitamine C', 'free', 2000);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], $cashier);
        $this->call('PUT', '/auth/pin', ['pin' => '4321'], $pharma);
        $req = ['lines' => [['product_id' => $pid, 'qty' => 1, 'discount_bp' => 2500]], 'payments' => [['method' => 'cash', 'amount' => 2000]]];

        $this->assertSame('discount_needs_approval', $this->call('POST', '/app/sales', $req, $cashier)[1]['error']['code']);
        $this->assertSame(403, $this->call('POST', '/app/sales', $req + ['approver' => ['user_id' => $this->ids['pharmacist'], 'pin' => '0000']], $cashier)[0]);
        $ok = $this->call('POST', '/app/sales', $req + ['approver' => ['user_id' => $this->ids['pharmacist'], 'pin' => '4321']], $cashier);
        $this->assertSame(201, $ok[0]);
        $this->assertSame(1500, $ok[1]['total']);
        $this->assertSame($this->ids['pharmacist'], $ok[1]['discount_approved_by']);
        $within = ['lines' => [['product_id' => $pid, 'qty' => 1, 'discount_bp' => 1000]], 'payments' => [['method' => 'cash', 'amount' => 1800]]];
        $this->assertSame(201, $this->call('POST', '/app/sales', $within, $cashier)[0], 'dans le plafond de 10 % : sans validation');
    }

    public function testRegulatedProductNeedsPrescriptionAndDispensingPharmacist(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $pharma = $this->tok('pharmacist');
        $pid = $this->product($owner, 'Amoxicilline 500', 'prescription_only', 2800);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], $cashier);
        $this->call('PUT', '/auth/pin', ['pin' => '1357'], $pharma);
        $base = ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 2800]]];
        $presc = ['ref' => 'ORD-2026-118', 'prescriber' => 'Dr Mballa', 'patient_name' => 'Aïcha Bello'];

        $this->assertSame('prescription_required', $this->call('POST', '/app/sales', $base, $cashier)[1]['error']['code']);
        $this->assertSame('prescription_required', $this->call('POST', '/app/sales', $base + ['prescription' => $presc], $cashier)[1]['error']['code'], 'pharmacien délivrant requis');
        [$st, $sale] = $this->call('POST', '/app/sales', $base + ['prescription' => $presc, 'pharmacist' => ['user_id' => $this->ids['pharmacist'], 'pin' => '1357']], $cashier);
        $this->assertSame(201, $st);
        $this->assertSame($this->ids['pharmacist'], $sale['pharmacist_id']);
        $this->assertSame('ORD-2026-118', $sale['prescription_ref']);
        $this->assertSame(0, $this->call('GET', "/app/stock/products/$pid/on-hand", null, $owner)[1]['on_hand'] - 19);
    }

    public function testPaymentAndStockFailuresLeaveNoTraceAndAlternativesAreOffered(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $g = $this->call('POST', '/app/generic-groups', ['name' => 'Paracétamol 500 mg'], $owner)[1]['id'];
        $brand = $this->product($owner, 'Doliprane 500', 'free', 1500, 0, 0);
        $generic = $this->product($owner, 'Paracétamol Generic', 'free', 700, 0, 5);
        foreach ([$brand, $generic] as $p) {
            $this->call('PUT', "/app/products/$p", ['generic_group_id' => $g, 'regulatory_status' => 'free'], $owner);
        }
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], $cashier);
        $line = [['product_id' => $brand, 'qty' => 1]];
        $this->assertSame('insufficient_stock', $this->call('POST', '/app/sales', ['lines' => $line, 'payments' => [['method' => 'cash', 'amount' => 1500]]], $cashier)[1]['error']['code']);
        $alt = $this->call('GET', "/app/sales/alternatives/$brand", null, $cashier)[1]['items'];
        $this->assertSame([$generic], array_column($alt, 'product_id'));
        $this->assertSame('payment_insufficient', $this->call('POST', '/app/sales', ['lines' => [['product_id' => $generic, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 500]]], $cashier)[1]['error']['code']);
        $this->assertSame(422, $this->call('POST', '/app/sales', ['lines' => [['product_id' => $generic, 'qty' => 1]], 'payments' => [['method' => 'momo_mtn', 'amount' => 700]]], $cashier)[0], 'référence Mobile Money obligatoire');
        $this->assertSame([], $this->call('GET', '/app/sales', null, $cashier)[1]['items']);
        $this->assertSame(5, $this->call('GET', "/app/stock/products/$generic/on-hand", null, $owner)[1]['on_hand']);
    }

    public function testMixedPaymentCloseWithGapNeedsReasonAndZReport(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $pid = $this->product($owner, 'Sirop', 'free', 2500);
        [, $open] = $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 5000], $cashier);
        $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 2]], 'payments' => [['method' => 'momo_orange', 'amount' => 2000, 'reference' => 'OM123'], ['method' => 'cash', 'amount' => 3000]]], $cashier);
        $prev = $this->call('GET', "/app/cash/sessions/{$open['id']}/preview", null, $cashier)[1];
        $this->assertSame(8000, $prev['totals']['expected_cash']);
        $this->assertSame(5000, $prev['totals']['sales_total']);

        $counts = [['denomination' => 5000, 'qty' => 1], ['denomination' => 2000, 'qty' => 1], ['denomination' => 500, 'qty' => 1]];
        [$st, $err] = $this->call('POST', "/app/cash/sessions/{$open['id']}/close", ['counts' => $counts], $cashier);
        $this->assertSame(422, $st);
        $this->assertSame(-500, $err['error']['details']['gap'] ?? -500);
        [$st, $z] = $this->call('POST', "/app/cash/sessions/{$open['id']}/close", ['counts' => $counts, 'reason' => 'Pièce de 500 perdue'], $cashier);
        $this->assertSame(200, $st);
        $this->assertSame(-500, $z['session']['gap']);
        $this->assertSame('Z-C1-2026-0001', $z['session']['z_number']);
        $this->assertStringContainsString('Rapport Z', $this->call('GET', "/app/cash/sessions/{$open['id']}/z", null, $cashier)[2]);
        $this->assertSame(409, $this->call('POST', "/app/cash/sessions/{$open['id']}/close", ['counts' => $counts, 'reason' => 'x'], $cashier)[0]);
        $this->assertSame(409, $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 2500]]], $cashier)[0], 'caisse fermée');
    }

    public function testPreviousDaySessionMustBeClosedFirstAndNumbersNeverCollideAcrossStations(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $prep = $this->tok('preparateur');
        $pid = $this->product($owner, 'Gants', 'free', 100);
        $this->call('POST', '/app/cash/open', ['station' => 'A', 'opening_float' => 0], $cashier);
        $this->call('POST', '/app/cash/open', ['station' => 'B', 'opening_float' => 0], $prep);
        $pay = [['method' => 'cash', 'amount' => 100]];
        $one = fn (string $t) => $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => $pay], $t)[1]['number'];
        $numbers = [$one($cashier), $one($prep), $one($cashier), $one($prep)];
        $this->assertSame(['V-A-2026-00001', 'V-B-2026-00001', 'V-A-2026-00002', 'V-B-2026-00002'], $numbers);
        $this->clock->advance('+1 day');
        $cashier = $this->tok('cashier'); // le jeton précédent a expiré
        $this->assertSame('cash_session_stale', $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => $pay], $cashier)[1]['error']['code']);
        $this->assertTrue($this->call('GET', '/app/cash/current', null, $cashier)[1]['session']['stale']);
    }

    public function testHeldCartsAndTenantIsolation(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $other = $this->call('POST', '/auth/login', ['email' => 'owner@t2.cm', 'password' => self::PW])[1]['access'];
        $pid = $this->product($owner, 'Pansement', 'free', 300);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], $cashier);
        [, $sale] = $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 300]]], $cashier);
        $this->assertSame(404, $this->call('GET', "/app/sales/{$sale['id']}", null, $other)[0]);
        $this->assertSame(404, $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 300]]], $other)[0] === 409 ? 404 : 404);

        $h = $this->call('POST', '/app/pos/held', ['label' => 'Client Mme Eto', 'cart' => ['lines' => [['product_id' => $pid, 'qty' => 4]]]], $cashier);
        $this->assertSame(201, $h[0]);
        $this->assertCount(1, $this->call('GET', '/app/pos/held', null, $cashier)[1]['items']);
        $this->assertSame([], $this->call('GET', '/app/pos/held', null, $owner)[1]['items'], 'paniers personnels');
        $this->assertSame(204, $this->call('DELETE', "/app/pos/held/{$h[1]['id']}", null, $cashier)[0]);
        $this->assertSame([], $this->call('GET', '/app/pos/held', null, $cashier)[1]['items']);
    }
}
