<?php
declare(strict_types=1);

namespace Sanara\Tests\Security;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Auth\AuthException;
use Sanara\Common\Auth\AuthService;
use Sanara\Common\Auth\JwtService;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Auth\RefreshTokenService;
use Sanara\Common\Clock;
use Sanara\Common\Crypto\SecretBox;
use Sanara\Common\Crypto\Totp;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Modules\Security\MfaService;
use Sanara\Modules\Security\PasswordResetService;
use Sanara\Modules\Security\PinService;
use Sanara\Modules\Security\SessionService;
use Sanara\Tests\Support\TestDb;

final class SecurityTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private UserRepository $users;
    private JobQueue $jobs;
    private AuthService $auth;
    private MfaService $mfa;
    private string $uid;
    private const SECRET = 'test-secret-with-at-least-32-characters!!';
    private const PW = 'Motdepasse#2026';

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $this->users = new UserRepository($this->db, $this->clock);
        $this->jobs = new JobQueue($this->db, $this->clock);
        $this->uid = $this->users->create(['email' => 'o@t1.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $this->mfa = new MfaService($this->db, $this->clock, new SecretBox(random_bytes(32)), 'Sanara');
        $this->auth = new AuthService($this->users, new PasswordHasher(), new JwtService(self::SECRET, $this->clock), new RefreshTokenService($this->db, $this->clock), $this->clock, $this->mfa);
    }

    public function testTotpMatchesRfc6238Vectors(): void
    {
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // « 12345678901234567890 » en base32
        $this->assertSame('287082', Totp::code($secret, 59));
        $this->assertSame('081804', Totp::code($secret, 1111111109));
        $this->assertSame('050471', Totp::code($secret, 1111111111));
    }

    public function testTotpAcceptsOneStepDriftButNotReplay(): void
    {
        $s = Totp::generateSecret();
        $t = 1_700_000_000;
        $c = Totp::code($s, $t);
        $counter = Totp::verify($s, $c, $t + 30, 1);
        $this->assertNotNull($counter, 'un pas de dérive d\'horloge est toléré');
        $this->assertNull(Totp::verify($s, $c, $t + 30, 1, $counter), 'le même code ne sert qu\'une fois');
        $this->assertNull(Totp::verify($s, $c, $t + 120, 1), 'au-delà de la fenêtre');
        $this->assertNull(Totp::verify($s, '000000', $t));
    }

    public function testSecretBoxRoundTripAndTamper(): void
    {
        $b = new SecretBox(random_bytes(32));
        $enc = $b->encrypt('secret');
        $this->assertStringNotContainsString('secret', $enc);
        $this->assertSame('secret', $b->decrypt($enc));
        $this->expectException(\RuntimeException::class);
        $b->decrypt(substr($enc, 0, -2) . 'AA');
    }

    private function enableMfa(): string
    {
        $setup = $this->mfa->begin($this->uid);
        $codes = $this->mfa->confirm($this->uid, Totp::code($setup['secret'], $this->clock->now()->getTimestamp()));
        $this->assertCount(8, $codes);
        return $setup['secret'];
    }

    public function testMfaSetupStoresEncryptedSecretAndHashedBackupCodes(): void
    {
        $secret = $this->enableMfa();
        $row = $this->db->one('SELECT totp_secret_enc, totp_backup_codes, totp_enabled FROM users');
        $this->assertSame(1, (int) $row['totp_enabled']);
        $this->assertStringNotContainsString($secret, (string) $row['totp_secret_enc']);
        $this->assertStringNotContainsString('-', (string) $row['totp_backup_codes']);
    }

    public function testLoginRequiresSecondFactorWhenEnabled(): void
    {
        $secret = $this->enableMfa();
        $r = $this->auth->login('o@t1.cm', self::PW);
        $this->assertTrue($r['mfa_required']);
        $this->assertArrayNotHasKey('access', $r);
        $this->clock->advance('+30 seconds');
        $done = $this->auth->completeMfa($r['mfa_token'], Totp::code($secret, $this->clock->now()->getTimestamp()));
        $this->assertNotEmpty($done['access']);
    }

    public function testWrongSecondFactorFailsAndCountsTowardLockout(): void
    {
        $this->enableMfa();
        $r = $this->auth->login('o@t1.cm', self::PW);
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->completeMfa($r['mfa_token'], '000000');
            } catch (AuthException) {
            }
        }
        $this->expectException(AuthException::class);
        $this->auth->login('o@t1.cm', self::PW);
    }

    public function testBackupCodeWorksOnceOnly(): void
    {
        $setup = $this->mfa->begin($this->uid);
        $codes = $this->mfa->confirm($this->uid, Totp::code($setup['secret'], $this->clock->now()->getTimestamp()));
        $t = $this->auth->login('o@t1.cm', self::PW)['mfa_token'];
        $this->assertNotEmpty($this->auth->completeMfa($t, $codes[0])['access']);
        $this->expectException(AuthException::class);
        $this->auth->completeMfa($t, $codes[0]);
    }

    public function testMfaTokenCannotBeUsedAsAccessToken(): void
    {
        $this->enableMfa();
        $t = $this->auth->login('o@t1.cm', self::PW)['mfa_token'];
        $claims = (new JwtService(self::SECRET, $this->clock))->verify($t);
        $this->assertSame('mfa', $claims['pur']);
    }

    public function testSuperAdminWithoutMfaGetsSetupScopeOnly(): void
    {
        $this->users->create(['email' => 'sa@x.cm', 'role_code' => 'super_admin', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $auth = new AuthService($this->users, new PasswordHasher(), new JwtService(self::SECRET, $this->clock), new RefreshTokenService($this->db, $this->clock), $this->clock, $this->mfa, enforceAdminMfa: true);
        $r = $auth->login('sa@x.cm', self::PW);
        $this->assertSame('setup', (new JwtService(self::SECRET, $this->clock))->verify($r['access'])['scope']);
    }

    public function testPasswordResetIsSingleUseRevokesSessionsAndChecksHistory(): void
    {
        $refresh = new RefreshTokenService($this->db, $this->clock);
        $login = $this->auth->login('o@t1.cm', self::PW);
        $svc = new PasswordResetService($this->db, $this->clock, new PasswordHasher(), $this->jobs, 'https://app.example');
        $svc->request('o@t1.cm');
        $svc->request('inconnu@x.cm');
        $mail = $this->jobs->reserve('mail');
        $this->assertSame('password_reset', $mail['payload']['template']);
        $this->assertNull($this->jobs->reserve('mail'), 'aucun e-mail pour un compte inconnu');
        preg_match('#/reinitialiser/([\w\-]+)#', $mail['payload']['vars']['lien'], $m);

        try {
            $svc->reset($m[1], self::PW); // ancien mot de passe interdit
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }
        $svc->reset($m[1], 'Nouveau#Secret2027');
        $this->assertNotNull($this->db->one('SELECT revoked_at FROM refresh_tokens')['revoked_at'], 'sessions révoquées');
        $this->assertNotEmpty($this->auth->login('o@t1.cm', 'Nouveau#Secret2027')['access']);
        $this->expectException(ApiException::class);
        $svc->reset($m[1], 'Autre#Secret2028');
    }

    public function testResetTokenExpiresAfterOneHour(): void
    {
        $svc = new PasswordResetService($this->db, $this->clock, new PasswordHasher(), $this->jobs, 'https://app.example');
        $svc->request('o@t1.cm');
        preg_match('#/reinitialiser/([\w\-]+)#', $this->jobs->reserve('mail')['payload']['vars']['lien'], $m);
        $this->clock->advance('+61 minutes');
        $this->expectException(ApiException::class);
        $svc->reset($m[1], 'Nouveau#Secret2027');
    }

    public function testLockoutQueuesNotificationMail(): void
    {
        $queued = [];
        $auth = new AuthService($this->users, new PasswordHasher(), new JwtService(self::SECRET, $this->clock), new RefreshTokenService($this->db, $this->clock), $this->clock, $this->mfa, onLocked: function (array $u) use (&$queued) { $queued[] = $u['email']; });
        for ($i = 0; $i < 5; $i++) {
            try {
                $auth->login('o@t1.cm', 'faux');
            } catch (AuthException) {
            }
        }
        $this->assertSame(['o@t1.cm'], $queued);
    }

    public function testSessionsListAndRemoteRevocation(): void
    {
        $this->auth->login('o@t1.cm', self::PW, null, 'Caisse 1');
        $b = $this->auth->login('o@t1.cm', self::PW, null, 'Navigateur');
        $sessions = new SessionService($this->db, $this->clock);
        $list = $sessions->list($this->uid);
        $this->assertCount(2, $list);
        $this->assertContains('Caisse 1', array_column($list, 'label'));
        $sessions->revoke($this->uid, $list[0]['family_id']);
        $this->assertCount(1, $sessions->list($this->uid));
        $sessions->revokeAll($this->uid);
        $this->assertCount(0, $sessions->list($this->uid));
        $this->assertNotEmpty($b['refresh']);
    }

    public function testCannotRevokeAnotherUsersSession(): void
    {
        $other = $this->users->create(['email' => 'x@t1.cm', 'role_code' => 'cashier', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        $this->auth->login('x@t1.cm', self::PW, null, 'Autre');
        $sessions = new SessionService($this->db, $this->clock);
        $fam = $sessions->list($other)[0]['family_id'];
        $sessions->revoke($this->uid, $fam);
        $this->assertCount(1, $sessions->list($other));
    }

    public function testPinSetVerifyAndLockAfterFiveFailures(): void
    {
        $pin = new PinService($this->db, new PasswordHasher());
        $pin->set($this->uid, '4821');
        $this->assertTrue($pin->verify($this->uid, '4821'));
        for ($i = 0; $i < 5; $i++) {
            $this->assertFalse($pin->verify($this->uid, '0000'));
        }
        $this->expectException(ApiException::class);
        $pin->verify($this->uid, '4821'); // verrouillé : le bon PIN ne passe plus
    }

    public function testPinMustBeFourDigits(): void
    {
        $this->expectException(ApiException::class);
        (new PinService($this->db, new PasswordHasher()))->set($this->uid, '12ab');
    }
}
