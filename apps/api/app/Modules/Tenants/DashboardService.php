<?php
declare(strict_types=1);

namespace Sanara\Modules\Tenants;

use DateTimeImmutable;
use DateTimeZone;
use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Modules\Requests\PresenceService;
use Sanara\Modules\Sales\CashService;
use Sanara\Modules\Stock\StockService;

/**
 * Tableau de bord (maquette H1) composé selon les droits de la personne connectée : chiffre d'affaires
 * pour le titulaire et le comptable, caisse et ventes du caissier, demandes pour les pharmaciens,
 * stock pour le magasinier. Jours comptés à l'heure du pays.
 */
final class DashboardService
{
    private const DAYS = 12;
    private const EXPIRY_DAYS = 90;

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly TenantContext $ctx,
        private readonly Clock $clock,
        private readonly Authorizer $authz,
        private readonly CashService $cash,
        private readonly StockService $stock,
        private readonly PresenceService $presence,
    ) {
    }

    /** @return array<string, mixed> */
    public function summary(string $userId): array
    {
        $can = fn (string $p) => $this->authz->can($userId, $p);
        $user = $this->db->one('SELECT email, role_code FROM users WHERE id = ?', [$userId]) ?? [];
        $out = ['user' => ['email' => $user['email'] ?? '', 'role' => $user['role_code'] ?? ''], 'role' => $user['role_code'] ?? ''];
        if ($can('reports.read') || $can('finance.read')) {
            $out['sales'] = $this->sales();
        }
        if ($can('sales.create')) {
            $out['till'] = $this->till($userId);
        }
        if ($can('pickup.validate')) {
            $out['pickups'] = ['ready' => count($this->tdb->select('patient_requests', ['status' => 'ready'])), 'accepted' => count($this->tdb->select('patient_requests', ['status' => 'accepted']))];
        }
        if ($can('requests.process')) {
            $out['requests'] = $this->requests();
            $me = array_values(array_filter($this->presence->team(), static fn (array $m) => $m['user_id'] === $userId))[0] ?? null;
            $out['presence'] = ['online' => $me['online'] ?? false, 'anyone' => $this->presence->anyoneOnline()];
        }
        if ($can('stock.read')) {
            $out['stock'] = $this->stockSummary();
        }
        if ($can('finance.read') || ($can('customers.write') && $can('reports.read'))) {
            $out['credit'] = $this->credit();
        }
        return $out;
    }

    /** @return array{today: array<string, int>, days: list<array{date: string, total: int}>} */
    private function sales(): array
    {
        $zone = $this->zone();
        $today = $this->clock->now()->setTimezone($zone)->setTime(0, 0);
        $from = $today->modify('-' . (self::DAYS - 1) . ' days');
        $rows = $this->db->all(
            "SELECT total, created_at FROM sales WHERE tenant_id = ? AND status <> 'cancelled' AND created_at >= ?",
            [$this->ctx->require(), $this->clock->stamp($from)],
        );
        $days = [];
        for ($d = $from; $d <= $today; $d = $d->modify('+1 day')) {
            $days[$d->format('Y-m-d')] = 0;
        }
        $tickets = 0;
        $todayKey = $today->format('Y-m-d');
        foreach ($rows as $r) {
            $day = (new DateTimeImmutable((string) $r['created_at'], new DateTimeZone('UTC')))->setTimezone($zone)->format('Y-m-d');
            if (isset($days[$day])) {
                $days[$day] += (int) $r['total'];
                $tickets += $day === $todayKey ? 1 : 0;
            }
        }
        $total = $days[$todayKey];
        return [
            'today' => ['total' => $total, 'tickets' => $tickets, 'average' => $tickets > 0 ? intdiv($total, $tickets) : 0, 'yesterday' => $days[$today->modify('-1 day')->format('Y-m-d')] ?? 0],
            'days' => array_map(static fn (string $date, int $sum) => ['date' => $date, 'total' => $sum], array_keys($days), array_values($days)),
        ];
    }

    /** Caisse de la personne connectée et ses ventes sur cette caisse. @return array<string, mixed> */
    private function till(string $userId): array
    {
        $session = $this->cash->current($userId);
        if ($session === null) {
            return ['open' => false];
        }
        $mine = $this->tdb->select('sales', ['session_id' => $session['id']]);
        $totals = $this->cash->totals((string) $session['id']);
        return [
            'open' => true, 'station' => $session['station'], 'opened_at' => $session['opened_at'], 'stale' => $session['stale'],
            'opening_float' => (int) $session['opening_float'], 'sales' => count($mine),
            'total' => array_sum(array_map(static fn (array $s) => (int) $s['total'], $mine)), 'expected_cash' => (int) $totals['expected_cash'],
        ];
    }

    /** @return array{open: int, urgent: int, latest: list<array<string, mixed>>} */
    private function requests(): array
    {
        $open = array_values(array_filter(
            $this->tdb->select('patient_requests', [], 'created_at'),
            static fn (array $r) => in_array($r['status'], ['received', 'processing', 'photo_needed'], true),
        ));
        usort($open, static fn (array $a, array $b) => [(int) $b['urgent'], $a['created_at']] <=> [(int) $a['urgent'], $b['created_at']]);
        return [
            'open' => count($open),
            'urgent' => count(array_filter($open, static fn (array $r) => (int) $r['urgent'] === 1)),
            'latest' => array_map(static fn (array $r) => [
                'id' => $r['id'], 'number' => $r['number'], 'first_name' => $r['first_name'], 'status' => $r['status'],
                'urgent' => (int) $r['urgent'] === 1, 'created_at' => $r['created_at'],
            ], array_slice($open, 0, 5)),
        ];
    }

    /** @return array<string, mixed> */
    private function stockSummary(): array
    {
        $limit = $this->clock->now()->modify('+' . self::EXPIRY_DAYS . ' days')->format('Y-m-d');
        $out = 0;
        $low = 0;
        $expiring = 0;
        $watch = [];
        foreach ($this->stock->overview() as $p) {
            if ($p['available'] <= 0) {
                $out++;
                $watch[] = ['name' => $p['name'], 'reason' => 'out', 'qty' => $p['available']];
            } elseif ($p['min_stock'] > 0 && $p['available'] < $p['min_stock']) {
                $low++;
                $watch[] = ['name' => $p['name'], 'reason' => 'low', 'qty' => $p['available']];
            }
            if ($p['next_expiry'] !== null && $p['next_expiry'] <= $limit) {
                $expiring++;
                $watch[] = ['name' => $p['name'], 'reason' => 'expiring', 'qty' => $p['available'], 'expiry' => $p['next_expiry']];
            }
        }
        return ['out' => $out, 'low' => $low, 'expiring' => $expiring, 'watch' => array_slice($watch, 0, 6)];
    }

    /** @return array{outstanding: int, accounts: int} */
    private function credit(): array
    {
        $balances = $this->db->all(
            'SELECT customer_id, SUM(amount) AS balance FROM customer_entries WHERE tenant_id = ? GROUP BY customer_id',
            [$this->ctx->require()],
        );
        $due = array_filter($balances, static fn (array $b) => (int) $b['balance'] > 0);
        return ['outstanding' => array_sum(array_map(static fn (array $b) => (int) $b['balance'], $due)), 'accounts' => count($due)];
    }

    private function zone(): DateTimeZone
    {
        $c = $this->db->one('SELECT c.timezone FROM tenants t JOIN countries c ON c.code = t.country_code WHERE t.id = ?', [$this->ctx->require()]);
        return new DateTimeZone((string) ($c['timezone'] ?? 'Africa/Douala'));
    }
}
