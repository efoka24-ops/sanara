<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Email;

interface Mailer
{
    public function send(string $to, string $subject, string $html, string $text): void;
}
