<?php
declare(strict_types=1);

namespace Sanara\Tests\Stock;

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

final class StockHttpTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        $clock = Clock::fixed('2026-11-24T09:00:00Z');
        $users = new UserRepository($db, $clock);
        $users->create(['email' => 'owner@t1.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash('Motdepasse#2026')]);
        $users->create(['email' => 'preparateur@t1.cm', 'role_code' => 'preparateur', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash('Motdepasse#2026')]);
        $users->create(['email' => 'owner@t2.cm', 'role_code' => 'owner', 'tenant_id' => 'T2', 'password_hash' => (new PasswordHasher())->hash('Motdepasse#2026')]);

        $s = new Services(
            $db,
            $clock,
            Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']),
            new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'),
        );

        $ownerTok = $this->loginWith($s, 'owner@t1.cm', 'Motdepasse#2026');
        $this->createProduct($s, $ownerTok, 'Paracetamol 500', '6000000001011');

        $otherOwnerTok = $this->loginWith($s, 'owner@t2.cm', 'Motdepasse#2026');
        $this->createProduct($s, $otherOwnerTok, 'Ibuprofen 400', '6000000001012');

        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $s);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $token = null): array
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.0.' . random_int(1, 200)]);
        if ($json !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($token !== null) {
            $req = $req->withHeader('Authorization', "Bearer $token");
        }
        $res = $this->app->handle($req);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function login(string $email): string
    {
        return $this->call('POST', '/auth/login', ['email' => $email, 'password' => 'Motdepasse#2026'])[1]['access'];
    }

    private function loginWith(Services $s, string $email, string $password): string
    {
        return $s->auth->login($email, $password)['access'];
    }

    private function createProduct(Services $s, string $token, string $name, string $barcode): string
    {
        $claims = $s->jwt->verify($token);
        $uid = $claims['sub'];
        $tenantId = $claims['tid'];
        $s->tenant->set($tenantId);
        return $s->catalog->create([
            'name' => $name,
            'dci' => $name,
            'regulatory_status' => 'free',
            'sale_price' => 1500,
            'purchase_price' => 1000,
            'barcodes' => [$barcode],
        ], (string) $uid);
    }

    public function testOwnerCanReceiveStockAndConsultFefoOrderedLots(): void
    {
        $owner = $this->login('owner@t1.cm');
        [, $products] = $this->call('GET', '/app/products?q=Paracetamol', null, $owner);
        $productId = $products['items'][0]['id'];

        $this->assertSame(201, $this->call('POST', '/app/stock/receipts', [
            'product_id' => $productId,
            'lot_number' => 'LOT-B',
            'expiry' => '2027-03-30',
            'quantity' => 12,
            'unit_cost' => 900,
        ], $owner)[0]);

        $this->assertSame(201, $this->call('POST', '/app/stock/receipts', [
            'product_id' => $productId,
            'lot_number' => 'LOT-A',
            'expiry' => '2027-01-15',
            'quantity' => 7,
            'unit_cost' => 920,
        ], $owner)[0]);

        [, $lots] = $this->call('GET', '/app/stock/products/' . $productId . '/lots', null, $owner);
        $this->assertCount(2, $lots['items']);
        $this->assertSame('LOT-A', $lots['items'][0]['lot_number']);
        $this->assertSame(7, $lots['items'][0]['available_qty']);

        [, $onHand] = $this->call('GET', '/app/stock/products/' . $productId . '/on-hand', null, $owner);
        $this->assertSame(19, $onHand['on_hand']);
    }

    public function testReadOnlyRoleCannotReceiveStockButCanReadLots(): void
    {
        $owner = $this->login('owner@t1.cm');
        [, $products] = $this->call('GET', '/app/products?q=Paracetamol', null, $owner);
        $productId = $products['items'][0]['id'];

        $preparateur = $this->login('preparateur@t1.cm');
        $this->assertSame(403, $this->call('POST', '/app/stock/receipts', [
            'product_id' => $productId,
            'lot_number' => 'NOPE',
            'expiry' => '2027-02-01',
            'quantity' => 1,
            'unit_cost' => 100,
        ], $preparateur)[0]);

        $this->assertSame(200, $this->call('GET', '/app/stock/products/' . $productId . '/lots', null, $preparateur)[0]);
    }

    public function testTenantIsolationOnStockEndpoints(): void
    {
        $ownerT1 = $this->login('owner@t1.cm');
        [, $productsT1] = $this->call('GET', '/app/products?q=Paracetamol', null, $ownerT1);
        $productT1 = $productsT1['items'][0]['id'];

        $ownerT2 = $this->login('owner@t2.cm');
        $this->assertSame(404, $this->call('GET', '/app/stock/products/' . $productT1 . '/on-hand', null, $ownerT2)[0]);
    }

    public function testReceiptValidation(): void
    {
        $owner = $this->login('owner@t1.cm');
        [, $products] = $this->call('GET', '/app/products?q=Paracetamol', null, $owner);
        $productId = $products['items'][0]['id'];

        [$st, $err] = $this->call('POST', '/app/stock/receipts', [
            'product_id' => $productId,
            'lot_number' => '',
            'expiry' => 'invalid-date',
            'quantity' => 0,
            'unit_cost' => -3,
        ], $owner);
        $this->assertSame(422, $st);
        $this->assertSame('validation_failed', $err['error']['code']);
    }
}
