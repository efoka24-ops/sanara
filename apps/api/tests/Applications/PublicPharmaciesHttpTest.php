<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

final class PublicPharmaciesHttpTest extends TestCase
{
    private App $app;
    private Services $s;

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);

        $clock = Clock::fixed('2026-11-24T10:00:00Z');
        $config = Config::fromEnv([
            'APP_ENV' => 'testing',
            'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!',
        ]);

        $storage = new EncryptedStorage(sys_get_temp_dir() . '/sanara-public-search-' . bin2hex(random_bytes(4)), random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 'url-secret-0123456789-0123456789-xx');
        $this->s = new Services($db, $clock, $config, $storage);
        $this->seedPublicPharmacies();
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    private function seedPublicPharmacies(): void
    {
        $now = '2026-11-24 10:00:00.000';

        $this->s->db->insert('public_pharmacies', [
            'id' => '01KAT00000000000000000001',
            'tenant_id' => '01KAT00000000000000010001',
            'pharmacy_name' => 'Pharmacie Centrale Yaounde',
            'region' => 'Centre',
            'city' => 'Yaounde',
            'district' => 'Bastos',
            'address' => 'Avenue de l\'independance',
            'phone' => '+237600000010',
            'visible' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->s->db->insert('public_pharmacies', [
            'id' => '01KAT00000000000000000002',
            'tenant_id' => '01KAT00000000000000010002',
            'pharmacy_name' => 'Pharmacie du Marche',
            'region' => 'Littoral',
            'city' => 'Douala',
            'district' => 'Akwa',
            'address' => 'Boulevard de la Republique',
            'phone' => '+237600000011',
            'visible' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->s->db->insert('public_pharmacies', [
            'id' => '01KAT00000000000000000003',
            'tenant_id' => '01KAT00000000000000010003',
            'pharmacy_name' => 'Pharmacie Cachee',
            'region' => 'Centre',
            'city' => 'Yaounde',
            'district' => 'Mokolo',
            'address' => 'Rue cachee',
            'phone' => '+237600000012',
            'visible' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $path): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('GET', $path);
        $res = $this->app->handle($req);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    public function testHiddenPharmacyIsNotReturnedByDefault(): void
    {
        [$st, $r] = $this->call('/public/pharmacies');

        $this->assertSame(200, $st);
        $this->assertSame(2, $r['total']);
        $names = array_column($r['items'], 'pharmacy_name');
        $this->assertContains('Pharmacie Centrale Yaounde', $names);
        $this->assertContains('Pharmacie du Marche', $names);
        $this->assertNotContains('Pharmacie Cachee', $names);
    }

    public function testTextFilterMatchesByNameOrCity(): void
    {
        [$st1, $r1] = $this->call('/public/pharmacies?q=centrale');
        $this->assertSame(200, $st1);
        $this->assertSame(1, $r1['total']);
        $this->assertSame('Pharmacie Centrale Yaounde', $r1['items'][0]['pharmacy_name']);

        [$st2, $r2] = $this->call('/public/pharmacies?q=douala');
        $this->assertSame(200, $st2);
        $this->assertSame(1, $r2['total']);
        $this->assertSame('Pharmacie du Marche', $r2['items'][0]['pharmacy_name']);
    }

    public function testBasicPaginationWorks(): void
    {
        [$st, $r] = $this->call('/public/pharmacies?limit=1&offset=1');

        $this->assertSame(200, $st);
        $this->assertSame(2, $r['total']);
        $this->assertSame(1, $r['limit']);
        $this->assertSame(1, $r['offset']);
        $this->assertCount(1, $r['items']);
        $this->assertSame('Pharmacie du Marche', $r['items'][0]['pharmacy_name']);
    }
}
