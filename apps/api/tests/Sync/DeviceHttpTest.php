<?php
declare(strict_types=1);

namespace Sanara\Tests\Sync;

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

/** T129 : enregistrement d'un poste, nom unique par pharmacie, dans la limite de l'abonnement. */
final class DeviceHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        $clock = Clock::fixed('2026-11-27T08:00:00Z');
        $users = new UserRepository($this->db, $clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        foreach (['cashier' => 'T1', 'storekeeper' => 'T1', 'other' => 'T2'] as $name => $tenant) {
            $users->create(['email' => "$name@t.cm", 'role_code' => $name === 'other' ? 'owner' : $name, 'tenant_id' => $tenant, 'password_hash' => $hash]);
        }
        foreach (['T1', 'T2'] as $t) {
            $this->db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "Pharmacie $t", 'slug' => strtolower($t), 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => "AG-$t", 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        }
        $services = new Services($this->db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $services);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $path, array $json, ?string $token = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest('POST', $path, ['REMOTE_ADDR' => '41.0.2.' . random_int(1, 250)])
            ->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        if ($token !== null) {
            $r = $r->withHeader('Authorization', "Bearer $token");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function register(string $device, string $name, string $user = 'cashier'): array
    {
        $token = $this->tokens[$user] ??= $this->call('/auth/login', ['email' => "$user@t.cm", 'password' => self::PW])[1]['access'];
        return $this->call('/sync/device', ['deviceId' => $device, 'name' => $name], $token);
    }

    public function testRegistersAPostUnderAUniqueNameAndLetsItBeRenamed(): void
    {
        [$status, $body] = $this->register('poste-a', 'Caisse 1');
        self::assertSame(200, $status);
        self::assertSame('CAISSE1', $body['name']);

        [$status, $body] = $this->register('poste-b', 'caisse-1');
        self::assertSame(409, $status);
        self::assertSame('station_name_taken', $body['error']['code']);

        self::assertSame(200, $this->register('poste-a', 'Comptoir')[0]);
        self::assertSame(200, $this->register('poste-b', 'Caisse 1')[0]);
        self::assertSame(2, (int) $this->db->one('SELECT COUNT(*) AS n FROM devices WHERE tenant_id = ?', ['T1'])['n']);
        // Les noms sont propres à chaque pharmacie.
        self::assertSame(200, $this->register('poste-c', 'Comptoir', 'other')[0]);
    }

    public function testRefusesABlankNameAndRolesWithoutTheTill(): void
    {
        self::assertSame(422, $this->register('poste-a', ' -- ')[0]);
        self::assertSame(422, $this->register('', 'Caisse')[0]);
        self::assertSame(403, $this->register('poste-a', 'Caisse', 'storekeeper')[0]);
    }

    public function testStaysWithinTheNumberOfPostsOfTheSubscription(): void
    {
        $this->db->insert('subscriptions', ['id' => 'S1', 'pharmacy_id' => 'T1', 'plan_id' => 'P1', 'status' => 'active', 'stations' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        self::assertSame(200, $this->register('poste-a', 'A')[0]);
        [$status, $body] = $this->register('poste-b', 'B');
        self::assertSame(409, $status);
        self::assertSame('station_limit_reached', $body['error']['code']);
        // Un poste déjà enregistré reste accepté ; un poste révoqué libère sa place.
        self::assertSame(200, $this->register('poste-a', 'A2')[0]);
        $this->db->execute('UPDATE devices SET revoked_at = ? WHERE fingerprint = ?', ['2026-11-27 08:00:00.000', 'poste-a']);
        self::assertSame(200, $this->register('poste-b', 'B')[0]);
        [$status, $body] = $this->register('poste-a', 'A');
        self::assertSame(403, $status);
        self::assertSame('device_revoked', $body['error']['code']);
    }
}
