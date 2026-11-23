<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Sms;

/** Développement : écrit dans le journal PHP, n'envoie rien. */
final class ConsoleSmsProvider implements SmsProvider
{
    public function send(string $phone, string $message): string
    {
        error_log('[sanara][sms] vers ' . substr($phone, 0, 3) . '***' . substr($phone, -2) . ' : ' . mb_strlen($message) . ' caractères');
        return 'console-' . bin2hex(random_bytes(4));
    }
}
