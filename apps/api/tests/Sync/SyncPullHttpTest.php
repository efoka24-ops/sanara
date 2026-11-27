<?php
declare(strict_types=1);

namespace Sanara\Tests\Sync;

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

/** T137, T139 : le poste télécharge, par pages, ce qu'il lui faut pour vendre hors-ligne. */
final class SyncPullHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Clock $clock;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        $this->clock = $clock = Clock::fixed('2026-11-26T18:00:00Z');
        $users = new UserRepository($db, $clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        foreach (['owner' => 'T1', 'cashier' => 'T1', 'accountant' => 'T1', 'other' => 'T2'] as $name => $tenant) {
            $users->create(['email' => "$name@t.cm", 'role_code' => $name === 'other' ? 'owner' : $name, 'tenant_id' => $tenant, 'password_hash' => $hash]);
        }
        foreach (['T1', 'T2'] as $t) {
            $db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "Pharmacie $t", 'slug' => strtolower($t), 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => "AG-$t", 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        }
        $services = new Services($db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $services);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $user = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.2.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($user !== null) {
            $this->tokens[$user] ??= $this->call('POST', '/auth/login', ['email' => "$user@t.cm", 'password' => self::PW])[1]['access'];
            $r = $r->withHeader('Authorization', "Bearer {$this->tokens[$user]}");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    /** Une heure passe : les jetons de session sont à reprendre. */
    private function later(): void
    {
        $this->clock->advance('+1 hour');
        $this->tokens = [];
    }

    private function product(string $name, int $stock = 0, string $user = 'owner', ?string $barcode = null): string
    {
        $body = ['name' => $name, 'regulatory_status' => 'free', 'sale_price' => 1500, 'purchase_price' => 900, 'vat_bp' => 1925];
        if ($barcode !== null) {
            $body['barcodes'] = [$barcode];
        }
        $id = $this->call('POST', '/app/products', $body, $user)[1]['id'];
        if ($stock > 0) {
            $this->call('POST', '/app/stock/receipts', ['product_id' => $id, 'lot_number' => 'L-' . substr($id, -6), 'expiry' => '2030-01-01', 'quantity' => $stock, 'unit_cost' => 900], $user);
        }
        return $id;
    }

    public function testAnonymousAndRolesWithoutSalesRightAreRefused(): void
    {
        $this->assertSame(401, $this->call('GET', '/sync/pull')[0]);
        $this->assertSame(403, $this->call('GET', '/sync/pull', null, 'accountant')[0]);
    }

    public function testFirstPageCarriesProductsLotsBalancesAndSettingsWithoutCosts(): void
    {
        $id = $this->product('Paracétamol', 12, barcode: '3400930000001');
        $this->product('Produit étranger', 3, 'other');

        [$status, $page] = $this->call('GET', '/sync/pull', null, 'cashier');
        $this->assertSame(200, $status);
        $this->assertNull($page['nextCursor']);
        $this->assertSame('2026-11-26T18:00:00.000Z', $page['serverTime']);
        $this->assertSame(1000, $page['settings']['maxDiscountBp']);
        $this->assertSame([], $page['settings']['saleCounters']);
        $this->assertSame(['name' => 'Pharmacie T1', 'address' => '', 'phone' => '', 'licenseNumber' => 'AG-T1', 'currency' => 'XAF', 'ticketFooter' => '', 'ticketWidth' => '80'], $page['settings']['pharmacy']);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], 'cashier');
        $this->call('POST', '/app/sales', ['lines' => [['product_id' => $id, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1500]]], 'cashier');
        $this->assertSame(['C1:2026' => 1], $this->call('GET', '/sync/pull', null, 'cashier')[1]['settings']['saleCounters']);
        $this->assertCount(1, $page['products'], 'jamais les produits d’une autre pharmacie');
        $this->assertSame(
            ['id' => $id, 'name' => 'Paracétamol', 'dci' => null, 'form' => null, 'strength' => null, 'regulatoryStatus' => 'free', 'salePrice' => 1500, 'vatBp' => 1925, 'active' => true, 'barcodes' => ['3400930000001'], 'unassignedQty' => 0],
            $page['products'][0],
        );
        $this->assertCount(1, $page['lots']);
        $this->assertSame([$id, 12, '2030-01-01', 'available'], [$page['lots'][0]['productId'], $page['lots'][0]['quantity'], $page['lots'][0]['expiry'], $page['lots'][0]['status']]);
        $this->assertSame(['id', 'productId', 'lotNumber', 'expiry', 'status', 'receivedAt', 'quantity'], array_keys($page['lots'][0]), 'aucun prix d’achat');
    }

    public function testLaterPullsCarryOnlyWhatChangedSinceByTimeOfReception(): void
    {
        $sold = $this->product('Vendu', 10);
        $quiet = $this->product('Calme', 4);
        $account = $this->call('POST', '/app/customers', ['name' => 'Awa Test', 'phone' => '690', 'credit_limit' => 5000], 'owner')[1]['id'];
        $this->later();
        [, $first] = $this->call('GET', '/sync/pull', null, 'cashier');
        $this->assertTrue($first['full']);

        $this->later();
        [, $second] = $this->call('GET', "/sync/pull?since={$first['serverTime']}", null, 'cashier');
        $this->assertFalse($second['full']);
        $this->assertSame([[], [], null], [$second['products'], $second['lots'], $second['nextCursor']]);
        $this->assertNotNull($second['settings']);

        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], 'cashier');
        $this->call('POST', '/app/sales', ['lines' => [['product_id' => $sold, 'qty' => 1]], 'payments' => [['method' => 'credit', 'amount' => 1500]], 'customer_id' => $account], 'cashier');
        [, $third] = $this->call('GET', "/sync/pull?since={$second['serverTime']}", null, 'cashier');
        $this->assertSame([$sold], array_column($third['products'], 'id'));
        $this->assertSame([9], array_column($third['lots'], 'quantity'));
        $this->assertSame('c:', $third['nextCursor']);
        [, $customers] = $this->call('GET', "/sync/pull?since={$second['serverTime']}&cursor=c:", null, 'cashier');
        $this->assertSame([['id' => $account, 'name' => 'Awa Test', 'phone' => '690', 'creditLimit' => 5000, 'balance' => 1500, 'active' => true]], $customers['customers']);
        $this->assertNull($customers['nextCursor']);

        // Un changement de statut de lot compte aussi ; une date illisible redonne tout.
        $this->later();
        [, $marker] = $this->call('GET', '/sync/pull?since=hier', null, 'cashier');
        $this->later();
        $lot = $this->call('GET', "/app/stock/products/$quiet/lots", null, 'owner')[1]['items'][0]['id'];
        $this->call('POST', "/app/stock/lots/$lot/quarantine", ['reason' => 'Rappel du fabricant'], 'owner');
        [, $fourth] = $this->call('GET', "/sync/pull?since={$marker['serverTime']}", null, 'cashier');
        // « Vendu » a changé il y a une heure : plus ancien que la marge de recouvrement.
        $this->assertSame([$quiet], array_column($fourth['products'], 'id'));
        $this->assertSame(['quarantine'], array_column($fourth['lots'], 'status'));
        $all = $this->call('GET', '/sync/pull?since=hier', null, 'cashier')[1];
        $this->assertTrue($all['full']);
        $this->assertCount(2, $all['products']);
    }

    public function testPagesFollowTheCursorWithoutGapOrRepeat(): void
    {
        $expected = [];
        foreach (range(1, 5) as $i) {
            $expected[] = $this->product("Produit $i", 1);
        }
        sort($expected);

        $seen = [];
        $lots = 0;
        $cursor = '';
        $pages = 0;
        do {
            [, $page] = $this->call('GET', "/sync/pull?limit=2&cursor=$cursor", null, 'cashier');
            $seen = [...$seen, ...array_column($page['products'], 'id')];
            $lots += count($page['lots']);
            $this->assertSame($pages === 0, $page['settings'] !== null, 'réglages avec la première page seulement');
            $cursor = $page['nextCursor'];
            $pages++;
        } while ($cursor !== null && $pages < 10);

        $this->assertSame($expected, $seen);
        $this->assertSame(5, $lots);
        $this->assertSame(3, $pages);
    }
}
