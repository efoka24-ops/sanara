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
    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly Clock $clock)
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
                    'status' => 'available',
                    'status_reason' => null,
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
