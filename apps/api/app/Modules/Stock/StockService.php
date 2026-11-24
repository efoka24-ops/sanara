<?php
declare(strict_types=1);

namespace Sanara\Modules\Stock;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/**
 * US7 (T091) : réception de stock par lot et consultation FEFO / stock courant.
 * Le stock est calculé uniquement via le journal stock_movements.
 */
final class StockService
{
    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly Clock $clock, private readonly \Sanara\Common\Tenancy\TenantContext $ctx)
    {
    }

    /** @param array<string, mixed> $in */
    public function receive(array $in, string $userId): string
    {
        $productId = trim((string) ($in['product_id'] ?? ''));
        $lotNumber = trim((string) ($in['lot_number'] ?? ''));
        $expiry = trim((string) ($in['expiry'] ?? ''));
        $qty = $this->toPositiveInt($in['quantity'] ?? null, 'quantity');
        $unitCost = $this->toNonNegativeInt($in['unit_cost'] ?? 0, 'unit_cost');
        $reason = mb_substr(trim((string) ($in['reason'] ?? 'Réception stock')), 0, 190);

        $errors = [];
        if ($productId === '') {
            $errors['product_id'] = 'required';
        }
        if ($lotNumber === '' || mb_strlen($lotNumber) > 60) {
            $errors['lot_number'] = 'invalid';
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry)) {
            $errors['expiry'] = 'invalid';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }

        $this->mustProductExist($productId);

        $movementId = Ulid::generate();
        $now = $this->clock->stamp();

        $this->db->transaction(function () use ($productId, $lotNumber, $expiry, $qty, $unitCost, $reason, $userId, $movementId, $now): void {
            $lot = $this->tdb->select('lots', ['product_id' => $productId, 'lot_number' => $lotNumber], null, 1)[0] ?? null;
            if ($lot === null) {
                $lotId = Ulid::generate();
                $this->tdb->insert('lots', [
                    'id' => $lotId,
                    'product_id' => $productId,
                    'lot_number' => $lotNumber,
                    'expiry' => $expiry,
                    'unit_cost' => $unitCost,
                    'status' => 'available',
                    'status_reason' => null,
                    'received_at' => $now,
                ]);
            } else {
                $lotId = (string) $lot['id'];
                $this->tdb->update('lots', [
                    'expiry' => $expiry,
                    'unit_cost' => $unitCost,
                ], ['id' => $lotId]);
            }

            $this->tdb->insert('stock_movements', [
                'id' => $movementId,
                'product_id' => $productId,
                'lot_id' => $lotId,
                'qty' => $qty,
                'kind' => 'receipt',
                'ref_type' => 'stock_receipt',
                'ref_id' => $movementId,
                'reversal_of' => null,
                'reason' => $reason !== '' ? $reason : null,
                'user_id' => $userId,
                'created_at' => $now,
            ]);
        });

        return $movementId;
    }

    /** @return list<array<string, mixed>> */
    public function listLots(string $productId): array
    {
        $this->mustProductExist($productId);
        $rows = $this->tdb->select('lots', ['product_id' => $productId], 'expiry');
        $balances = $this->lotBalances($productId);
        $today = substr($this->clock->stamp(), 0, 10);
        $out = [];
        foreach ($rows as $row) {
            $lotId = (string) $row['id'];
            $isExpired = (string) $row['expiry'] < $today;
            $status = $isExpired ? 'expired' : (string) $row['status'];
            $available = $status === 'available' ? ($balances[$lotId] ?? 0) : 0;
            $out[] = [
                'id' => $lotId,
                'product_id' => (string) $row['product_id'],
                'lot_number' => (string) $row['lot_number'],
                'expiry' => (string) $row['expiry'],
                'status' => $status,
                'unit_cost' => (int) $row['unit_cost'],
                'available_qty' => $available,
                'received_at' => (string) $row['received_at'],
            ];
        }
        usort($out, static function (array $a, array $b): int {
            if ($a['expiry'] === $b['expiry']) {
                return strcmp((string) $a['received_at'], (string) $b['received_at']);
            }
            return strcmp((string) $a['expiry'], (string) $b['expiry']);
        });
        return $out;
    }

    /** @return array{product_id: string, on_hand: int, lots: array<int, array<string, mixed>>} */
    public function onHand(string $productId): array
    {
        $lots = $this->listLots($productId);
        $total = 0;
        foreach ($this->lotBalances($productId) as $qty) {
            $total += $qty;
        }
        return ['product_id' => $productId, 'on_hand' => $total, 'lots' => $lots];
    }

    /** Quantité disponible par lot, tous lots confondus, pour un produit (lots bloqués ou périmés exclus). */
    public function availableQty(string $productId): int
    {
        $n = 0;
        foreach ($this->listLots($productId) as $l) {
            $n += (int) $l['available_qty'];
        }
        return $n;
    }

    public function allowsNegative(): bool
    {
        $r = $this->tdb->select('tenant_settings', ['setting_key' => 'allow_negative_stock'], null, 1)[0] ?? null;
        return $r !== null && json_decode((string) $r['setting_value'], true) === true;
    }

    /**
     * Plan FEFO : lots non périmés, non bloqués, les plus proches de la péremption d'abord.
     * Stock insuffisant : erreur, sauf si le stock négatif est autorisé (le reste n'est alors rattaché à aucun lot).
     *
     * @return list<array{lot_id: ?string, qty: int}>
     */
    public function plan(string $productId, int $qty): array
    {
        if ($qty <= 0) {
            throw new ApiException('validation_failed', 422, ['fields' => ['quantity' => 'invalid']]);
        }
        $out = [];
        $left = $qty;
        foreach ($this->listLots($productId) as $lot) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, (int) $lot['available_qty']);
            if ($take > 0) {
                $out[] = ['lot_id' => (string) $lot['id'], 'qty' => $take];
                $left -= $take;
            }
        }
        if ($left > 0) {
            if (!$this->allowsNegative()) {
                throw new ApiException('insufficient_stock', 409, ['product_id' => $productId, 'missing' => $left]);
            }
            $out[] = ['lot_id' => null, 'qty' => $left];
        }
        return $out;
    }

    /**
     * Sortie de stock en FEFO (vente, perte…). À appeler dans la transaction de l'opération appelante.
     *
     * @return list<array{lot_id: ?string, qty: int, movement_id: string}>
     */
    public function issue(string $productId, int $qty, string $kind, string $refType, string $refId, string $userId, ?string $reason = null): array
    {
        $this->mustProductExist($productId);
        $out = [];
        foreach ($this->plan($productId, $qty) as $a) {
            $id = Ulid::generate();
            $this->tdb->insert('stock_movements', [
                'id' => $id, 'product_id' => $productId, 'lot_id' => $a['lot_id'], 'qty' => -$a['qty'], 'kind' => $kind,
                'ref_type' => $refType, 'ref_id' => $refId, 'reversal_of' => null, 'reason' => $reason, 'user_id' => $userId, 'created_at' => $this->clock->stamp(),
            ]);
            $out[] = $a + ['movement_id' => $id];
        }
        return $out;
    }

    /** Ajustement motivé (casse, perte, correction) sur un lot précis. Motif obligatoire. */
    public function adjust(string $productId, string $lotId, int $delta, string $reason, string $userId, string $kind = 'adjustment'): string
    {
        $reason = trim($reason);
        if ($delta === 0 || $reason === '') {
            throw new ApiException('validation_failed', 422, ['fields' => [$delta === 0 ? 'delta' : 'reason' => 'invalid']]);
        }
        $this->mustProductExist($productId);
        $lot = $this->lot($lotId);
        if ((string) $lot['product_id'] !== $productId) {
            throw new ApiException('validation_failed', 422, ['fields' => ['lot_id' => 'invalid']]);
        }
        if ($delta < 0 && ($this->lotBalances($productId)[$lotId] ?? 0) + $delta < 0) {
            throw new ApiException('insufficient_stock', 409, ['lot_id' => $lotId]);
        }
        return $this->move($productId, $lotId, $delta, $kind, 'adjustment', null, null, mb_substr($reason, 0, 190), $userId);
    }

    /** Correction par écriture inverse : le mouvement d'origine n'est jamais modifié, ni supprimé. */
    public function reverse(string $movementId, string $reason, string $userId): string
    {
        $m = $this->tdb->select('stock_movements', ['id' => $movementId], null, 1)[0] ?? throw new ApiException('not_found', 404);
        if ($m['reversal_of'] !== null || $this->tdb->select('stock_movements', ['reversal_of' => $movementId], null, 1) !== []) {
            throw new ApiException('conflict', 409);
        }
        if (trim($reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required']]);
        }
        return $this->move((string) $m['product_id'], $m['lot_id'] === null ? null : (string) $m['lot_id'], -(int) $m['qty'], 'reversal', (string) ($m['ref_type'] ?? ''), (string) ($m['ref_id'] ?? '') ?: null, $movementId, mb_substr(trim($reason), 0, 190), $userId);
    }

    public function quarantine(string $lotId, string $reason, string $userId): void
    {
        $lot = $this->lot($lotId);
        if ($lot['status'] === 'destroyed') {
            throw new ApiException('conflict', 409);
        }
        if (trim($reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required']]);
        }
        $this->tdb->update('lots', ['status' => 'quarantine', 'status_reason' => mb_substr(trim($reason), 0, 190)], ['id' => $lotId]);
    }

    public function release(string $lotId): void
    {
        $lot = $this->lot($lotId);
        if ($lot['status'] !== 'quarantine') {
            throw new ApiException('conflict', 409);
        }
        if ((string) $lot['expiry'] < substr($this->clock->stamp(), 0, 10)) {
            throw new ApiException('conflict', 409);
        }
        $this->tdb->update('lots', ['status' => 'available', 'status_reason' => null], ['id' => $lotId]);
    }

    /** Destruction d'un lot en quarantaine ou périmé, avec numéro de procès-verbal. */
    public function destroy(string $lotId, int $qty, string $reason, ?string $method, ?string $witness, string $userId): string
    {
        $lot = $this->lot($lotId);
        $expired = (string) $lot['expiry'] < substr($this->clock->stamp(), 0, 10);
        if (!in_array($lot['status'], ['quarantine', 'expired'], true) && !$expired) {
            throw new ApiException('conflict', 409);
        }
        if ($qty <= 0 || trim($reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => [$qty <= 0 ? 'quantity' : 'reason' => 'invalid']]);
        }
        $productId = (string) $lot['product_id'];
        $balance = $this->lotBalances($productId)[$lotId] ?? 0;
        if ($qty > $balance) {
            throw new ApiException('insufficient_stock', 409, ['lot_id' => $lotId]);
        }
        $id = Ulid::generate();
        $this->db->transaction(function () use ($id, $lotId, $productId, $qty, $reason, $method, $witness, $userId, $balance): void {
            $n = count($this->tdb->select('destructions')) + 1;
            $this->tdb->insert('destructions', [
                'id' => $id, 'number' => sprintf('D-%s-%04d', substr($this->clock->stamp(), 0, 4), $n), 'product_id' => $productId, 'lot_id' => $lotId, 'qty' => $qty,
                'reason' => mb_substr(trim($reason), 0, 190), 'method' => $method !== null ? mb_substr($method, 0, 120) : null, 'witness' => $witness !== null ? mb_substr($witness, 0, 120) : null,
                'user_id' => $userId, 'created_at' => $this->clock->stamp(),
            ]);
            $this->move($productId, $lotId, -$qty, 'destroy', 'destruction', $id, null, mb_substr(trim($reason), 0, 190), $userId);
            if ($qty === $balance) {
                $this->tdb->update('lots', ['status' => 'destroyed'], ['id' => $lotId]);
            }
        });
        return $id;
    }

    /** @return array<string, mixed> données du procès-verbal de destruction */
    public function destruction(string $id): array
    {
        $d = $this->tdb->select('destructions', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
        $p = $this->tdb->select('products', ['id' => $d['product_id']], null, 1)[0] ?? [];
        $l = $this->tdb->select('lots', ['id' => $d['lot_id']], null, 1)[0] ?? [];
        $u = $this->db->one('SELECT email FROM users WHERE id = ?', [$d['user_id']]);
        $t = $this->db->one('SELECT name, license_number FROM tenants WHERE id = ?', [$this->ctx->require()]);
        return [
            'number' => $d['number'], 'date' => $d['created_at'], 'pharmacy' => $t['name'] ?? '', 'license' => $t['license_number'] ?? '',
            'product' => $p['name'] ?? '', 'lot' => $l['lot_number'] ?? '', 'expiry' => $l['expiry'] ?? '', 'qty' => (int) $d['qty'],
            'unit_cost' => (int) ($l['unit_cost'] ?? 0), 'value' => (int) $d['qty'] * (int) ($l['unit_cost'] ?? 0),
            'reason' => $d['reason'], 'method' => $d['method'], 'witness' => $d['witness'], 'user' => $u['email'] ?? '',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function destructions(): array
    {
        return array_map(fn ($d) => $this->destruction((string) $d['id']), $this->tdb->select('destructions', [], 'created_at'));
    }

    /**
     * Vue d'ensemble : un produit par ligne, stock disponible, stock bloqué, prochaine péremption, état.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(): array
    {
        $tid = $this->ctx->require();
        $today = substr($this->clock->stamp(), 0, 10);
        $rows = $this->db->all(
            'SELECT p.id, p.name, p.dci, p.min_stock, l.id AS lot_id, l.status, l.expiry, COALESCE(SUM(m.qty), 0) AS qty
             FROM products p
             LEFT JOIN lots l ON l.product_id = p.id AND l.tenant_id = p.tenant_id
             LEFT JOIN stock_movements m ON m.lot_id = l.id AND m.tenant_id = p.tenant_id
             WHERE p.tenant_id = ? AND p.active = 1
             GROUP BY p.id, p.name, p.dci, p.min_stock, l.id, l.status, l.expiry',
            [$tid],
        );
        $by = [];
        foreach ($rows as $r) {
            $id = (string) $r['id'];
            $by[$id] ??= ['product_id' => $id, 'name' => $r['name'], 'dci' => $r['dci'], 'min_stock' => (int) $r['min_stock'], 'available' => 0, 'blocked' => 0, 'next_expiry' => null];
            if ($r['lot_id'] === null) {
                continue;
            }
            $qty = (int) $r['qty'];
            $expired = (string) $r['expiry'] < $today;
            if ($r['status'] === 'available' && !$expired) {
                $by[$id]['available'] += $qty;
                if ($qty > 0 && ($by[$id]['next_expiry'] === null || (string) $r['expiry'] < $by[$id]['next_expiry'])) {
                    $by[$id]['next_expiry'] = (string) $r['expiry'];
                }
            } elseif ($r['status'] !== 'destroyed') {
                $by[$id]['blocked'] += $qty;
            }
        }
        $out = array_values($by);
        foreach ($out as &$o) {
            $o['state'] = $o['available'] <= 0 ? 'out' : ($o['available'] <= $o['min_stock'] ? 'low' : 'ok');
        }
        usort($out, static fn ($a, $b) => strcmp((string) $a['name'], (string) $b['name']));
        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function movements(string $productId): array
    {
        $this->mustProductExist($productId);
        return array_reverse($this->tdb->select('stock_movements', ['product_id' => $productId], 'created_at'));
    }

    /** @return list<array<string, mixed>> */
    public function alerts(bool $unreadOnly = false): array
    {
        $where = $unreadOnly ? ['read_at' => null] : [];
        return array_reverse($this->tdb->select('stock_alerts', $where, 'created_at', 200));
    }

    public function markAlertsRead(): void
    {
        $this->tdb->update('stock_alerts', ['read_at' => $this->clock->stamp()], ['read_at' => null]);
    }

    /**
     * Job quotidien pour la pharmacie courante : alertes de péremption à 90/60/30 jours, lots périmés bloqués, stock bas ou en rupture.
     *
     * @return array{expiry_alerts: int, expired_blocked: int, stock_alerts: int}
     */
    public function runDailyChecks(): array
    {
        $today = substr($this->clock->stamp(), 0, 10);
        $res = ['expiry_alerts' => 0, 'expired_blocked' => 0, 'stock_alerts' => 0];
        $names = array_column($this->tdb->select('products'), 'name', 'id');
        foreach ($this->tdb->select('lots') as $lot) {
            if ($lot['status'] === 'destroyed') {
                continue;
            }
            $bal = $this->lotBalances((string) $lot['product_id'])[(string) $lot['id']] ?? 0;
            if ($bal <= 0) {
                continue;
            }
            $days = (int) floor((strtotime((string) $lot['expiry'] . ' UTC') - strtotime($today . ' UTC')) / 86400);
            $name = (string) ($names[$lot['product_id']] ?? '');
            if ($days < 0) {
                if ($lot['status'] === 'available') {
                    $this->tdb->update('lots', ['status' => 'expired', 'status_reason' => 'Périmé'], ['id' => $lot['id']]);
                    $res['expired_blocked']++;
                }
                $res['expiry_alerts'] += $this->alert('expired', (string) $lot['product_id'], (string) $lot['id'], "$name, lot {$lot['lot_number']} périmé.");
                continue;
            }
            foreach ([30, 60, 90] as $w) {
                if ($days <= $w) {
                    $res['expiry_alerts'] += $this->alert("expiry_$w", (string) $lot['product_id'], (string) $lot['id'], "$name, lot {$lot['lot_number']} expire dans $days jours.");
                    break;
                }
            }
        }
        foreach ($this->overview() as $o) {
            if ($o['state'] === 'ok') {
                continue;
            }
            $res['stock_alerts'] += $this->alert((string) $o['state'], (string) $o['product_id'], null, $o['state'] === 'out' ? "{$o['name']} : rupture de stock." : "{$o['name']} : stock bas ({$o['available']} pour un minimum de {$o['min_stock']}).", $today);
        }
        return $res;
    }

    private function alert(string $kind, string $productId, ?string $lotId, string $message, string $suffix = ''): int
    {
        $key = $kind . ':' . ($lotId ?? $productId) . ($suffix !== '' ? ':' . $suffix : '');
        if ($this->tdb->select('stock_alerts', ['dedupe_key' => $key], null, 1) !== []) {
            return 0;
        }
        $this->tdb->insert('stock_alerts', ['id' => Ulid::generate(), 'dedupe_key' => $key, 'kind' => $kind, 'product_id' => $productId, 'lot_id' => $lotId, 'message' => mb_substr($message, 0, 190), 'created_at' => $this->clock->stamp(), 'read_at' => null]);
        return 1;
    }

    /** Alerte de péremption courte à la réception d'un lot (T096). */
    public function shortDated(string $expiry): bool
    {
        return (strtotime($expiry . ' UTC') - strtotime(substr($this->clock->stamp(), 0, 10) . ' UTC')) / 86400 <= 90;
    }

    private function move(string $productId, ?string $lotId, int $qty, string $kind, ?string $refType, ?string $refId, ?string $reversalOf, ?string $reason, string $userId): string
    {
        $id = Ulid::generate();
        $this->tdb->insert('stock_movements', ['id' => $id, 'product_id' => $productId, 'lot_id' => $lotId, 'qty' => $qty, 'kind' => $kind, 'ref_type' => $refType, 'ref_id' => $refId, 'reversal_of' => $reversalOf, 'reason' => $reason, 'user_id' => $userId, 'created_at' => $this->clock->stamp()]);
        return $id;
    }

    /** @return array<string, mixed> */
    private function lot(string $id): array
    {
        return $this->tdb->select('lots', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
    }

    /** @return array<string, int> */
    private function lotBalances(string $productId): array
    {
        $rows = $this->tdb->select('stock_movements', ['product_id' => $productId], 'created_at');
        $out = [];
        foreach ($rows as $row) {
            $lotId = (string) ($row['lot_id'] ?? '');
            if ($lotId === '') {
                continue;
            }
            $out[$lotId] = ($out[$lotId] ?? 0) + (int) $row['qty'];
        }
        return $out;
    }

    private function mustProductExist(string $productId): void
    {
        if ($productId === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['product_id' => 'required']]);
        }
        $p = $this->tdb->select('products', ['id' => $productId], null, 1)[0] ?? null;
        if ($p === null) {
            throw new ApiException('not_found', 404);
        }
    }

    private function toPositiveInt(mixed $value, string $field): int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) {
            throw new ApiException('validation_failed', 422, ['fields' => [$field => 'invalid']]);
        }
        $v = (int) $value;
        if ($v <= 0) {
            throw new ApiException('validation_failed', 422, ['fields' => [$field => 'invalid']]);
        }
        return $v;
    }

    private function toNonNegativeInt(mixed $value, string $field): int
    {
        if (!(is_int($value) || (is_string($value) && ctype_digit($value)))) {
            throw new ApiException('validation_failed', 422, ['fields' => [$field => 'invalid']]);
        }
        $v = (int) $value;
        if ($v < 0) {
            throw new ApiException('validation_failed', 422, ['fields' => [$field => 'invalid']]);
        }
        return $v;
    }
}
