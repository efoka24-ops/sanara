<?php
declare(strict_types=1);

namespace Sanara\Tests\Security;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Crypto\Totp;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class SecurityHttpTest extends TestCase
{
    private App $app;
    private Services $s;
    private const PW = 'Motdepasse#2026';

    protected function boot(bool $enforceAdmin): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        $clock = new Clock();
        $this->s = new Services($db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'MFA_ADMIN' => $enforceAdmin ? '1' : '0', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!', 'APP_URL' => 'https://app.example']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $u = new UserRepository($db, $clock);
        $u->create(['email' => 'owner@t1.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $u->create(['email' => 'sa@x.cm', 'role_code' => 'super_admin', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
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
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    public function testFullMfaJourneyThroughHttp(): void
    {
        $this->boot(false);
        [, $login] = $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => self::PW]);
        [, $me0] = $this->call('GET', '/auth/me', null, $login['access']);
        $this->assertFalse($me0['mfa_enabled']);
        [, $setup] = $this->call('POST', '/auth/mfa/begin', null, $login['access']);
        $this->assertStringStartsWith('otpauth://totp/', $setup['uri']);
        [$st, $codes] = $this->call('POST', '/auth/mfa/confirm', ['code' => Totp::code($setup['secret'], time())], $login['access']);
        $this->assertSame(200, $st);
        $this->assertCount(8, $codes['backup_codes']);

        [, $second] = $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => self::PW]);
        $this->assertTrue($second['mfa_required']);
        $this->assertSame(401, $this->call('GET', '/auth/me', null, $second['mfa_token'])[0], 'le jeton intermédiaire n\'ouvre rien');
        $this->assertSame(401, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $second['mfa_token'], 'code' => '000000'])[0]);
        [$st, $done] = $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $second['mfa_token'], 'code' => $codes['backup_codes'][0]]);
        $this->assertSame(200, $st);
        $this->assertSame('owner', $this->call('GET', '/auth/me', null, $done['access'])[1]['role']);
    }

    public function testSuperAdminMustEnrolMfaBeforeUsingTheConsole(): void
    {
        $this->boot(true);
        [, $login] = $this->call('POST', '/auth/login', ['email' => 'sa@x.cm', 'password' => self::PW]);
        $this->assertTrue($login['user']['mfa_setup_required']);
        [$st, $r] = $this->call('GET', '/admin/applications', null, $login['access']);
        $this->assertSame([403, 'mfa_required'], [$st, $r['error']['code']]);
        [, $me] = $this->call('GET', '/auth/me', null, $login['access']);
        $this->assertTrue($me['mfa_setup_required']);
        [, $setup] = $this->call('POST', '/auth/mfa/begin', null, $login['access']);
        $this->call('POST', '/auth/mfa/confirm', ['code' => Totp::code($setup['secret'], time())], $login['access']);
        [, $again] = $this->call('POST', '/auth/login', ['email' => 'sa@x.cm', 'password' => self::PW]);
        [, $ok] = $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $again['mfa_token'], 'code' => Totp::code($setup['secret'], time() + 30)]);
        $this->assertSame(200, $this->call('GET', '/admin/applications', null, $ok['access'])[0]);
    }

    public function testPasswordResetAndSessionsThroughHttp(): void
    {
        $this->boot(false);
        [, $login] = $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => self::PW, 'device_label' => 'Caisse 1']);
        [, $list] = $this->call('GET', '/auth/sessions', null, $login['access']);
        $this->assertSame('Caisse 1', $list['sessions'][0]['label']);

        $this->assertSame(204, $this->call('POST', '/auth/password/forgot', ['email' => 'owner@t1.cm'])[0]);
        $this->assertSame(204, $this->call('POST', '/auth/password/forgot', ['email' => 'inconnu@x.cm'])[0]);
        $job = $this->s->db->one('SELECT payload FROM jobs WHERE payload LIKE ?', ['%password_reset%']);
        preg_match('#/reinitialiser/([\w\-]+)#', json_decode($job['payload'], true)['vars']['lien'], $m);
        $this->assertSame(204, $this->call('POST', '/auth/password/reset/' . $m[1], ['password' => 'Nouveau#Secret2027'])[0]);
        $this->assertSame(401, $this->call('POST', '/auth/refresh', ['refresh' => $login['refresh']])[0], 'sessions révoquées');
        $this->assertSame(200, $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => 'Nouveau#Secret2027'])[0]);
    }

    public function testPinThroughHttp(): void
    {
        $this->boot(false);
        [, $login] = $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => self::PW]);
        $this->assertSame(204, $this->call('PUT', '/auth/pin', ['pin' => '4821'], $login['access'])[0]);
        $this->assertTrue($this->call('POST', '/auth/pin/verify', ['pin' => '4821'], $login['access'])[1]['valid']);
        $this->assertFalse($this->call('POST', '/auth/pin/verify', ['pin' => '1111'], $login['access'])[1]['valid']);
        $this->assertSame(422, $this->call('PUT', '/auth/pin', ['pin' => 'abcd'], $login['access'])[0]);
    }
}
