<?php
declare(strict_types=1);

namespace Sanara\Tests\Tenants;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * Tableau de bord (maquette H1) : chaque rôle voit les blocs de son métier, avec des chiffres de sa
 * pharmacie seulement. Le titulaire voit tout ; le caissier sa caisse et ses ventes ; le magasinier
 * le stock ; le comptable l'argent.
 */
final class DashboardHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    private Clock $clock;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        // Vendredi 27 novembre 2026, 10 h à Douala.
        $this->clock = Clock::fixed('2026-11-27T09:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        foreach (['owner', 'pharmacist', 'cashier', 'storekeeper', 'accountant'] as $role) {
            $users->create(['email' => "$role@t.cm", 'role_code' => $role, 'tenant_id' => 'T1', 'password_hash' => $hash]);
        }
        $users->create(['email' => 'other@t.cm', 'role_code' => 'owner', 'tenant_id' => 'T2', 'password_hash' => $hash]);
        foreach (['T1', 'T2'] as $t) {
            $this->db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "Pharmacie $t", 'slug' => strtolower($t), 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => "AG-$t", 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        }
        $s = new Services($this->db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $s);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $user = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.10.' . random_int(1, 250)]);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($user !== null) {
            $this->tokens[$user] ??= (string) $this->call('POST', '/auth/login', ['email' => "$user@t.cm", 'password' => self::PW])[1]['access'];
            $r = $r->withHeader('Authorization', "Bearer {$this->tokens[$user]}");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function product(string $name, int $price, int $stock, int $min = 0, string $expiry = '2030-01-01'): string
    {
        $id = $this->call('POST', '/app/products', ['name' => $name, 'regulatory_status' => 'free', 'sale_price' => $price, 'purchase_price' => 100, 'vat_bp' => 0, 'min_stock' => $min], 'owner')[1]['id'];
        if ($stock > 0) {
            $this->call('POST', '/app/stock/receipts', ['product_id' => $id, 'lot_number' => 'L-' . $name, 'expiry' => $expiry, 'quantity' => $stock, 'unit_cost' => 100], 'owner');
        }
        return $id;
    }

    private function sell(string $user, string $product, int $qty, int $amount): void
    {
        [$status] = $this->call('POST', '/app/sales', ['lines' => [['product_id' => $product, 'qty' => $qty]], 'payments' => [['method' => 'cash', 'amount' => $amount]]], $user);
        $this->assertSame(201, $status);
    }

    private function dashboard(string $user): array
    {
        [$status, $body] = $this->call('GET', '/app/dashboard', null, $user);
        $this->assertSame(200, $status);
        return $body;
    }

    public function testTheOwnerSeesSalesRequestsAndStockAtAGlance(): void
    {
        $para = $this->product('Paracétamol', 1000, 20);
        $this->product('Ibuprofène', 500, 2, 10);
        $this->product('Sirop', 800, 0, 5);
        $this->product('Vitamine', 700, 4, 0, '2027-01-15');

        // Hier : une vente de 2 000 sur une caisse ouverte et clôturée la veille.
        $this->clock->advance('-1 day');
        $this->tokens = [];
        $session = $this->call('POST', '/app/cash/open', ['station' => 'C2', 'opening_float' => 0], 'owner')[1]['id'];
        $this->sell('owner', $para, 2, 2000);
        $this->call('POST', "/app/cash/sessions/$session/close", ['counts' => [], 'reason' => 'test'], 'owner');
        $this->clock->advance('+1 day');
        $this->tokens = [];
        // Aujourd'hui : 3 000 en deux tickets.
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], 'cashier');
        $this->sell('cashier', $para, 1, 1000);
        $this->sell('cashier', $para, 2, 2000);

        $d = $this->dashboard('owner');
        $this->assertSame(['total' => 3000, 'tickets' => 2, 'average' => 1500, 'yesterday' => 2000], $d['sales']['today']);
        $this->assertCount(12, $d['sales']['days']);
        $this->assertSame(['date' => '2026-11-27', 'total' => 3000], end($d['sales']['days']));
        $this->assertSame(['date' => '2026-11-26', 'total' => 2000], $d['sales']['days'][10]);
        $this->assertSame(['out' => 1, 'low' => 1, 'expiring' => 1], array_intersect_key($d['stock'], ['out' => 1, 'low' => 1, 'expiring' => 1]));
        $this->assertSame(['open' => 0, 'urgent' => 0, 'latest' => []], $d['requests']);
        $this->assertArrayHasKey('till', $d, 'le titulaire peut aussi vendre');
        $this->assertSame('owner@t.cm', $d['user']['email']);
    }

    public function testTheCashierSeesOnlyTheirTillAndTheirSales(): void
    {
        $para = $this->product('Paracétamol', 1000, 20);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 5000], 'cashier');
        $this->sell('cashier', $para, 3, 3000);
        $this->call('POST', '/app/cash/open', ['station' => 'C2', 'opening_float' => 0], 'owner');
        $this->sell('owner', $para, 1, 1000);

        $d = $this->dashboard('cashier');
        $this->assertSame(['open' => true, 'station' => 'C1', 'opening_float' => 5000, 'sales' => 1, 'total' => 3000, 'expected_cash' => 8000], array_intersect_key($d['till'], array_flip(['open', 'station', 'opening_float', 'sales', 'total', 'expected_cash'])));
        $this->assertArrayNotHasKey('sales', $d, 'pas le chiffre d’affaires de la pharmacie');
        $this->assertArrayNotHasKey('stock', $d);
        $this->assertArrayNotHasKey('requests', $d);
        $this->assertArrayHasKey('pickups', $d, 'les commandes à remettre au comptoir');
    }

    public function testEachRoleGetsItsOwnBlocks(): void
    {
        $keys = fn (string $u) => array_values(array_diff(array_keys($this->dashboard($u)), ['user', 'role']));
        $this->assertEqualsCanonicalizing(['stock'], $keys('storekeeper'));
        $this->assertEqualsCanonicalizing(['sales', 'credit'], $keys('accountant'));
        $this->assertEqualsCanonicalizing(['sales', 'stock', 'requests', 'presence', 'till', 'pickups', 'credit'], $keys('pharmacist'));
        $this->assertSame(401, $this->call('GET', '/app/dashboard')[0]);
    }

    public function testFiguresStayInsideThePharmacy(): void
    {
        $para = $this->product('Paracétamol', 1000, 20);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], 'cashier');
        $this->sell('cashier', $para, 1, 1000);
        $this->assertSame(0, $this->dashboard('other')['sales']['today']['total']);
    }
}
