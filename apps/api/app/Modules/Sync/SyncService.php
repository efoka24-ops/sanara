<?php
declare(strict_types=1);

namespace Sanara\Modules\Sync;

use DateTimeImmutable;
use DateTimeZone;
use Sanara\Common\Audit\AuditLogger;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Sales\CashService;
use Sanara\Modules\Sales\SalesService;

/**
 * T137 : applique les opérations produites par un poste hors-ligne. Chaque opération est atomique et
 * rejouable : déjà appliquée, elle répond « duplicate » ; refusée, elle ne laisse rien et reste sur le
 * poste. Une opération n'est acceptée que du compte qui l'a produite.
 */
final class SyncService
{
    public const SCHEMA_VERSION = 1;
    private const MAX_BATCH = 200;
    private const MAX_PAGE = 500;
    /** Au-delà, l'horloge du poste est signalée comme déréglée. */
    private const MAX_SKEW = 300;

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly Clock $clock,
        private readonly SalesService $sales,
        private readonly CashService $cash,
        private readonly Authorizer $authz,
        private readonly AuditLogger $audit,
        private readonly TenantContext $ctx,
    ) {
    }

    /**
     * @param array<string, mixed> $in deviceId, sentAt?, operations[{id, deviceId, userId, type, schemaVersion, createdAt, payload}]
     * @return array{serverTime: string, clockSkewSeconds: ?int, results: list<array<string, mixed>>}
     */
    public function push(array $in, string $userId): array
    {
        $ops = $in['operations'] ?? null;
        if (!is_array($ops) || count($ops) > self::MAX_BATCH) {
            throw new ApiException('validation_failed', 422, ['fields' => ['operations' => 'invalid']]);
        }
        $ops = array_values(array_map(static fn ($o) => (array) $o, $ops));
        usort($ops, static fn (array $a, array $b) => [(string) ($a['createdAt'] ?? ''), (string) ($a['id'] ?? '')] <=> [(string) ($b['createdAt'] ?? ''), (string) ($b['id'] ?? '')]);

        $sent = $this->time($in['sentAt'] ?? null);
        return [
            'serverTime' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            'clockSkewSeconds' => $sent === null ? null : $sent->getTimestamp() - $this->clock->now()->getTimestamp(),
            'results' => array_map(fn (array $o) => $this->one($o, $userId), $ops),
        ];
    }

    /**
     * T139 : référentiel nécessaire à la vente hors-ligne, par pages de produits classés par identifiant.
     * Les prix d'achat n'y figurent jamais.
     *
     * @return array{serverTime: string, settings: ?array<string, mixed>, products: list<array<string, mixed>>, lots: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function pull(string $cursor, int $limit): array
    {
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $tid = $this->ctx->require();
        $rows = $this->db->all('SELECT id, name, dci, form, strength, regulatory_status, sale_price, vat_bp, active FROM products WHERE tenant_id = ? AND id > ? ORDER BY id LIMIT ' . ($limit + 1), [$tid, $cursor]);
        $more = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $ids = array_column($rows, 'id');

        $barcodes = [];
        $lots = [];
        $unassigned = [];
        if ($ids !== []) {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            foreach ($this->db->all("SELECT product_id, code FROM product_barcodes WHERE tenant_id = ? AND product_id IN ($in) ORDER BY code", [$tid, ...$ids]) as $b) {
                $barcodes[$b['product_id']][] = (string) $b['code'];
            }
            $balances = [];
            foreach ($this->db->all("SELECT product_id, lot_id, SUM(qty) AS qty FROM stock_movements WHERE tenant_id = ? AND product_id IN ($in) GROUP BY product_id, lot_id", [$tid, ...$ids]) as $m) {
                if ($m['lot_id'] === null) {
                    $unassigned[$m['product_id']] = (int) $m['qty'];
                } else {
                    $balances[$m['lot_id']] = (int) $m['qty'];
                }
            }
            foreach ($this->db->all("SELECT id, product_id, lot_number, expiry, status, received_at FROM lots WHERE tenant_id = ? AND status <> 'destroyed' AND product_id IN ($in) ORDER BY product_id, expiry, received_at", [$tid, ...$ids]) as $l) {
                $lots[] = ['id' => $l['id'], 'productId' => $l['product_id'], 'lotNumber' => $l['lot_number'], 'expiry' => $l['expiry'], 'status' => $l['status'], 'receivedAt' => $l['received_at'], 'quantity' => $balances[$l['id']] ?? 0];
            }
        }

        return [
            'serverTime' => $this->clock->now()->format('Y-m-d\TH:i:s.v\Z'),
            'settings' => $cursor === '' ? ['maxDiscountBp' => (int) $this->setting('max_discount_bp', 1000), 'allowNegativeStock' => $this->setting('allow_negative_stock', false) === true, 'saleCounters' => (object) $this->saleCounters()] : null,
            'products' => array_map(static fn (array $p) => [
                'id' => $p['id'], 'name' => $p['name'], 'dci' => $p['dci'], 'form' => $p['form'], 'strength' => $p['strength'], 'regulatoryStatus' => $p['regulatory_status'],
                'salePrice' => (int) $p['sale_price'], 'vatBp' => (int) $p['vat_bp'], 'active' => (int) $p['active'] === 1, 'barcodes' => $barcodes[$p['id']] ?? [], 'unassignedQty' => $unassigned[$p['id']] ?? 0,
            ], $rows),
            'lots' => $lots,
            'nextCursor' => $more ? (string) end($ids) : null,
        ];
    }

    /** Un poste réinstallé repart après les numéros déjà attribués. @return array<string, int> « POSTE:année » => dernier numéro */
    private function saleCounters(): array
    {
        $out = [];
        foreach ($this->tdb->select('document_sequences') as $r) {
            if (str_starts_with((string) $r['scope'], 'sale:')) {
                $out[substr((string) $r['scope'], 5)] = (int) $r['seq_value'];
            }
        }
        return $out;
    }

    private function setting(string $key, mixed $default): mixed
    {
        $r = $this->tdb->select('tenant_settings', ['setting_key' => $key], null, 1)[0] ?? null;
        return $r === null ? $default : json_decode((string) $r['setting_value'], true);
    }

    /** @param array<string, mixed> $op @return array<string, mixed> */
    private function one(array $op, string $userId): array
    {
        $id = (string) ($op['id'] ?? '');
        try {
            $at = $this->time($op['createdAt'] ?? null);
            $device = (string) ($op['deviceId'] ?? '');
            if (!Ulid::isValid($id) || $at === null || $device === '' || strlen($device) > 64 || !is_int($op['schemaVersion'] ?? null) || !is_array($op['payload'] ?? null)) {
                throw new ApiException('validation_failed', 422);
            }
            if ($op['schemaVersion'] > self::SCHEMA_VERSION) {
                throw new ApiException('schema_too_new', 422);
            }
            if ($this->known($id)) {
                return ['id' => $id, 'status' => 'duplicate', 'conflicts' => []];
            }
            if (($op['userId'] ?? null) !== $userId) {
                throw new ApiException('other_user', 403);
            }
            $conflicts = $this->db->transaction(function () use ($op, $id, $device, $userId, $at): array {
                $type = (string) ($op['type'] ?? '');
                $stamp = $this->clock->stamp($at);
                $this->tdb->insert('sync_operations', ['id' => $id, 'device_id' => $device, 'user_id' => $userId, 'type' => mb_substr($type, 0, 40), 'created_at' => $stamp, 'received_at' => $this->clock->stamp()]);
                $conflicts = $this->apply($type, $op['payload'], $userId, $stamp);
                $ahead = $at->getTimestamp() - $this->clock->now()->getTimestamp();
                if ($ahead > self::MAX_SKEW) {
                    $conflicts[] = ['kind' => 'clock_skew', 'details' => ['secondsAhead' => $ahead]];
                }
                foreach ($conflicts as $c) {
                    $this->tdb->insert('sync_conflicts', ['id' => Ulid::generate(), 'operation_id' => $id, 'kind' => $c['kind'], 'details' => (string) json_encode($c['details'], JSON_UNESCAPED_UNICODE), 'created_at' => $this->clock->stamp(), 'resolved_at' => null, 'resolved_by' => null]);
                }
                $this->audit->record('sync.' . $type, ['user_id' => $userId, 'tenant_id' => $this->ctx->require(), 'device_id' => $device, 'entity' => 'sync_operation', 'entity_id' => $id]);
                return $conflicts;
            });
            return ['id' => $id, 'status' => 'applied', 'conflicts' => $conflicts];
        } catch (ApiException $e) {
            return ['id' => $id, 'status' => 'rejected', 'code' => $e->errorCode, 'details' => (object) $e->details, 'conflicts' => []];
        } catch (\Throwable $e) {
            // Deux envois simultanés de la même opération : le second la retrouve déjà enregistrée.
            if (Ulid::isValid($id) && $this->known($id)) {
                return ['id' => $id, 'status' => 'duplicate', 'conflicts' => []];
            }
            error_log('sync ' . $id . ' : ' . get_class($e) . ' ' . $e->getMessage());
            return ['id' => $id, 'status' => 'rejected', 'code' => 'server_error', 'conflicts' => []];
        }
    }

    /** @param array<string, mixed> $p @return list<array{kind: string, details: array<string, mixed>}> */
    private function apply(string $type, array $p, string $userId, string $at): array
    {
        switch ($type) {
            case 'cash.open':
                $session = (string) ($p['sessionId'] ?? '');
                if (!Ulid::isValid($session) || !is_int($p['openingFloat'] ?? null)) {
                    throw new ApiException('validation_failed', 422);
                }
                if ($this->tdb->select('cash_sessions', ['id' => $session], null, 1) !== []) {
                    throw new ApiException('conflict', 409);
                }
                $this->cash->open($userId, (string) ($p['station'] ?? ''), $p['openingFloat'], $session, $at);
                return [];
            case 'sale.create':
                return $this->sales->recordOffline($p, $userId, $at);
            case 'cash.close':
                $s = $this->cash->session((string) ($p['sessionId'] ?? ''));
                if ($s['opened_by'] !== $userId && !$this->authz->can($userId, 'cash.close')) {
                    throw new ApiException('forbidden', 403);
                }
                $this->cash->close((string) $s['id'], $userId, array_values((array) ($p['counts'] ?? [])), isset($p['reason']) ? (string) $p['reason'] : null, $at);
                return [];
            default:
                throw new ApiException('unknown_operation', 422);
        }
    }

    private function known(string $id): bool
    {
        return $this->tdb->select('sync_operations', ['id' => $id], null, 1) !== [];
    }

    private function time(mixed $iso): ?DateTimeImmutable
    {
        if (!is_string($iso) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?Z$/', $iso) !== 1) {
            return null;
        }
        try {
            return new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
