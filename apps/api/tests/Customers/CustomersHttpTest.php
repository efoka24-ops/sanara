<?php
declare(strict_types=1);

namespace Sanara\Tests\Customers;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Ulid;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** US13 (T220 à T222) : fiches clients, crédit plafonné, règlements partiels et relevé. */
final class CustomersHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    private Clock $clock;
    /** @var array<string, string> */
    private array $ids = [];
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        $this->clock = Clock::fixed('2026-11-27T10:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        foreach (['owner' => 'T1', 'cashier' => 'T1', 'accountant' => 'T1', 'storekeeper' => 'T1', 'other' => 'T2'] as $name => $tenant) {
            $this->ids[$name] = (string) $users->create(['email' => "$name@t.cm", 'role_code' => $name === 'other' ? 'owner' : $name, 'tenant_id' => $tenant, 'password_hash' => $hash]);
        }
        foreach (['T1', 'T2'] as $t) {
            $this->db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "Pharmacie $t", 'slug' => strtolower($t), 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => "AG-$t", 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        }
        $services = new Services($this->db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $services);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function call(string $method, string $path, ?array $json = null, ?string $user = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.3.' . random_int(1, 250)]);
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
        $body = (string) $res->getBody();
        return [$res->getStatusCode(), json_decode($body, true) ?? [], $body];
    }

    private function customer(int $limit = 5000, string $name = 'Awa Test', string $user = 'owner'): string
    {
        [$status, $body] = $this->call('POST', '/app/customers', ['name' => $name, 'phone' => '+237 690000000', 'credit_limit' => $limit, 'reminder_consent' => true], $user);
        $this->assertSame(201, $status);
        return $body['id'];
    }

    private function product(int $price = 1000, int $stock = 20): string
    {
        $id = $this->call('POST', '/app/products', ['name' => 'Paracétamol ' . Ulid::generate(), 'regulatory_status' => 'free', 'sale_price' => $price, 'purchase_price' => 600, 'vat_bp' => 0], 'owner')[1]['id'];
        $this->call('POST', '/app/stock/receipts', ['product_id' => $id, 'lot_number' => 'L1', 'expiry' => '2030-01-01', 'quantity' => $stock, 'unit_cost' => 600], 'owner');
        return $id;
    }

    private function openTill(string $user = 'cashier'): string
    {
        return $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], $user)[1]['id'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function creditSale(string $product, int $qty, ?string $customer): array
    {
        $body = ['lines' => [['product_id' => $product, 'qty' => $qty]], 'payments' => [['method' => 'credit', 'amount' => 1000 * $qty]]];
        if ($customer !== null) {
            $body['customer_id'] = $customer;
        } else {
            $body['customer_name'] = 'Sans fiche';
        }
        [$status, $sale] = $this->call('POST', '/app/sales', $body, 'cashier');
        return [$status, $sale];
    }

    private function balance(string $customer): int
    {
        return $this->call('GET', "/app/customers/$customer", null, 'owner')[1]['balance'];
    }

    public function testAccountsAreManagedByAuthorisedRolesAndSearchableAtTheTill(): void
    {
        $this->assertSame(403, $this->call('POST', '/app/customers', ['name' => 'X', 'credit_limit' => 0], 'cashier')[0]);
        $this->assertSame(422, $this->call('POST', '/app/customers', ['name' => ' ', 'credit_limit' => -1], 'owner')[0]);
        $id = $this->customer();
        $this->customer(0, 'Bello Moussa', 'accountant');
        $this->customer(0, 'Étranger', 'other');

        [, $list] = $this->call('GET', '/app/customers?q=awa', null, 'cashier');
        $this->assertSame([['id' => $id, 'name' => 'Awa Test', 'phone' => '+237 690000000', 'credit_limit' => 5000, 'balance' => 0, 'active' => true]], $list['items']);
        $this->assertCount(2, $this->call('GET', '/app/customers', null, 'cashier')[1]['items']);
        $this->assertSame(403, $this->call('GET', '/app/customers', null, 'storekeeper')[0]);
        $this->assertSame(404, $this->call('GET', "/app/customers/$id", null, 'other')[0]);

        $this->assertSame(204, $this->call('POST', "/app/customers/$id", ['name' => 'Awa Test', 'phone' => '', 'credit_limit' => 8000, 'active' => false], 'owner')[0]);
        $updated = $this->call('GET', "/app/customers/$id", null, 'owner')[1];
        $this->assertSame([8000, false, false], [$updated['credit_limit'], $updated['active'], $updated['reminder_consent']]);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) AS n FROM audit_logs WHERE action = 'customer.update'")['n']);
    }

    public function testCreditNeedsAnAccountAndStopsAtItsCeiling(): void
    {
        $customer = $this->customer(3000);
        $product = $this->product();
        $this->openTill();

        $this->assertSame(422, $this->creditSale($product, 1, null)[0]);
        [$status, $sale] = $this->creditSale($product, 2, $customer);
        $this->assertSame(201, $status);
        $this->assertSame('Awa Test', $sale['customer_name']);
        $this->assertSame(2000, $this->balance($customer));

        [$status, $error] = $this->creditSale($product, 2, $customer);
        $this->assertSame(422, $status);
        $this->assertSame(['credit_limit_exceeded', 1000], [$error['error']['code'], $error['error']['details']['available']]);
        $this->assertSame(2000, $this->balance($customer));
        $this->assertSame(18, $this->call('GET', "/app/stock/products/$product/on-hand", null, 'owner')[1]['on_hand'], 'aucune sortie pour la vente refusée');
        $this->assertSame(201, $this->creditSale($product, 1, $customer)[0]);

        $this->call('POST', "/app/customers/$customer", ['name' => 'Awa Test', 'credit_limit' => 90000, 'active' => false], 'owner');
        $this->assertSame(422, $this->creditSale($product, 1, $customer)[0], 'compte désactivé');
    }

    public function testPartialPaymentsReduceTheDebtWithAReceiptAndReachTheTill(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $session = $this->openTill();
        $this->creditSale($product, 3, $customer);
        $pay = "/app/customers/$customer/payments";

        $this->assertSame(422, $this->call('POST', $pay, ['amount' => 3001, 'method' => 'cash'], 'cashier')[0], 'jamais plus que la dette');
        $this->assertSame(422, $this->call('POST', $pay, ['amount' => 0, 'method' => 'cash'], 'cashier')[0]);
        $this->assertSame(422, $this->call('POST', $pay, ['amount' => 100, 'method' => 'credit'], 'cashier')[0]);
        $this->assertSame(409, $this->call('POST', $pay, ['amount' => 100, 'method' => 'cash'], 'accountant')[0], 'espèces sans caisse ouverte');

        [$status, $receipt] = $this->call('POST', $pay, ['amount' => 1200, 'method' => 'cash'], 'cashier');
        $this->assertSame(201, $status);
        $this->assertSame(['REC-2026-000001', 1800], [$receipt['receipt_number'], $receipt['balance']]);
        $this->assertSame(1200, $this->call('GET', "/app/cash/sessions/$session/preview", null, 'cashier')[1]['totals']['expected_cash']);
        // Un virement ne passe pas par la caisse.
        $this->assertSame(201, $this->call('POST', $pay, ['amount' => 800, 'method' => 'bank', 'reference' => 'VIR-1'], 'accountant')[0]);
        $this->assertSame(1200, $this->call('GET', "/app/cash/sessions/$session/preview", null, 'cashier')[1]['totals']['expected_cash']);

        $statement = $this->call('GET', "/app/customers/$customer", null, 'accountant')[1];
        $this->assertSame([3000, -1200, -800], array_column($statement['entries'], 'amount'));
        $this->assertSame(1000, $statement['balance']);
        $page = $this->call('GET', "/app/customers/$customer/receipts/{$receipt['id']}", null, 'cashier')[2];
        $this->assertStringContainsString('REC-2026-000001', $page);
        $this->assertStringContainsString('Awa Test', $page);
    }

    public function testAReturnCanBeCreditedToTheAccount(): void
    {
        $customer = $this->customer();
        $product = $this->product();
        $this->openTill();
        [, $sale] = $this->creditSale($product, 2, $customer);
        $line = $this->call('GET', "/app/sales/{$sale['id']}", null, 'owner')[1]['lines'][0]['id'];

        [$status] = $this->call('POST', "/app/sales/{$sale['id']}/returns", ['lines' => [['sale_line_id' => $line, 'qty' => 1]], 'reason' => 'Erreur de saisie', 'refund_method' => 'account', 'approver' => null], 'owner');
        $this->assertSame(201, $status);
        $this->assertSame(1000, $this->balance($customer));

        [, $cash] = $this->call('POST', '/app/sales', ['lines' => [['product_id' => $product, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]], 'cashier');
        $cashLine = $this->call('GET', "/app/sales/{$cash['id']}", null, 'owner')[1]['lines'][0]['id'];
        $this->assertSame(422, $this->call('POST', "/app/sales/{$cash['id']}/returns", ['lines' => [['sale_line_id' => $cashLine, 'qty' => 1]], 'reason' => 'x', 'refund_method' => 'account'], 'owner')[0], 'pas de compte sur cette vente');
    }

    public function testAnOfflineCreditSaleIsRecordedAndAnOverrunFlagged(): void
    {
        $customer = $this->customer(1500);
        $product = $this->product();
        $session = Ulid::generate();
        $sale = fn (int $n, ?string $who) => ['id' => Ulid::generate(), 'deviceId' => 'poste-1', 'userId' => $this->ids['cashier'], 'type' => 'sale.create', 'schemaVersion' => 1, 'createdAt' => "2026-11-27T09:0$n:00.000Z", 'payload' => [
            'saleId' => Ulid::generate(), 'sessionId' => $session, 'number' => sprintf('V-C1-2026-%05d', $n),
            'lines' => [['productId' => $product, 'qty' => 1, 'unitPrice' => 1000, 'discountBp' => 0, 'vatBp' => 0]],
            'payments' => [['method' => 'credit', 'amount' => 1000]],
        ] + ($who === null ? ['customerName' => 'Inconnu'] : ['customerId' => $who])];
        $open = ['id' => Ulid::generate(), 'deviceId' => 'poste-1', 'userId' => $this->ids['cashier'], 'type' => 'cash.open', 'schemaVersion' => 1, 'createdAt' => '2026-11-27T09:00:00.000Z', 'payload' => ['sessionId' => $session, 'station' => 'C1', 'openingFloat' => 0]];

        $results = $this->call('POST', '/sync/push', ['deviceId' => 'poste-1', 'operations' => [$open, $sale(1, $customer), $sale(2, $customer), $sale(3, null)]], 'cashier')[1]['results'];
        $this->assertSame(['applied', 'applied', 'applied', 'rejected'], array_column($results, 'status'));
        $this->assertSame([], $results[1]['conflicts']);
        $this->assertSame(['credit_limit_exceeded'], array_column($results[2]['conflicts'], 'kind'));
        $this->assertSame(500, $results[2]['conflicts'][0]['details']['over']);
        $this->assertSame(2000, $this->balance($customer), 'la marchandise est partie : la dette est enregistrée');
    }
}
