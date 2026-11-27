<?php
declare(strict_types=1);

namespace Sanara\Modules\Requests;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/** Règles communes aux demandes patients : historique, échéances, stock réservé et retrait en caisse. */
final class RequestLedger
{
    public function dailyQuota(): int
    {
        return $this->platform?->int('request_quota_per_day') ?? 5;
    }

    /** Durée de validité d'un devis. */
    public const QUOTE_HOURS = 24;
    private const PICKUP_HOURS = 24;

    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly Clock $clock, private readonly ?\Sanara\Common\PlatformSettings $platform = null)
    {
    }

    /** Durée de validité d'un devis (stock réservé), réglée dans la console. */
    public function quoteHours(): int
    {
        return max(1, $this->platform?->int('reservation_release_hours') ?? self::QUOTE_HOURS);
    }

    public function event(string $requestId, string $kind, ?string $actor = null, ?string $note = null): void
    {
        $seq = count($this->tdb->select('request_events', ['request_id' => $requestId])) + 1;
        $this->tdb->insert('request_events', [
            'id' => Ulid::generate(), 'request_id' => $requestId, 'kind' => $kind, 'actor_id' => $actor,
            'note' => $note === null ? null : mb_substr($note, 0, 500), 'seq' => $seq, 'created_at' => $this->clock->stamp(),
        ]);
    }

    /** @param array<string, mixed> $set */
    public function move(string $requestId, array $set): void
    {
        $this->tdb->update('patient_requests', $set + ['updated_at' => $this->clock->stamp()], ['id' => $requestId]);
    }

    /** Passe en « expirée » les demandes dont le devis ou la mise de côté est échu : le stock redevient libre. */
    public function expire(): void
    {
        $now = $this->clock->stamp();
        $due = [];
        foreach ($this->tdb->select('patient_requests', ['status' => 'quoted']) as $r) {
            $quote = $this->tdb->select('quotes', ['request_id' => $r['id']], null, 1)[0] ?? null;
            if ($quote !== null && $quote['valid_until'] <= $now) {
                $due[] = [$r['id'], 'quoted'];
            }
        }
        foreach (['accepted', 'ready'] as $status) {
            foreach ($this->tdb->select('patient_requests', ['status' => $status]) as $r) {
                if ($r['pickup_expires_at'] <= $now) {
                    $due[] = [$r['id'], $status];
                }
            }
        }
        foreach ($due as [$id, $status]) {
            $this->db->transaction(function () use ($id, $status, $now): void {
                // Le statut lu est exigé : une décision arrivée entre-temps l'emporte.
                if ($this->tdb->update('patient_requests', ['status' => 'expired', 'pickup_code' => null, 'updated_at' => $now], ['id' => $id, 'status' => $status]) === 1) {
                    $this->event((string) $id, 'expired');
                }
            });
        }
    }

    /** Heures pendant lesquelles une commande acceptée reste de côté. */
    public function holdUntil(): string
    {
        $r = $this->tdb->select('tenant_settings', ['setting_key' => 'pickup_hold_hours'], null, 1)[0] ?? null;
        $hours = $r === null ? self::PICKUP_HOURS : max(1, (int) json_decode((string) $r['setting_value'], true));
        return $this->clock->stamp($this->clock->now()->modify("+$hours hours"));
    }

    /** Quantité promise à d'autres patients et pas encore retirée. */
    public function reserved(string $productId, string $exceptRequest): int
    {
        $total = 0;
        foreach (['quoted', 'accepted', 'ready'] as $status) {
            foreach ($this->tdb->select('patient_requests', ['status' => $status]) as $r) {
                if ($r['id'] === $exceptRequest) {
                    continue;
                }
                foreach ($this->tdb->select('quote_lines', ['request_id' => $r['id'], 'product_id' => $productId]) as $l) {
                    if ((int) $l['available'] === 1 && ($l['accepted'] === null || (int) $l['accepted'] === 1)) {
                        $total += (int) $l['quantity'];
                    }
                }
            }
        }
        return $total;
    }

    /** Lignes acceptées d'une demande. @return list<array<string, mixed>> */
    public function accepted(string $requestId): array
    {
        return array_values(array_filter(
            $this->tdb->select('quote_lines', ['request_id' => $requestId], 'position'),
            static fn (array $l) => (int) $l['accepted'] === 1,
        ));
    }

    /**
     * Prix promis pour un retrait au comptoir. La vente ne peut contenir que les produits et quantités
     * acceptés par le patient.
     *
     * @param list<array{product_id: string, qty: int, discount_bp: int}> $lines
     * @return array<string, int> prix unitaire du devis par produit
     */
    public function pickupPrices(string $requestId, array $lines): array
    {
        $this->expire();
        $request = $this->tdb->select('patient_requests', ['id' => $requestId], null, 1)[0] ?? throw new ApiException('not_found', 404);
        if (!in_array($request['status'], ['accepted', 'ready'], true)) {
            throw new ApiException('conflict', 409);
        }
        $left = [];
        $prices = [];
        foreach ($this->accepted($requestId) as $l) {
            $id = (string) $l['product_id'];
            $left[$id] = ($left[$id] ?? 0) + (int) $l['quantity'];
            $prices[$id] = (int) $l['unit_price'];
        }
        foreach ($lines as $l) {
            $id = $l['product_id'];
            if (!isset($left[$id]) || $l['qty'] > $left[$id] || $l['discount_bp'] !== 0) {
                throw new ApiException('pickup_mismatch', 422, ['product_id' => $id]);
            }
            $left[$id] -= $l['qty'];
        }
        return $prices;
    }

    /** Le code ne sert qu'une fois. À appeler dans la transaction de la vente. */
    public function pickedUp(string $requestId, string $saleId, string $userId): void
    {
        $now = $this->clock->stamp();
        $set = ['status' => 'picked_up', 'sale_id' => $saleId, 'pickup_code' => null, 'picked_up_at' => $now, 'updated_at' => $now];
        $done = $this->tdb->update('patient_requests', $set, ['id' => $requestId, 'status' => 'ready'])
            + $this->tdb->update('patient_requests', $set, ['id' => $requestId, 'status' => 'accepted']);
        if ($done !== 1) {
            throw new ApiException('conflict', 409);
        }
        $this->event($requestId, 'picked_up', $userId);
    }
}
