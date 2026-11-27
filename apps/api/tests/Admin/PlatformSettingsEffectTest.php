<?php
declare(strict_types=1);

namespace Sanara\Tests\Admin;

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

/** T312 : chaque paramètre global de la console a un effet réel. */
final class PlatformSettingsEffectTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    private Db $db;
    private Clock $clock;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        Seeder::platformDefaults($this->db);
        $this->clock = Clock::fixed('2026-11-27T09:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        $users->create(['email' => 'admin@s.cm', 'role_code' => 'super_admin', 'password_hash' => $hash]);
        $users->create(['email' => 'owner@t.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => $hash]);
        $users->create(['email' => 'pharmacist@t.cm', 'role_code' => 'pharmacist', 'tenant_id' => 'T1', 'password_hash' => $hash]);
        $this->db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie T1', 'slug' => 't1', 'status' => 'active', 'public_visible' => 1, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
    }

    private function app(bool $adminMfa = false): App
    {
        $env = ['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!', 'MFA_ADMIN' => $adminMfa ? '1' : '0'];
        $s = new Services($this->db, $this->clock, Config::fromEnv($env), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $s->publicProjection->refreshAll();
        return Bootstrap::createApp(sys_get_temp_dir(), $s);
    }

    private function set(string $key, string $value): void
    {
        $this->db->execute('DELETE FROM platform_settings WHERE skey = ?', [$key]);
        $this->db->insert('platform_settings', ['skey' => $key, 'svalue' => $value, 'updated_at' => '2026-11-27 09:00:00.000']);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(App $app, string $method, string $path, ?array $json = null, ?string $user = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.8.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($user !== null) {
            $this->tokens[$user] ??= (string) $this->call($app, 'POST', '/auth/login', ['email' => $user, 'password' => self::PW])[1]['access'];
            $r = $r->withHeader('Authorization', "Bearer {$this->tokens[$user]}");
        }
        $res = $app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function pharmacy(App $app): string
    {
        return (string) $this->call($app, 'GET', '/public/pharmacies')[1]['items'][0]['id'];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function send(App $app, string $phone = '+237 690 11 22 33'): array
    {
        return $this->call($app, 'POST', '/public/pharmacies/' . $this->pharmacy($app) . '/requests', [
            'consent' => true, 'first_name' => 'Awa', 'phone' => $phone, 'documents' => [['name' => 'o.pdf', 'base64' => base64_encode(self::PDF)]],
        ]);
    }

    public function testMaintenanceClosesTheServiceExceptForTheConsole(): void
    {
        $this->set('maintenance', '1');
        $app = $this->app();
        [$status, $body] = $this->call($app, 'GET', '/public/pharmacies');
        $this->assertSame([503, 'maintenance'], [$status, $body['error']['code']]);
        $this->assertSame(503, $this->call($app, 'POST', '/auth/login', ['email' => 'owner@t.cm', 'password' => self::PW])[0]);
        $this->assertSame(200, $this->call($app, 'GET', '/health')[0]);
        $this->assertSame(200, $this->call($app, 'GET', '/admin/settings', null, 'admin@s.cm')[0], 'le super admin garde la main');
        $this->assertSame(200, $this->call($app, 'GET', '/public/status')[0]);
        $this->assertTrue($this->call($app, 'GET', '/public/status')[1]['maintenance']);
    }

    public function testPatientsAreLimitedToTheDailyQuotaAcrossPharmacies(): void
    {
        $this->set('request_quota_per_day', '2');
        $app = $this->app();
        $this->assertSame(201, $this->send($app)[0]);
        $this->assertSame(201, $this->send($app)[0]);
        [$status, $body] = $this->send($app);
        $this->assertSame([429, 'too_many_requests'], [$status, $body['error']['code']]);
        $this->assertSame(201, $this->send($app, '+237 677 00 00 00')[0], 'un autre numéro');
    }

    public function testTheReservationDelayFollowsTheSetting(): void
    {
        $this->set('reservation_release_hours', '48');
        $app = $this->app();
        $this->send($app);
        $id = (string) $this->call($app, 'GET', '/app/requests', null, 'pharmacist@t.cm')[1]['items'][0]['id'];
        $this->call($app, 'POST', "/app/requests/$id/take", [], 'pharmacist@t.cm');
        [, $quote] = $this->call($app, 'POST', "/app/requests/$id/quote", ['lines' => [['label' => 'Introuvable', 'quantity' => 1]]], 'pharmacist@t.cm');
        $this->assertSame('2026-11-29 09:00:00.000', $quote['quote']['valid_until']);
    }

    public function testActivationLinksLastAsLongAsTheSettingSays(): void
    {
        $this->set('link_validity_hours', '72');
        $app = $this->app();
        $this->db->insert('pharmacy_applications', $this->application());
        $this->assertSame(200, $this->call($app, 'POST', '/admin/applications/A1/approve', ['plan' => 'essentiel', 'pos_count' => 1], 'admin@s.cm')[0]);
        $token = $this->db->one('SELECT expires_at FROM activation_tokens ORDER BY created_at DESC');
        $this->assertSame('2026-11-30 09:00:00.000', $token['expires_at']);
        $mail = $this->db->one("SELECT payload FROM jobs WHERE payload LIKE '%access_granted%'");
        $this->assertStringContainsString('72 heures', (string) $mail['payload']);
    }

    public function testTheSuperAdminSecondFactorFollowsTheSetting(): void
    {
        $login = fn (App $app) => $this->call($app, 'POST', '/auth/login', ['email' => 'admin@s.cm', 'password' => self::PW])[1];
        $this->assertTrue($login($this->app(true))['user']['mfa_setup_required'], 'exigée par défaut');
        $this->set('admin_mfa', '0');
        $this->assertFalse($login($this->app(true))['user']['mfa_setup_required']);
    }

    /** @return array<string, mixed> */
    private function application(): array
    {
        $row = ['id' => 'A1', 'number' => 'PH-2026-0001', 'tracking_code' => 'x', 'country_code' => 'CM', 'status' => 'pending', 'pharmacy_name' => 'Pharmacie Neuve', 'license_number' => 'AG-9', 'license_date' => '2020-01-01', 'pharmacy_phone' => '+237600000009', 'pos_count' => 1, 'takes_duty' => 0, 'offers_delivery_drop' => 0, 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Plateau', 'owner_last_name' => 'Mballa', 'owner_first_name' => 'Alain', 'owner_order_number' => 'O-1', 'owner_phone' => '+237600000002', 'owner_email' => 'neuve@t.cm', 'locale' => 'fr', 'allow_reapply' => 0];
        foreach ($this->db->all('PRAGMA table_info(pharmacy_applications)') as $c) {
            if ((int) $c['notnull'] === 1 && !isset($row[$c['name']])) {
                $row[$c['name']] = str_contains((string) $c['type'], 'INT') ? 0 : '2026-01-01 00:00:00.000';
            }
        }
        return $row;
    }
}
