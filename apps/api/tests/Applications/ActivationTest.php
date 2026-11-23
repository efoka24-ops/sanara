<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Modules\Applications\ActivationService;
use Sanara\Tests\Support\TestDb;

final class ActivationTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private ActivationService $svc;
    private string $userId;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $this->userId = (new UserRepository($this->db, $this->clock))->create(['email' => 'o@x.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'status' => 'pending_activation', 'password_hash' => '!x']);
        $this->db->insert('activation_tokens', ['id' => 'A1', 'user_id' => $this->userId, 'token_hash' => hash('sha256', 'tok-ok'), 'expires_at' => '2026-11-26 10:00:00.000', 'created_at' => '2026-11-24 10:00:00.000']);
        $this->svc = new ActivationService($this->db, $this->clock, new PasswordHasher());
    }

    public function testValidTokenSetsPasswordActivatesAccountAndIsSingleUse(): void
    {
        $this->svc->activate('tok-ok', 'Motdepasse#2026');
        $u = $this->db->one('SELECT * FROM users');
        $this->assertSame('active', $u['status']);
        $this->assertStringStartsWith('$argon2id$', $u['password_hash']);
        $this->expectException(ApiException::class);
        $this->svc->activate('tok-ok', 'Motdepasse#2026');
    }

    public function testExpiredTokenIsRejected(): void
    {
        $this->clock->advance('+49 hours');
        try {
            $this->svc->activate('tok-ok', 'Motdepasse#2026');
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('invalid_token', $e->errorCode);
        }
        $this->assertSame('pending_activation', $this->db->one('SELECT status FROM users')['status']);
    }

    public function testWeakPasswordAndUnknownTokenAreRejected(): void
    {
        foreach ([['tok-ok', 'faible'], ['inconnu', 'Motdepasse#2026']] as [$t, $p]) {
            try {
                $this->svc->activate($t, $p);
                $this->fail();
            } catch (ApiException $e) {
                $this->assertContains($e->errorCode, ['validation_failed', 'invalid_token']);
            }
        }
        $this->assertNull($this->db->one('SELECT used_at FROM activation_tokens')['used_at']);
    }

    public function testCheckTokenTellsIfStillUsable(): void
    {
        $this->assertTrue($this->svc->isUsable('tok-ok'));
        $this->assertFalse($this->svc->isUsable('nope'));
    }
}
