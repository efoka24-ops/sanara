<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Clock;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;

/** T054 : nouveau lien d'activation. Ne révèle jamais si un compte existe ; un nouveau lien invalide les précédents. */
final class ActivationResendService
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly ActivationService $activation, private readonly JobQueue $jobs, private readonly string $appUrl)
    {
    }

    public function resend(string $email): void
    {
        $u = $this->db->one("SELECT id, email, locale FROM users WHERE email = ? AND status = 'pending_activation' AND deleted_at IS NULL", [strtolower(trim($email))]);
        if ($u === null) {
            return;
        }
        $this->db->execute('UPDATE activation_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$this->clock->stamp(), $u['id']]);
        $token = $this->activation->issueToken((string) $u['id'], ReviewService::ACTIVATION_HOURS);
        $this->jobs->push('mail', [
            'template' => 'activation_resend', 'to' => $u['email'], 'locale' => $u['locale'] === 'en' ? 'en' : 'fr',
            'vars' => ['duree_lien' => ReviewService::ACTIVATION_HOURS . ($u['locale'] === 'en' ? ' hours' : ' heures'), 'lien_activation' => rtrim($this->appUrl, '/') . '/activer/' . $token],
        ]);
    }
}
