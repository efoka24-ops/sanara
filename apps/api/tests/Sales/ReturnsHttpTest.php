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

final class ReturnsHttpTest extends TestCase
{
    private App $app;
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
        foreach (['owner', 'cashier', 'pharmacist'] as $role) {
            $this->ids[$role] = (string) $users->create(['email' => "$role@t.cm", 'role_code' => $role, 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        }
        $db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie du Plateau', 'slug' => 't1', 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        $s = new Services($db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $s);
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

    /** @return array{0: string, 1: array<string, mixed>} produit et vente de 4 unités à 1 000 FCFA */
    private function sale(string $owner, string $cashier): array
    {
        $pid = $this->call('POST', '/app/products', ['name' => 'Sérum', 'regulatory_status' => 'free', 'sale_price' => 1000, 'purchase_price' => 600], $owner)[1]['id'];
        $this->call('POST', '/app/stock/receipts', ['product_id' => $pid, 'lot_number' => 'L1', 'expiry' => '2030-01-01', 'quantity' => 10, 'unit_cost' => 600], $owner);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 1000], $cashier);
        $sale = $this->call('POST', '/app/sales', ['lines' => [['product_id' => $pid, 'qty' => 4]], 'payments' => [['method' => 'cash', 'amount' => 4000]]], $cashier)[1];
        return [$pid, $sale];
    }

    public function testCashierNeedsAManagerPinPartialReturnRestocksOnlyOnPharmacistDecision(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        $pharma = $this->tok('pharmacist');
        [$pid, $sale] = $this->sale($owner, $cashier);
        $lineId = $sale['lines'][0]['id'];
        $this->call('PUT', '/auth/pin', ['pin' => '2468'], $this->tok('owner'));
        $body = ['lines' => [['sale_line_id' => $lineId, 'qty' => 1]], 'reason' => 'Boîte abîmée', 'refund_method' => 'cash'];

        $this->assertSame(403, $this->call('POST', "/app/sales/{$sale['id']}/returns", $body, $cashier)[0], 'le caissier ne peut pas rembourser seul');
        $this->assertSame(422, $this->call('POST', "/app/sales/{$sale['id']}/returns", ['reason' => ''] + $body, $owner)[0], 'motif obligatoire');
        $withPin = $body + ['approver' => ['user_id' => $this->ids['owner'], 'pin' => '2468']];
        [$st, $r] = $this->call('POST', "/app/sales/{$sale['id']}/returns", $withPin, $cashier);
        $this->assertSame(201, $st);
        $this->assertSame(1000, $r['refund_amount']);
        $this->assertSame(6, $this->call('GET', "/app/stock/products/$pid/on-hand", null, $owner)[1]['on_hand'], 'pas de remise en stock par défaut');
        $this->assertSame('part_returned', $this->call('GET', "/app/sales/{$sale['id']}", null, $owner)[1]['status']);

        // espèces attendues : fond 1 000 + vente 4 000 − remboursement 1 000
        $sess = $this->call('GET', '/app/cash/current', null, $cashier)[1]['session']['id'];
        $this->assertSame(4000, $this->call('GET', "/app/cash/sessions/$sess/preview", null, $cashier)[1]['totals']['expected_cash']);

        // le pharmacien rend 1 boîte en stock ; le caissier ne peut pas décider de la remise en stock
        $restock = ['lines' => [['sale_line_id' => $lineId, 'qty' => 1]], 'reason' => 'Intacte, scellée', 'refund_method' => 'credit_note', 'restock' => true];
        $this->assertSame(403, $this->call('POST', "/app/sales/{$sale['id']}/returns", $restock, $cashier)[0], 'le caissier seul ne décide pas de la remise en stock');
        [$st, $r2] = $this->call('POST', "/app/sales/{$sale['id']}/returns", $restock, $owner);
        $this->assertSame(201, $st);
        $this->assertSame(7, $this->call('GET', "/app/stock/products/$pid/on-hand", null, $owner)[1]['on_hand']);
        $this->assertMatchesRegularExpression('/^AV-2026-000001$/', $r2['credit_note']['number']);
        $this->assertStringContainsString('Avoir AV-2026-000001', $this->call('GET', "/app/returns/{$r2['id']}/credit-note", null, $owner)[2]);

        // on ne rend pas plus que ce qui a été vendu
        $tooMuch = ['lines' => [['sale_line_id' => $lineId, 'qty' => 3]], 'reason' => 'Trop', 'refund_method' => 'other'];
        $this->assertSame(422, $this->call('POST', "/app/sales/{$sale['id']}/returns", $tooMuch, $owner)[0]);
    }

    public function testRegulatedProductsAreNeverRestockedAndFullSameDayReturnCancelsTheSale(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        [$pid, $sale] = $this->sale($owner, $cashier);
        $full = ['lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'qty' => 4]], 'reason' => 'Erreur de caisse', 'refund_method' => 'other', 'restock' => true];
        [$st, $r] = $this->call('POST', "/app/sales/{$sale['id']}/returns", $full, $owner);
        $this->assertSame(201, $st);
        $this->assertSame('cancel', $r['kind']);
        $this->assertSame('cancelled', $this->call('GET', "/app/sales/{$sale['id']}", null, $owner)[1]['status']);
        $this->assertSame(10, $this->call('GET', "/app/stock/products/$pid/on-hand", null, $owner)[1]['on_hand']);
        $this->assertSame(422, $this->call('POST', "/app/sales/{$sale['id']}/returns", $full, $owner)[0], 'déjà rendu en totalité');
    }

    public function testLateReturnAlwaysNeedsAManager(): void
    {
        $owner = $this->tok('owner');
        $cashier = $this->tok('cashier');
        [, $sale] = $this->sale($owner, $cashier);
        $this->call('PUT', '/auth/pin', ['pin' => '2468'], $owner);
        $this->clock->advance('+10 days');
        $owner = $this->tok('owner');
        $body = ['lines' => [['sale_line_id' => $sale['lines'][0]['id'], 'qty' => 1]], 'reason' => 'Hors délai', 'refund_method' => 'credit_note'];
        $this->assertSame(403, $this->call('POST', "/app/sales/{$sale['id']}/returns", $body, $owner)[0], 'même le titulaire doit se valider hors délai');
        $this->assertSame(201, $this->call('POST', "/app/sales/{$sale['id']}/returns", $body + ['approver' => ['user_id' => $this->ids['owner'], 'pin' => '2468']], $owner)[0]);
    }
}
