<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

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

/** T144, T145 : la recherche publique suit la publication et la suspension, sans jamais exposer le reste. */
final class PublicProjectionHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    private Services $s;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        $clock = Clock::fixed('2026-11-27T08:00:00Z');
        $users = new UserRepository($this->db, $clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        $users->create(['email' => 'owner@t.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => $hash]);
        $users->create(['email' => 'admin@s.cm', 'role_code' => 'super_admin', 'password_hash' => $hash]);
        $this->db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie du Plateau', 'slug' => 't1', 'status' => 'active', 'address' => 'Rue 12', 'phone' => '+237600000001', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        $this->db->insert('pharmacy_applications', ['id' => 'A1', 'number' => 'PH-2026-0001', 'tracking_code' => 'x', 'country_code' => 'CM', 'status' => 'approved', 'pharmacy_name' => 'Pharmacie du Plateau', 'license_number' => 'AG-1', 'license_date' => '2020-01-01', 'pharmacy_phone' => '+237600000001', 'pos_count' => 1, 'takes_duty' => 0, 'offers_delivery_drop' => 0, 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Plateau', 'owner_last_name' => 'Mballa', 'owner_first_name' => 'Alain', 'owner_order_number' => 'O-1', 'owner_phone' => '+237600000002', 'owner_email' => 'owner@t.cm', 'locale' => 'fr', 'tenant_id' => 'T1'] + $this->requiredApplicationColumns());
        $this->s = new Services($this->db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    /** Colonnes obligatoires de la demande sans intérêt pour ce test. @return array<string, mixed> */
    private function requiredApplicationColumns(): array
    {
        $out = [];
        $given = ['id', 'number', 'tracking_code', 'country_code', 'status', 'pharmacy_name', 'license_number', 'license_date', 'pharmacy_phone', 'pos_count', 'takes_duty', 'offers_delivery_drop', 'region', 'department', 'city', 'district', 'owner_last_name', 'owner_first_name', 'owner_order_number', 'owner_phone', 'owner_email', 'locale', 'tenant_id'];
        foreach ($this->db->all('PRAGMA table_info(pharmacy_applications)') as $c) {
            if ((int) $c['notnull'] === 1 && !in_array($c['name'], $given, true)) {
                $out[$c['name']] = str_contains((string) $c['type'], 'INT') ? 0 : '2026-01-01 00:00:00.000';
            }
        }
        return $out;
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $email = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.3.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($email !== null) {
            $token = $this->call('POST', '/auth/login', ['email' => $email, 'password' => self::PW])[1];
            $r = $r->withHeader('Authorization', 'Bearer ' . ($token['access'] ?? $token['setup_token'] ?? ''));
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    /** @return list<array<string, mixed>> */
    private function found(string $query = ''): array
    {
        return $this->call('GET', "/public/pharmacies$query")[1]['items'];
    }

    public function testPublishingAddsThePharmacyAndUnpublishingRemovesIt(): void
    {
        $this->assertSame([], $this->found());

        $this->assertSame(204, $this->call('PUT', '/app/visibility', ['visible' => true], 'owner@t.cm')[0]);
        $items = $this->found();
        $this->assertCount(1, $items);
        $this->assertSame(
            ['pharmacy_name' => 'Pharmacie du Plateau', 'region' => 'Nord', 'city' => 'Garoua', 'district' => 'Plateau', 'address' => 'Rue 12', 'phone' => '+237600000001'],
            array_diff_key($items[0], ['id' => 1]),
            'ni identifiant de pharmacie ni indicateur interne',
        );

        $this->call('PUT', '/app/visibility', ['visible' => false], 'owner@t.cm');
        $this->assertSame([], $this->found());
        $this->assertSame([], $this->found('?visible=false'), 'une pharmacie non publiée ne s’obtient jamais');
    }

    public function testSuspensionHidesThePharmacyAndReactivationDoesNotRepublishIt(): void
    {
        $this->call('PUT', '/app/visibility', ['visible' => true], 'owner@t.cm');
        $this->s->admin->suspend('T1', 'Impayés', 'admin');
        $this->assertSame([], $this->found('?visible=false'));
        $this->s->admin->reactivate('T1');
        $this->assertSame([], $this->found());
    }

    public function testExistingPublishedPharmaciesAreBackfilled(): void
    {
        $this->db->execute('UPDATE tenants SET public_visible = 1 WHERE id = ?', ['T1']);
        $this->assertSame([], $this->found());
        $this->s->publicProjection->refreshAll();
        $this->s->publicProjection->refreshAll();
        $this->assertCount(1, $this->found());
    }

    public function testSearchWildcardsAreTakenLiterally(): void
    {
        $this->call('PUT', '/app/visibility', ['visible' => true], 'owner@t.cm');
        $this->assertCount(1, $this->found('?q=plat'));
        $this->assertSame([], $this->found('?q=' . urlencode('%')));
        $this->assertSame([], $this->found('?q=' . urlencode('_')));
    }
}
