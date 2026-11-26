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
