<?php
declare(strict_types=1);

namespace Sanara\Tests\Tenants;

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

final class TeamHttpTest extends TestCase
{
    private App $app;
    private Services $s;

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        $clock = Clock::fixed('2026-11-24T10:00:00Z');
        $db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'P', 'slug' => 'p', 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => $clock->stamp(), 'updated_at' => $clock->stamp()]);
        (new UserRepository($db, $clock))->create(['email' => 'owner@t1.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash('Owner#Motdepasse1')]);
        $config = Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!', 'APP_URL' => 'https://app.example']);
        $this->s = new Services($db, $clock, $config, new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $m, string $p, ?array $j = null, ?string $tok = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($m, $p, ['REMOTE_ADDR' => '41.0.0.9']);
        if ($j !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($j)));
        }
        if ($tok) {
            $r = $r->withHeader('Authorization', "Bearer $tok");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function login(string $e, string $p): string
    {
        return $this->call('POST', '/auth/login', ['email' => $e, 'password' => $p])[1]['access'];
    }

    public function testOwnerInvitesCashierWhoCannotManageTheTeamAndDenialIsAudited(): void
    {
        $owner = $this->login('owner@t1.cm', 'Owner#Motdepasse1');
        [$st, $r] = $this->call('POST', '/app/team', ['name' => 'Ibrahim', 'email' => 'ibrahim@t1.cm', 'role' => 'cashier'], $owner);
        $this->assertSame(201, $st);

        $job = $this->s->db->one('SELECT payload FROM jobs WHERE payload LIKE ?', ['%member_invited%']);
        preg_match('#/activer/([\w\-]+)#', (string) json_decode($job['payload'], true)['vars']['lien_activation'], $m);
        $this->assertSame(204, $this->call('POST', '/public/activation/' . $m[1], ['password' => 'Cashier#Secure2026'])[0]);

        $cashier = $this->login('ibrahim@t1.cm', 'Cashier#Secure2026');
        $this->assertSame(403, $this->call('GET', '/app/team', null, $cashier)[0]);
        $this->assertNotNull($this->s->db->one("SELECT id FROM audit_logs WHERE action = 'access.denied' AND entity_id = 'team.manage'"));

        $this->assertSame(204, $this->call('PUT', '/app/team/' . $r['id'] . '/role', ['role' => 'storekeeper'], $owner)[0]);
        [, $me] = $this->call('GET', '/auth/me', null, $cashier);
        $this->assertContains('stock.write', $me['permissions'], 'effet immédiat sans reconnexion');
    }

    public function testTeamListReturnsEffectivePermissionsAndOverrides(): void
    {
        $owner = $this->login('owner@t1.cm', 'Owner#Motdepasse1');
        [$st, $invite] = $this->call('POST', '/app/team', ['name' => 'Kossi', 'email' => 'kossi@t1.cm', 'role' => 'preparateur'], $owner);
        $this->assertSame(201, $st);

        $this->assertSame(204, $this->call('PUT', '/app/team/' . $invite['id'] . '/permissions/finance.read', ['granted' => true], $owner)[0]);
        $this->assertSame(204, $this->call('PUT', '/app/team/' . $invite['id'] . '/permissions/sales.discount', ['granted' => false], $owner)[0]);

        [, $team] = $this->call('GET', '/app/team', null, $owner);
        $member = null;
        foreach ($team['members'] as $m) {
            if (($m['id'] ?? null) === $invite['id']) {
                $member = $m;
                break;
            }
        }
        $this->assertNotNull($member);
        $this->assertSame('preparateur', $member['role_code']);
        $this->assertContains('finance.read', $member['permissions']);
        $this->assertNotContains('sales.discount', $member['permissions']);

        $overrides = [];
        foreach ($member['permission_overrides'] as $o) {
            $overrides[$o['permission_code']] = (bool) $o['granted'];
        }
        $this->assertArrayHasKey('finance.read', $overrides);
        $this->assertArrayHasKey('sales.discount', $overrides);
        $this->assertTrue($overrides['finance.read']);
        $this->assertFalse($overrides['sales.discount']);
    }

    public function testSettingsAndOnboardingThroughHttp(): void
    {
        $owner = $this->login('owner@t1.cm', 'Owner#Motdepasse1');
        $this->assertSame(204, $this->call('PUT', '/app/settings', ['settings' => ['ticket_footer' => 'Merci']], $owner)[0]);
        [, $s] = $this->call('GET', '/app/settings', null, $owner);
        $this->assertSame('Merci', $s['settings']['ticket_footer']);
        [, $o] = $this->call('POST', '/app/onboarding/hours', [], $owner);
        $this->assertSame('team', $o['current']);
        $this->assertSame(204, $this->call('PUT', '/app/visibility', ['visible' => true], $owner)[0]);
        $this->assertSame(1, (int) $this->s->db->one("SELECT public_visible FROM tenants WHERE id = 'T1'")['public_visible']);
        $this->assertSame(422, $this->call('PUT', '/app/settings', ['settings' => ['hack' => 1]], $owner)[0]);
    }

    public function testAppRoutesRejectSuperAdminWithoutTenant(): void
    {
        (new UserRepository($this->s->db, $this->s->clock))->create(['email' => 'sa@x.cm', 'role_code' => 'super_admin', 'password_hash' => (new PasswordHasher())->hash('Admin#Motdepasse1')]);
        $tok = $this->login('sa@x.cm', 'Admin#Motdepasse1');
        $this->assertSame(403, $this->call('GET', '/app/team', null, $tok)[0]);
    }
}
