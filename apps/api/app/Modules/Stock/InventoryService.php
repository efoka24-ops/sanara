<?php
declare(strict_types=1);

namespace Sanara\Modules\Stock;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/**
 * US8 : inventaire complet ou tournant. Le stock attendu est figé à l'instant du comptage, donc les ventes
 * faites pendant l'inventaire ne créent pas d'écart. Les comptages de plusieurs appareils s'additionnent
 * (le plus récent par appareil gagne). Ajustements appliqués à la validation seulement.
 */
final class InventoryService
{
    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly Clock $clock)
    {
    }

    /** @return string id */
    public function create(string $kind, bool $blind, ?string $familyId, string $userId): string
    {
        if (!in_array($kind, ['full', 'cycle'], true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['kind' => 'invalid']]);
        }
        $products = $this->tdb->select('products', $familyId !== null && $familyId !== '' ? ['family_id' => $familyId] : []);
        if ($kind === 'cycle' && ($familyId === null || $familyId === '')) {
            throw new ApiException('validation_failed', 422, ['fields' => ['family_id' => 'required']]);
        }
        $id = Ulid::generate();
        $this->db->transaction(function () use ($id, $kind, $blind, $familyId, $userId, $products): void {
            $n = count($this->tdb->select('inventories')) + 1;
            $this->tdb->insert('inventories', [
                'id' => $id, 'number' => sprintf('I-%s-%04d', substr($this->clock->stamp(), 0, 4), $n), 'kind' => $kind, 'blind' => $blind ? 1 : 0,
                'family_id' => $familyId !== '' ? $familyId : null, 'status' => 'open', 'created_by' => $userId, 'validated_by' => null,
                'created_at' => $this->clock->stamp(), 'validated_at' => null, 'gap_value' => null,
            ]);
            foreach ($products as $p) {
                foreach ($this->tdb->select('lots', ['product_id' => $p['id']]) as $lot) {
                    if ($lot['status'] === 'destroyed') {
                        continue;
                    }
                    $this->tdb->insert('inventory_lines', [
                        'id' => Ulid::generate(), 'inventory_id' => $id, 'product_id' => $p['id'], 'lot_id' => $lot['id'],
                        'expected_qty' => null, 'counted_qty' => null, 'counted_at' => null, 'unit_cost' => (int) $lot['unit_cost'],
                    ]);
                }
            }
        });
        return $id;
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $inv = $this->row($id);
        return $inv + ['lines_count' => count($this->tdb->select('inventory_lines', ['inventory_id' => $id])), 'counted_count' => $this->counted($id)];
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_reverse($this->tdb->select('inventories', [], 'created_at'));
    }

    /**
     * Lignes à compter. En mode aveugle et tant que l'inventaire est ouvert, le stock attendu est masqué.
     *
     * @return list<array<string, mixed>>
     */
    public function lines(string $id): array
    {
        $inv = $this->row($id);
        $hide = (int) $inv['blind'] === 1 && $inv['status'] === 'open';
        $names = array_column($this->tdb->select('products'), 'name', 'id');
        $lots = array_column($this->tdb->select('lots'), null, 'id');
        $out = [];
        foreach ($this->tdb->select('inventory_lines', ['inventory_id' => $id]) as $l) {
            $lot = $lots[$l['lot_id']] ?? [];
            $out[] = [
                'lot_id' => $l['lot_id'], 'product_id' => $l['product_id'], 'product' => $names[$l['product_id']] ?? '', 'lot_number' => $lot['lot_number'] ?? '', 'expiry' => $lot['expiry'] ?? '',
                'expected_qty' => $hide ? null : ($l['expected_qty'] === null ? null : (int) $l['expected_qty']),
                'counted_qty' => $l['counted_qty'] === null ? null : (int) $l['counted_qty'], 'counted_at' => $l['counted_at'],
            ];
        }
        usort($out, static fn ($a, $b) => strcmp($a['product'] . $a['expiry'], $b['product'] . $b['expiry']));
        return $out;
    }

    /**
     * Enregistre des comptages (en ligne ou rejoués après une coupure). Chaque entrée : lot_id, qty, counted_at (optionnel).
     *
     * @param list<array<string, mixed>> $counts
     */
    public function count(string $id, string $deviceId, array $counts, string $userId): int
    {
        $inv = $this->row($id);
        if ($inv['status'] !== 'open') {
            throw new ApiException('conflict', 409);
        }
        $deviceId = trim($deviceId) !== '' ? mb_substr(trim($deviceId), 0, 64) : 'web';
        $done = 0;
        $this->db->transaction(function () use ($id, $deviceId, $counts, $userId, &$done): void {
            foreach ($counts as $c) {
                $lotId = (string) ($c['lot_id'] ?? '');
                $qty = $c['qty'] ?? null;
                if ($this->tdb->select('inventory_lines', ['inventory_id' => $id, 'lot_id' => $lotId], null, 1) === [] || !is_int($qty) || $qty < 0) {
                    throw new ApiException('validation_failed', 422, ['fields' => ['counts' => 'invalid']]);
                }
                $at = isset($c['counted_at']) && is_string($c['counted_at']) && strtotime($c['counted_at']) !== false
                    ? $this->clock->stamp(new \DateTimeImmutable($c['counted_at'], new \DateTimeZone('UTC'))) : $this->clock->stamp();
                $prev = $this->tdb->select('inventory_counts', ['inventory_id' => $id, 'lot_id' => $lotId, 'device_id' => $deviceId], null, 1)[0] ?? null;
                if ($prev !== null && (string) $prev['counted_at'] > $at) {
                    continue; // un comptage plus récent du même appareil existe déjà
                }
                if ($prev === null) {
                    $this->tdb->insert('inventory_counts', ['id' => Ulid::generate(), 'inventory_id' => $id, 'lot_id' => $lotId, 'device_id' => $deviceId, 'qty' => $qty, 'counted_at' => $at, 'user_id' => $userId]);
                } else {
                    $this->tdb->update('inventory_counts', ['qty' => $qty, 'counted_at' => $at, 'user_id' => $userId], ['id' => $prev['id']]);
                }
                $this->merge($id, $lotId);
                $done++;
            }
        });
        return $done;
    }

    public function submit(string $id): void
    {
        $inv = $this->row($id);
        if ($inv['status'] !== 'open' || $this->counted($id) === 0) {
            throw new ApiException('conflict', 409);
        }
        $this->tdb->update('inventories', ['status' => 'pending_validation'], ['id' => $id]);
    }

    /** @return array{lines: list<array<string, mixed>>, total_gap_qty: int, gap_value: int, loss_value: int, surplus_value: int, uncounted: int} */
    public function report(string $id): array
    {
        $this->row($id);
        $lines = [];
        $loss = 0;
        $surplus = 0;
        $uncounted = 0;
        $qty = 0;
        foreach ($this->tdb->select('inventory_lines', ['inventory_id' => $id]) as $l) {
            if ($l['counted_qty'] === null) {
                $uncounted++;
                continue;
            }
            $gap = (int) $l['counted_qty'] - (int) $l['expected_qty'];
            $value = $gap * (int) $l['unit_cost'];
            $qty += $gap;
            $gap < 0 ? $loss += $value : $surplus += $value;
            $lines[] = ['lot_id' => $l['lot_id'], 'product_id' => $l['product_id'], 'expected_qty' => (int) $l['expected_qty'], 'counted_qty' => (int) $l['counted_qty'], 'gap' => $gap, 'unit_cost' => (int) $l['unit_cost'], 'value' => $value];
        }
        $names = array_column($this->tdb->select('products'), 'name', 'id');
        $lots = array_column($this->tdb->select('lots'), 'lot_number', 'id');
        foreach ($lines as &$l) {
            $l['product'] = $names[$l['product_id']] ?? '';
            $l['lot_number'] = $lots[$l['lot_id']] ?? '';
        }
        return ['lines' => array_values(array_filter($lines, static fn ($l) => $l['gap'] !== 0)), 'total_gap_qty' => $qty, 'gap_value' => $loss + $surplus, 'loss_value' => $loss, 'surplus_value' => $surplus, 'uncounted' => $uncounted];
    }

    public function reportCsv(string $id): string
    {
        $r = $this->report($id);
        $out = fopen('php://temp', 'w+');
        fputcsv($out, ['produit', 'lot', 'attendu', 'compte', 'ecart', 'prix_achat', 'valeur'], ';', '"', '');
        foreach ($r['lines'] as $l) {
            fputcsv($out, [$l['product'], $l['lot_number'], $l['expected_qty'], $l['counted_qty'], $l['gap'], $l['unit_cost'], $l['value']], ';', '"', '');
        }
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /** Validation par un pharmacien : applique les écarts sous forme de mouvements. */
    public function validate(string $id, string $userId, StockService $stock): array
    {
        $inv = $this->row($id);
        if ($inv['status'] !== 'pending_validation') {
            throw new ApiException('conflict', 409);
        }
        $report = $this->report($id);
        $this->db->transaction(function () use ($id, $userId, $report, $inv): void {
            foreach ($this->tdb->select('inventory_lines', ['inventory_id' => $id]) as $l) {
                if ($l['counted_qty'] === null) {
                    continue;
                }
                $gap = (int) $l['counted_qty'] - (int) $l['expected_qty'];
                if ($gap !== 0) {
                    $this->tdb->insert('stock_movements', [
                        'id' => Ulid::generate(), 'product_id' => $l['product_id'], 'lot_id' => $l['lot_id'], 'qty' => $gap, 'kind' => 'inventory', 'ref_type' => 'inventory', 'ref_id' => $id,
                        'reversal_of' => null, 'reason' => 'Inventaire ' . $inv['number'], 'user_id' => $userId, 'created_at' => $this->clock->stamp(),
                    ]);
                }
            }
            $this->tdb->update('inventories', ['status' => 'validated', 'validated_by' => $userId, 'validated_at' => $this->clock->stamp(), 'gap_value' => $report['gap_value']], ['id' => $id]);
        });
        return $report;
    }

    public function cancel(string $id): void
    {
        $inv = $this->row($id);
        if (!in_array($inv['status'], ['open', 'pending_validation'], true)) {
            throw new ApiException('conflict', 409);
        }
        $this->tdb->update('inventories', ['status' => 'cancelled'], ['id' => $id]);
    }

    /** Recalcule la ligne : somme des comptages des appareils, stock attendu à l'instant du dernier comptage. */
    private function merge(string $id, string $lotId): void
    {
        $qty = 0;
        $at = '';
        foreach ($this->tdb->select('inventory_counts', ['inventory_id' => $id, 'lot_id' => $lotId]) as $c) {
            $qty += (int) $c['qty'];
            $at = max($at, (string) $c['counted_at']);
        }
        $expected = 0;
        foreach ($this->tdb->select('stock_movements', ['lot_id' => $lotId]) as $m) {
            if ((string) $m['created_at'] <= $at && !($m['kind'] === 'inventory' && $m['ref_id'] === $id)) {
                $expected += (int) $m['qty'];
            }
        }
        $this->tdb->update('inventory_lines', ['counted_qty' => $qty, 'counted_at' => $at, 'expected_qty' => $expected], ['inventory_id' => $id, 'lot_id' => $lotId]);
    }

    private function counted(string $id): int
    {
        return count(array_filter($this->tdb->select('inventory_lines', ['inventory_id' => $id]), static fn ($l) => $l['counted_qty'] !== null));
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        return $this->tdb->select('inventories', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
    }
}
