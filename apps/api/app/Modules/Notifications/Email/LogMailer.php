<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Email;

final class LogMailer implements Mailer
{
    public function send(string $to, string $subject, string $html, string $text): void
    {
        error_log('[sanara][mail] "' . $subject . '" vers ' . preg_replace('/^.{2}/', '**', $to));
    }
}
