<?php
declare(strict_types=1);

namespace Sanara\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Auth\AuthException;
use Sanara\Common\Auth\AuthService;
use Sanara\Common\Auth\JwtService;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Auth\RefreshTokenService;
use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Tests\Support\TestDb;

final class AuthTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private AuthService $auth;
    private UserRepository $users;
    private const SECRET = 'test-secret-with-at-least-32-characters!!';

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $this->users = new UserRepository($this->db, $this->clock);
        $this->users->create(['email' => 'dr.ngo@pharmacie.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => (new PasswordHasher())->hash('Motdepasse#2026')]);
        $this->auth = new AuthService(
            $this->users,
            new PasswordHasher(),
            new JwtService(self::SECRET, $this->clock),
            new RefreshTokenService($this->db, $this->clock),
            $this->clock,
        );
    }

    public function testPasswordIsHashedWithArgon2idAndPolicyEnforced(): void
    {
        $h = new PasswordHasher();
        $hash = $h->hash('Motdepasse#2026');
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue($h->verify('Motdepasse#2026', $hash));
        $this->assertFalse($h->verify('autre', $hash));
        foreach (['court1#A', 'sansmajuscule1#abc', 'SansChiffre#abcdef', 'SansSpecial1abcdef'] as $bad) {
            $this->assertFalse($h->meetsPolicy($bad), $bad);
        }
        $this->assertTrue($h->meetsPolicy('Motdepasse#2026'));
    }

    public function testLoginReturnsTokensAndAccessTokenCarriesTenantAndRole(): void
    {
        $r = $this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026');
        $claims = (new JwtService(self::SECRET, $this->clock))->verify($r['access']);
        $this->assertSame('T1', $claims['tid']);
        $this->assertSame('owner', $claims['role']);
        $this->assertNotEmpty($r['refresh']);
    }

    public function testWrongPasswordAndUnknownUserGiveSameError(): void
    {
        foreach ([['dr.ngo@pharmacie.cm', 'mauvais'], ['inconnu@x.cm', 'Motdepasse#2026']] as [$e, $p]) {
            try {
                $this->auth->login($e, $p);
                $this->fail('attendu : échec');
            } catch (AuthException $ex) {
                $this->assertSame('invalid_credentials', $ex->errorCode);
            }
        }
    }

    public function testAccountLocksAfterFiveFailuresThenUnlocksAfterDelay(): void
    {
        for ($i = 0; $i < 5; $i++) {
            try {
                $this->auth->login('dr.ngo@pharmacie.cm', 'x');
            } catch (AuthException) {
            }
        }
        try {
            $this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026');
            $this->fail('compte censé être verrouillé');
        } catch (AuthException $e) {
            $this->assertSame('account_locked', $e->errorCode);
        }
        $this->clock->advance('+16 minutes');
        $this->assertNotEmpty($this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026')['access']);
    }

    public function testAccessTokenExpiresAfter15Minutes(): void
    {
        $jwt = new JwtService(self::SECRET, $this->clock);
        $token = $this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026')['access'];
        $this->clock->advance('+16 minutes');
        $this->expectException(AuthException::class);
        $jwt->verify($token);
    }

    public function testRefreshTokenRotatesAndReuseRevokesTheFamily(): void
    {
        $first = $this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026')['refresh'];
        $second = $this->auth->refresh($first)['refresh'];
        $this->assertNotSame($first, $second);
        try {
            $this->auth->refresh($first);
            $this->fail('rejeu attendu en échec');
        } catch (AuthException $e) {
            $this->assertSame('invalid_refresh_token', $e->errorCode);
        }
        $this->expectException(AuthException::class); // la famille entière est révoquée
        $this->auth->refresh($second);
    }

    public function testRefreshTokenExpires(): void
    {
        $t = $this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026')['refresh'];
        $this->clock->advance('+31 days');
        $this->expectException(AuthException::class);
        $this->auth->refresh($t);
    }

    public function testTokensAreNotStoredInClear(): void
    {
        $t = $this->auth->login('dr.ngo@pharmacie.cm', 'Motdepasse#2026')['refresh'];
        $this->assertNull($this->db->one('SELECT id FROM refresh_tokens WHERE token_hash = ?', [$t]));
        $this->assertNotNull($this->db->one('SELECT id FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $t)]));
    }
}
