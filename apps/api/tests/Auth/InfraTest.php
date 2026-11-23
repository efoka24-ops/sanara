<?php
declare(strict_types=1);

namespace Sanara\Tests\Auth;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Audit\AuditLogger;
use Sanara\Common\Clock;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\RateLimit\RateLimiter;
use Sanara\Tests\Support\TestDb;

final class InfraTest extends TestCase
{
    private Db $db;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
    }

    public function testAuditIsAppendOnlyAndRecordsBeforeAfter(): void
    {
        $audit = new AuditLogger($this->db, $this->clock);
        $audit->record('sale.cancel', ['tenant_id' => 'T1', 'user_id' => 'U1', 'device_id' => 'D1', 'entity' => 'sale', 'entity_id' => 'S1', 'before' => ['status' => 'paid'], 'after' => ['status' => 'cancelled'], 'ip' => '41.0.0.1']);
        $row = $this->db->one('SELECT * FROM audit_logs');
        $this->assertSame('sale.cancel', $row['action']);
        $this->assertSame(['status' => 'paid'], json_decode($row['before_json'], true));
        foreach (get_class_methods($audit) as $m) {
            $this->assertDoesNotMatchRegularExpression('/^(update|delete|remove|purge|edit)/i', $m, "AuditLogger ne doit pas exposer $m");
        }
    }

    public function testAuditNeverStoresPasswordsOrTokens(): void
    {
        $audit = new AuditLogger($this->db, $this->clock);
        $audit->record('user.login', ['after' => ['password' => 'secret', 'refresh_token' => 'abc', 'email' => 'a@b.cm']]);
        $after = json_decode($this->db->one('SELECT after_json FROM audit_logs')['after_json'], true);
        $this->assertSame('[masqué]', $after['password']);
        $this->assertSame('[masqué]', $after['refresh_token']);
        $this->assertSame('a@b.cm', $after['email']);
    }

    public function testJobIsReservedOnceAndAcked(): void
    {
        $q = new JobQueue($this->db, $this->clock);
        $q->push('mail', ['to' => 'a@b.cm']);
        $job = $q->reserve('mail');
        $this->assertSame(['to' => 'a@b.cm'], $job['payload']);
        $this->assertNull($q->reserve('mail'), 'un job réservé ne peut pas être pris deux fois');
        $q->ack($job['id']);
        $this->assertSame('done', $this->db->one('SELECT status FROM jobs')['status']);
    }

    public function testCrashedWorkerJobIsRecoveredAfterLockExpiry(): void
    {
        $q = new JobQueue($this->db, $this->clock, lockSeconds: 60);
        $q->push('mail', ['n' => 1]);
        $q->reserve('mail'); // le worker « plante » sans ack
        $this->assertNull($q->reserve('mail'));
        $this->clock->advance('+61 seconds');
        $again = $q->reserve('mail');
        $this->assertNotNull($again);
        $this->assertSame(2, $again['attempts']);
    }

    public function testFailedJobIsRetriedWithBackoffThenMarkedFailed(): void
    {
        $q = new JobQueue($this->db, $this->clock, maxAttempts: 2);
        $q->push('mail', []);
        $j = $q->reserve('mail');
        $q->fail($j['id'], 'smtp down');
        $this->assertNull($q->reserve('mail'), 'délai avant nouvelle tentative');
        $this->clock->advance('+10 minutes');
        $j = $q->reserve('mail');
        $q->fail($j['id'], 'smtp down');
        $this->assertSame('failed', $this->db->one('SELECT status FROM jobs')['status']);
    }

    public function testDelayedJobIsNotAvailableBeforeItsTime(): void
    {
        $q = new JobQueue($this->db, $this->clock);
        $q->push('escalation', [], delaySeconds: 180);
        $this->assertNull($q->reserve('escalation'));
        $this->clock->advance('+181 seconds');
        $this->assertNotNull($q->reserve('escalation'));
    }

    public function testRateLimiterBlocksAfterLimitAndResetsNextWindow(): void
    {
        $rl = new RateLimiter($this->db, $this->clock);
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($rl->hit('login:1.2.3.4', 3, 60)['allowed']);
        }
        $blocked = $rl->hit('login:1.2.3.4', 3, 60);
        $this->assertFalse($blocked['allowed']);
        $this->assertGreaterThan(0, $blocked['retryAfter']);
        $this->assertTrue($rl->hit('login:autre', 3, 60)['allowed']);
        $this->clock->advance('+61 seconds');
        $this->assertTrue($rl->hit('login:1.2.3.4', 3, 60)['allowed']);
    }
}
