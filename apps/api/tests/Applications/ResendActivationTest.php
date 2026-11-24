<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\RateLimit\RateLimiter;
use Sanara\Modules\Applications\ActivationResendService;
use Sanara\Modules\Applications\ActivationService;
use Sanara\Tests\Support\TestDb;

final class ResendActivationTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private JobQueue $jobs;
    private ActivationResendService $svc;
    private ActivationService $act;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $users->create(['email' => 'o@x.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'status' => 'pending_activation', 'password_hash' => '!x']);
        $users->create(['email' => 'actif@x.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'status' => 'active', 'password_hash' => 'x']);
        $this->jobs = new JobQueue($this->db, $this->clock);
        $this->act = new ActivationService($this->db, $this->clock, new PasswordHasher());
        $this->svc = new ActivationResendService($this->db, $this->clock, $this->act, $this->jobs, 'https://app.example');
    }

    public function testPendingUserGetsNewTokenAndOldOnesStopWorking(): void
    {
        $uid = (string) $this->db->one("SELECT id FROM users WHERE email = 'o@x.cm'")['id'];
        $old = $this->act->issueToken($uid, 48);
        $this->clock->advance('+49 hours');
        $this->assertFalse($this->act->isUsable($old));
        $this->svc->resend('o@x.cm');
        $mail = $this->jobs->reserve('mail');
        $this->assertSame('activation_resend', $mail['payload']['template']);
        preg_match('#/activer/([\w\-]+)#', $mail['payload']['vars']['lien_activation'], $m);
        $this->assertTrue($this->act->isUsable($m[1]));
        $this->svc->resend('o@x.cm');
        $this->assertFalse($this->act->isUsable($m[1]), 'un nouveau lien invalide le précédent');
    }

    public function testActiveOrUnknownAccountsGetNothingAndNoLeak(): void
    {
        $this->svc->resend('actif@x.cm');
        $this->svc->resend('inconnu@x.cm');
        $this->assertNull($this->jobs->reserve('mail'));
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM activation_tokens')['n']);
    }
}
