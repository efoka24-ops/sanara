<?php
declare(strict_types=1);

namespace Sanara\Modules\Sales;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/** US11 : ouverture de caisse avec fond, clôture avec comptage par coupures, écart motivé, rapport Z. */
final class CashService
{
    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly Clock $clock, private readonly Sequences $seq)
    {
    }

    public static function station(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $s) ?? '');
        return $s === '' ? 'CAISSE1' : substr($s, 0, 8);
    }

    public function open(string $userId, string $station, int $float): string
    {
        if ($float < 0) {
            throw new ApiException('validation_failed', 422, ['fields' => ['opening_float' => 'invalid']]);
        }
        if ($this->current($userId) !== null) {
            throw new ApiException('cash_session_open', 409);
        }
        $id = Ulid::generate();
        $this->tdb->insert('cash_sessions', [
            'id' => $id, 'station' => self::station($station), 'opened_by' => $userId, 'opened_at' => $this->clock->stamp(), 'opening_float' => $float, 'status' => 'open',
            'closed_by' => null, 'closed_at' => null, 'expected_cash' => null, 'counted_cash' => null, 'gap' => null, 'gap_reason' => null, 'z_number' => null,
        ]);
        return $id;
    }

    /** @return array<string, mixed>|null session ouverte de l'utilisateur, avec `stale` si elle date d'un jour précédent */
    public function current(string $userId): ?array
    {
        $s = $this->tdb->select('cash_sessions', ['opened_by' => $userId, 'status' => 'open'], null, 1)[0] ?? null;
        if ($s === null) {
            return null;
        }
        $s['stale'] = substr((string) $s['opened_at'], 0, 10) < substr($this->clock->stamp(), 0, 10);
        return $s;
    }

    /** @return array<string, mixed> */
    public function session(string $id): array
    {
        return $this->tdb->select('cash_sessions', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
    }

    /** @return list<array<string, mixed>> */
    public function sessions(): array
    {
        return array_reverse($this->tdb->select('cash_sessions', [], 'opened_at', 100));
    }

    /**
     * Totaux de la session : ventes par mode de règlement, espèces attendues en caisse.
     *
     * @return array<string, mixed>
     */
    public function totals(string $sessionId): array
    {
        $s = $this->session($sessionId);
        $by = [];
        foreach ($this->tdb->select('payments', ['session_id' => $sessionId]) as $p) {
            $by[(string) $p['method']] = ($by[(string) $p['method']] ?? 0) + (int) $p['amount'];
        }
        $sales = array_filter($this->tdb->select('sales', ['session_id' => $sessionId]), static fn ($x) => $x['status'] === 'completed');
        $refunds = 0;
        foreach ($this->tdb->select('cash_movements', ['session_id' => $sessionId]) as $m) {
            $refunds += (int) $m['amount'];
        }
        $cash = $by['cash'] ?? 0;
        return [
            'sales_count' => count($sales), 'sales_total' => array_sum(array_map(static fn ($x) => (int) $x['total'], $sales)), 'vat_total' => array_sum(array_map(static fn ($x) => (int) $x['vat_total'], $sales)),
            'discount_total' => array_sum(array_map(static fn ($x) => (int) $x['discount_total'], $sales)), 'by_method' => $by, 'cash_refunds' => $refunds,
            'opening_float' => (int) $s['opening_float'], 'expected_cash' => (int) $s['opening_float'] + $cash - $refunds,
        ];
    }

    /**
     * @param list<array{denomination: int, qty: int}> $counts
     * @return array<string, mixed> rapport Z
     */
    public function close(string $sessionId, string $userId, array $counts, ?string $reason): array
    {
        $s = $this->session($sessionId);
        if ($s['status'] !== 'open') {
            throw new ApiException('conflict', 409);
        }
        $counted = 0;
        foreach ($counts as $c) {
            if (!is_int($c['denomination'] ?? null) || !is_int($c['qty'] ?? null) || $c['denomination'] <= 0 || $c['qty'] < 0) {
                throw new ApiException('validation_failed', 422, ['fields' => ['counts' => 'invalid']]);
            }
            $counted += $c['denomination'] * $c['qty'];
        }
        $t = $this->totals($sessionId);
        $gap = $counted - (int) $t['expected_cash'];
        if ($gap !== 0 && trim((string) $reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required'], 'gap' => $gap]);
        }
        $this->db->transaction(function () use ($sessionId, $userId, $counts, $counted, $t, $gap, $reason, $s): void {
            foreach ($counts as $c) {
                if ($c['qty'] > 0) {
                    $this->tdb->insert('cash_counts', ['id' => Ulid::generate(), 'session_id' => $sessionId, 'denomination' => $c['denomination'], 'qty' => $c['qty']]);
                }
            }
            $n = $this->seq->next('z:' . $s['station'] . ':' . substr($this->clock->stamp(), 0, 4));
            $this->tdb->update('cash_sessions', [
                'status' => 'closed', 'closed_by' => $userId, 'closed_at' => $this->clock->stamp(), 'expected_cash' => $t['expected_cash'], 'counted_cash' => $counted,
                'gap' => $gap, 'gap_reason' => $reason !== null ? mb_substr(trim($reason), 0, 190) : null, 'z_number' => sprintf('Z-%s-%s-%04d', $s['station'], substr($this->clock->stamp(), 0, 4), $n),
            ], ['id' => $sessionId]);
        });
        return $this->report($sessionId);
    }

    /** @return array<string, mixed> */
    public function report(string $sessionId): array
    {
        $s = $this->session($sessionId);
        $u = $this->db->one('SELECT email FROM users WHERE id = ?', [$s['opened_by']]);
        $t = $this->db->one('SELECT name, license_number, currency FROM tenants WHERE id = ?', [$s['tenant_id']]);
        return ['session' => $s, 'totals' => $this->totals($sessionId), 'cashier' => $u['email'] ?? '', 'pharmacy' => $t['name'] ?? '', 'license' => $t['license_number'] ?? '', 'currency' => $t['currency'] ?? 'XAF'];
    }
}
