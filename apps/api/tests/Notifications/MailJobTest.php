<?php
declare(strict_types=1);

namespace Sanara\Tests\Notifications;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Modules\Notifications\Email\EmailTemplates;
use Sanara\Modules\Notifications\Email\Mailer;
use Sanara\Modules\Notifications\MailJobHandler;
use Sanara\Tests\Support\TestDb;

final class MailJobTest extends TestCase
{
    public function testDrainSendsRenderedMailAndRetriesOnFailure(): void
    {
        $db = TestDb::create();
        $clock = Clock::fixed('2026-11-24T10:00:00Z');
        $jobs = new JobQueue($db, $clock);
        $jobs->push('mail', ['template' => 'application_received', 'to' => 'a@b.cm', 'locale' => 'fr', 'vars' => ['responsable' => 'A', 'numero' => 'PH-2026-0001', 'code_suivi' => 'abc']]);
        $mailer = new class implements Mailer {
            public array $sent = [];
            public bool $fail = true;
            public function send(string $to, string $subject, string $html, string $text): void
            {
                if ($this->fail) {
                    throw new \RuntimeException('smtp down');
                }
                $this->sent[] = [$to, $subject];
            }
        };
        $h = new MailJobHandler($jobs, $mailer, new EmailTemplates());
        $this->assertSame(0, $h->drain());
        $mailer->fail = false;
        $this->assertSame(0, $h->drain(), 'replanifié avec délai');
        $clock->advance('+6 minutes');
        $this->assertSame(1, $h->drain());
        $this->assertSame([['a@b.cm', 'Votre demande d\'accès Sanara est bien reçue']], $mailer->sent);
    }
}
