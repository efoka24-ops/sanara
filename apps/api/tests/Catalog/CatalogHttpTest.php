<?php
declare(strict_types=1);

namespace Sanara\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Catalog\ReferenceCatalog;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class CatalogHttpTest extends TestCase
{
    private App $app;
    private const PW = 'Motdepasse#2026';

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        (new ReferenceCatalog($db))->seed('CM');
        $clock = new Clock();
        $u = new UserRepository($db, $clock);
        foreach (['owner', 'cashier', 'storekeeper'] as $role) {
            $u->create(['email' => "$role@t1.cm", 'role_code' => $role, 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        }
        $u->create(['email' => 'owner@t2.cm', 'role_code' => 'owner', 'tenant_id' => 'T2', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $s = new Services($db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
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

    private function tok(string $email): string
    {
        return $this->call('POST', '/auth/login', ['email' => $email, 'password' => self::PW])[1]['access'];
    }

    public function testRolesAndCatalogueFlow(): void
    {
        $owner = $this->tok('owner@t1.cm');
        $cashier = $this->tok('cashier@t1.cm');
        $payload = ['name' => 'Amoxicilline 500 mg', 'dci' => 'Amoxicilline', 'regulatory_status' => 'prescription_only', 'sale_price' => 2800, 'purchase_price' => 1950, 'barcodes' => ['6000000000012']];

        $this->assertSame(403, $this->call('POST', '/app/products', $payload, $cashier)[0], 'le caissier ne modifie pas le catalogue');
        [$st, $c] = $this->call('POST', '/app/products', $payload, $owner);
        $this->assertSame(201, $st);
        $this->assertSame(409, $this->call('POST', '/app/products', $payload, $owner)[0], 'code-barres déjà utilisé');
        $this->assertSame(422, $this->call('POST', '/app/products', ['name' => 'X'] + ['regulatory_status' => ''], $owner)[0]);

        [, $found] = $this->call('GET', '/app/products?q=6000000000012', null, $cashier);
        $this->assertSame($c['id'], $found['items'][0]['id'], 'le caissier peut chercher');
        $this->assertSame(204, $this->call('PUT', '/app/products/' . $c['id'], ['sale_price' => 3000, 'price_reason' => 'Fournisseur'], $owner)[0]);
        $detail = $this->call('GET', '/app/products/' . $c['id'], null, $owner)[1];
        $this->assertCount(3, $detail['price_history']);
        $this->assertSame(204, $this->call('POST', '/app/products/' . $c['id'] . '/active', ['active' => false], $owner)[0]);
        $this->assertSame([], $this->call('GET', '/app/products?q=amox', null, $owner)[1]['items']);
    }

    public function testImportExportAndReferenceAreTenantScoped(): void
    {
        $owner = $this->tok('owner@t1.cm');
        [$st, $r] = $this->call('POST', '/app/products/reference', [], $owner);
        $this->assertSame(200, $st);
        $this->assertGreaterThanOrEqual(30, $r['added']);
        $csv = "name;status;sale\nNouveau produit;free;500\n;free;1\n";
        [, $imp] = $this->call('POST', '/app/products/import', ['csv' => $csv], $owner);
        $this->assertSame(1, $imp['created'] ?? null, json_encode($imp));
        $this->assertCount(1, $imp['rejected']);
        [, , $export] = $this->call('GET', '/app/products/export', null, $owner);
        $this->assertStringContainsString('Nouveau produit', $export);

        $other = $this->tok('owner@t2.cm');
        $this->assertSame([], $this->call('GET', '/app/products?q=Nouveau', null, $other)[1]['items'], 'isolation entre pharmacies');
        $this->assertSame(403, $this->call('POST', '/app/products/import', ['csv' => $csv], $this->tok('storekeeper@t1.cm'))[0]);
    }
}
