<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications;

use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Logging\Log;
use Sanara\Modules\Notifications\Email\EmailTemplates;
use Sanara\Modules\Notifications\Email\Mailer;

/** Traite la file « mail » : rend le gabarit puis envoie. En cas d'échec, le job est replanifié (JobQueue::fail). */
final class MailJobHandler
{
    public function __construct(private readonly JobQueue $jobs, private readonly Mailer $mailer, private readonly EmailTemplates $templates)
    {
    }

    /** @return int nombre de messages envoyés */
    public function drain(int $max = 20): int
    {
        $sent = 0;
        while ($sent < $max && ($job = $this->jobs->reserve('mail')) !== null) {
            try {
                $p = $job['payload'];
                $m = $this->templates->render((string) $p['template'], (string) ($p['locale'] ?? 'fr'), (array) $p['vars']);
                $this->mailer->send((string) $p['to'], $m['subject'], $m['html'], $m['text']);
                $this->jobs->ack($job['id']);
                $sent++;
            } catch (\Throwable $e) {
                Log::write('error', 'mail job failed: ' . $e->getMessage(), ['job' => $job['id']]);
                $this->jobs->fail($job['id'], $e->getMessage());
            }
        }
        return $sent;
    }
}
