<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Sms;

/** Interface remplaçable (constitution VIII). Retourne l'identifiant du SMS chez le fournisseur. */
interface SmsProvider
{
    public function send(string $phone, string $message): string;
}
