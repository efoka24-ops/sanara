<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Email;

/** Développement et tests de bout en bout : un fichier JSON par message (équivalent de MailHog). Jamais en production. */
final class FileMailer implements Mailer
{
    public function __construct(private readonly string $dir)
    {
    }

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        file_put_contents(
            $this->dir . '/' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.json',
            json_encode(['to' => $to, 'subject' => $subject, 'text' => $text, 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }
}
