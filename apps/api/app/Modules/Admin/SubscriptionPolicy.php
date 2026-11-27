<?php
declare(strict_types=1);

namespace Sanara\Modules\Admin;

use Sanara\Common\Clock;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/**
 * T252, T255 : état de l'abonnement d'une pharmacie. Essai puis période payée ; après la fin, 7 jours
 * de grâce où tout fonctionne, puis lecture seule. Sans abonnement (pilote, démonstration) : aucune
 * restriction.
 */
final class SubscriptionPolicy
{
    public const TRIAL_DAYS = 30;
    public const PERIOD_DAYS = 30;
    public const GRACE_DAYS = 7;
    private const REMIND_DAYS = 7;

    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly ?JobQueue $jobs = null)
    {
    }

    /** @return array{state: string, plan: ?string, stations: ?int, ends_at: ?string, days_left: ?int} */
    public function state(string $tenantId): array
    {
        $s = $this->db->one('SELECT s.status, s.stations, s.trial_ends_at, s.period_end, p.name AS plan FROM subscriptions s JOIN plans p ON p.id = s.plan_id WHERE s.pharmacy_id = ?', [$tenantId]);
        if ($s === null) {
            return ['state' => 'none', 'plan' => null, 'stations' => null, 'ends_at' => null, 'days_left' => null];
        }
        $end = $s['status'] === 'trial' ? $s['trial_ends_at'] : $s['period_end'];
        $base = ['plan' => (string) $s['plan'], 'stations' => (int) $s['stations'], 'ends_at' => $end];
        if ($s['status'] === 'cancelled') {
            return ['state' => 'expired', 'days_left' => 0] + $base;
        }
        if ($end === null) {
            return ['state' => (string) $s['status'], 'days_left' => null] + $base;
        }
        $now = $this->clock->now();
        $endsAt = new \DateTimeImmutable((string) $end, new \DateTimeZone('UTC'));
        $left = (int) floor(($endsAt->getTimestamp() - $now->getTimestamp()) / 86400);
        if ($now < $endsAt) {
            return ['state' => (string) $s['status'], 'days_left' => $left] + $base;
        }
        $graceEnd = $endsAt->modify('+' . self::GRACE_DAYS . ' days');
        return ['state' => $now < $graceEnd ? 'grace' : 'expired', 'days_left' => $left] + $base;
    }

    public function readOnly(string $tenantId): bool
    {
        return $this->state($tenantId)['state'] === 'expired';
    }

    /** Démarre l'essai à la validation de la pharmacie. */
    public function startTrial(string $tenantId, string $planCode, int $stations): void
    {
        $plan = $this->db->one('SELECT id, max_stations FROM plans WHERE code = ? AND active = 1', [$planCode])
            ?? $this->db->one('SELECT id, max_stations FROM plans WHERE active = 1 ORDER BY price_month LIMIT 1');
        if ($plan === null) {
            return; // aucune formule définie : pharmacie sans abonnement
        }
        $now = $this->clock->stamp();
        $this->db->insert('subscriptions', [
            'id' => Ulid::generate(), 'pharmacy_id' => $tenantId, 'plan_id' => $plan['id'], 'status' => 'trial',
            'stations' => max(1, min($stations, (int) $plan['max_stations'])),
            'trial_ends_at' => $this->clock->stamp($this->clock->now()->modify('+' . self::TRIAL_DAYS . ' days')),
            'period_end' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Paiement reçu : un mois de plus, à partir d'aujourd'hui ou de la fin de la période en cours. */
    public function extend(string $tenantId): void
    {
        $s = $this->db->one('SELECT status, trial_ends_at, period_end FROM subscriptions WHERE pharmacy_id = ?', [$tenantId]);
        if ($s === null) {
            return;
        }
        $now = $this->clock->now();
        $current = $s['status'] === 'active' && $s['period_end'] !== null ? new \DateTimeImmutable((string) $s['period_end'], new \DateTimeZone('UTC')) : $now;
        $from = $current > $now ? $current : $now;
        $this->db->execute(
            "UPDATE subscriptions SET status = 'active', trial_ends_at = NULL, period_end = ?, updated_at = ? WHERE pharmacy_id = ?",
            [$this->clock->stamp($from->modify('+' . self::PERIOD_DAYS . ' days')), $this->clock->stamp(), $tenantId],
        );
    }

    /** Relances : une fois sept jours avant la fin, une fois au passage en lecture seule. @return int e-mails préparés */
    public function remind(): int
    {
        $sent = 0;
        foreach ($this->db->all('SELECT pharmacy_id FROM subscriptions') as $row) {
            $tenant = (string) $row['pharmacy_id'];
            $state = $this->state($tenant);
            $kind = match (true) {
                $state['state'] === 'expired' => 'subscription_expired',
                in_array($state['state'], ['trial', 'active'], true) && $state['days_left'] !== null && $state['days_left'] <= self::REMIND_DAYS => 'subscription_reminder',
                default => null,
            };
            if ($kind === null || $state['ends_at'] === null || !$this->once($tenant, $kind, (string) $state['ends_at'])) {
                continue;
            }
            $owner = $this->db->one("SELECT u.email, u.locale, t.name FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.tenant_id = ? AND u.role_code = 'owner' ORDER BY u.created_at LIMIT 1", [$tenant]);
            if ($owner !== null && $this->jobs !== null) {
                $this->jobs->push('mail', ['template' => $kind, 'to' => $owner['email'], 'locale' => $owner['locale'] ?? 'fr', 'vars' => ['pharmacie' => $owner['name'], 'date' => substr((string) $state['ends_at'], 0, 10)]]);
                $sent++;
            }
        }
        return $sent;
    }

    private function once(string $tenant, string $kind, string $period): bool
    {
        try {
            $this->db->insert('subscription_notices', ['id' => Ulid::generate(), 'pharmacy_id' => $tenant, 'kind' => $kind, 'period_end' => $period, 'created_at' => $this->clock->stamp()]);
            return true;
        } catch (\PDOException) {
            return false; // déjà envoyée pour cette échéance
        }
    }
}
