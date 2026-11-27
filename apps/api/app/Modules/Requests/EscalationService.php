<?php
declare(strict_types=1);

namespace Sanara\Modules\Requests;

use Sanara\Common\Clock;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Logging\Log;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Applications\DutyCalendar;
use Sanara\Modules\Applications\OpeningStatus;
use Sanara\Modules\Notifications\Sms\SmsProvider;

/**
 * T191, T296 : chaîne d'escalade d'une urgence. Étape 0 : e-mail aux pharmaciens. Puis, à chaque délai
 * (3 min par défaut, réglable), SMS au titulaire, SMS aux adjoints, et enfin repli honnête au patient.
 * La chaîne s'arrête dès qu'un pharmacien prend la demande. Le cron l'avance chaque minute ; la page de
 * suivi du patient l'avance aussi, pour ne pas dépendre du cron seul.
 *
 * États : alerted (e-mail envoyé), texted (SMS envoyés), handled, exhausted, closed (hors horaires).
 */
final class EscalationService
{
    private const DEFAULT_MINUTES = 3;
    /** Étapes après l'e-mail : titulaire, adjoints, fin. */
    private const STEPS = [1 => 'owner', 2 => 'pharmacist', 3 => 'end'];

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly TenantContext $ctx,
        private readonly Clock $clock,
        private readonly JobQueue $jobs,
        private readonly SmsProvider $sms,
        private readonly RequestLedger $ledger,
    ) {
    }

    /** Démarre la chaîne pour une demande devenue urgente (pharmacie courante). */
    public function start(string $requestId): void
    {
        $r = $this->tdb->select('patient_requests', ['id' => $requestId], null, 1)[0] ?? null;
        if ($r === null || $r['escalation_state'] !== null) {
            return;
        }
        if (!$this->reachable() && !$this->setting('urgent_outside_hours', false)) {
            $this->ledger->move($requestId, ['escalation_state' => 'closed']);
            $this->ledger->event($requestId, 'escalation_closed');
            return;
        }
        $this->notifyByMail((string) $r['number'], 'request_urgent');
        $this->record($requestId, 0, 'mail', null, 'queued');
        $this->ledger->move($requestId, ['escalation_state' => 'alerted', 'escalation_step' => 1, 'escalation_next_at' => $this->next()]);
        $this->ledger->event($requestId, 'escalation_mail');
    }

    /** Toutes les pharmacies : étapes échues. @return int étapes exécutées */
    public function run(): int
    {
        $due = $this->db->all(
            "SELECT id, tenant_id FROM patient_requests WHERE escalation_state IN ('alerted', 'texted') AND escalation_next_at <= ?",
            [$this->clock->stamp()],
        );
        $done = 0;
        $previous = $this->ctx->has() ? $this->ctx->require() : null;
        foreach ($due as $row) {
            $this->ctx->set((string) $row['tenant_id']);
            try {
                $done += $this->advance((string) $row['id']) ? 1 : 0;
            } catch (\Throwable $e) {
                Log::write('error', 'escalade : ' . $e->getMessage(), ['request' => $row['id']]);
            }
        }
        $previous === null ? $this->ctx->clear() : $this->ctx->set($previous);
        return $done;
    }

    /** Avance la chaîne d'une demande de la pharmacie courante, si une étape est échue. */
    public function advance(string $requestId): bool
    {
        $r = $this->tdb->select('patient_requests', ['id' => $requestId], null, 1)[0] ?? null;
        if ($r === null || !in_array($r['escalation_state'], ['alerted', 'texted'], true) || $r['escalation_next_at'] > $this->clock->stamp()) {
            return false;
        }
        $step = (int) $r['escalation_step'];
        $taken = !in_array($r['status'], ['received', 'photo_needed'], true);
        $last = $step >= 3 && !$taken;
        $set = $taken
            ? ['escalation_state' => 'handled', 'escalation_next_at' => null]
            : ($last
                ? ['escalation_state' => 'exhausted', 'escalation_next_at' => null]
                : ['escalation_state' => 'texted', 'escalation_step' => $step + 1, 'escalation_next_at' => $this->next()]);
        // Une seule exécution de l'étape, même si le cron et la page de suivi passent en même temps.
        $won = $this->tdb->update('patient_requests', $set + ['updated_at' => $this->clock->stamp()], ['id' => $requestId, 'escalation_step' => $step, 'escalation_state' => $r['escalation_state']]);
        if ($won !== 1) {
            return false;
        }
        if ($taken) {
            $this->ledger->event($requestId, 'escalation_handled');
        } elseif ($last) {
            $this->ledger->event($requestId, 'escalation_exhausted');
        } else {
            $this->text($requestId, $step, self::STEPS[$step], (string) $r['number']);
        }
        return true;
    }

    /** Pris en charge : la chaîne est close sans attendre le prochain passage. */
    public function stopIfTaken(string $requestId): void
    {
        $this->tdb->update('patient_requests', ['escalation_state' => 'handled', 'escalation_next_at' => null], ['id' => $requestId, 'escalation_state' => 'alerted']);
        $this->tdb->update('patient_requests', ['escalation_state' => 'handled', 'escalation_next_at' => null], ['id' => $requestId, 'escalation_state' => 'texted']);
    }

    /** E-mail aux pharmaciens, sans aucune donnée du patient. */
    public function notifyByMail(string $number, string $template): void
    {
        foreach ($this->staff(['owner', 'pharmacist']) as $u) {
            $this->jobs->push('mail', ['template' => $template, 'to' => $u['email'], 'locale' => $u['locale'] ?? 'fr', 'vars' => ['numero' => $number, 'pharmacie' => $u['name']]]);
        }
    }

    private function text(string $requestId, int $step, string $role, string $number): void
    {
        foreach ($this->staff([$role]) as $u) {
            if (trim((string) $u['phone']) === '') {
                $this->record($requestId, $step, 'sms', (string) $u['id'], 'no_phone');
                continue;
            }
            try {
                $this->sms->send((string) $u['phone'], "Sanara : demande urgente $number pour {$u['name']}. Ouvrez Sanara pour la prendre en charge.");
                $this->record($requestId, $step, 'sms', (string) $u['id'], 'sent');
            } catch (\Throwable $e) {
                Log::write('error', 'SMS d’escalade : ' . $e->getMessage(), ['request' => $requestId]);
                $this->record($requestId, $step, 'sms', (string) $u['id'], 'failed');
            }
        }
        $this->ledger->event($requestId, 'escalation_sms');
    }

    /** @param list<string> $roles @return list<array<string, mixed>> */
    private function staff(array $roles): array
    {
        $in = implode(', ', array_fill(0, count($roles), '?'));
        return $this->db->all(
            "SELECT u.id, u.email, u.phone, u.locale, t.name FROM users u JOIN tenants t ON t.id = u.tenant_id WHERE u.tenant_id = ? AND u.role_code IN ($in) AND u.status = 'active' ORDER BY u.email",
            [$this->ctx->require(), ...$roles],
        );
    }

    private function record(string $requestId, int $step, string $channel, ?string $userId, string $result): void
    {
        $this->tdb->insert('escalation_attempts', [
            'id' => Ulid::generate(), 'request_id' => $requestId, 'step' => $step, 'channel' => $channel,
            'user_id' => $userId, 'result' => $result, 'created_at' => $this->clock->stamp(),
        ]);
    }

    /** Ouverte ou de garde en ce moment. */
    private function reachable(): bool
    {
        $p = $this->db->one(
            'SELECT p.hours, p.duties, c.timezone FROM public_pharmacies p LEFT JOIN countries c ON c.code = p.country_code WHERE p.tenant_id = ?',
            [$this->ctx->require()],
        );
        if ($p === null || $p['hours'] === null) {
            return true; // horaires inconnus : on alerte plutôt que de laisser le patient sans réponse
        }
        $zone = (string) ($p['timezone'] ?? 'Africa/Douala');
        $now = $this->clock->now();
        $open = OpeningStatus::at(json_decode((string) $p['hours'], true), $now, $zone)['open'];
        $duty = DutyCalendar::next(json_decode((string) $p['duties'], true) ?? [], $now, $zone);
        return $open === true || ($duty['now'] ?? false);
    }

    private function next(): string
    {
        $minutes = max(1, min(30, (int) $this->setting('urgent_escalation_minutes', self::DEFAULT_MINUTES)));
        return $this->clock->stamp($this->clock->now()->modify("+$minutes minutes"));
    }

    private function setting(string $key, mixed $default): mixed
    {
        $r = $this->tdb->select('tenant_settings', ['setting_key' => $key], null, 1)[0] ?? null;
        return $r === null ? $default : json_decode((string) $r['setting_value'], true);
    }
}
