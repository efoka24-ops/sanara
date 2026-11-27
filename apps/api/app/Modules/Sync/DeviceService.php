<?php
declare(strict_types=1);

namespace Sanara\Modules\Sync;

use Sanara\Common\Audit\AuditLogger;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/**
 * T129 : un poste s'enregistre sous un nom unique dans sa pharmacie, qui préfixe ses tickets. Le nombre
 * de postes actifs ne dépasse pas celui de l'abonnement.
 */
final class DeviceService
{
    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly Clock $clock,
        private readonly AuditLogger $audit,
        private readonly TenantContext $ctx,
    ) {
    }

    /** @return array{id: string, name: string} */
    public function register(string $userId, string $deviceId, string $name): array
    {
        // Même forme que le nom de caisse (CashService::station), sans valeur par défaut.
        $name = substr(strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $name) ?? ''), 0, 8);
        $errors = [];
        if ($name === '') {
            $errors['name'] = 'invalid';
        }
        if ($deviceId === '' || strlen($deviceId) > 64) {
            $errors['deviceId'] = 'invalid';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        $tenant = $this->ctx->require();

        return $this->db->transaction(function () use ($userId, $deviceId, $name, $tenant): array {
            $devices = $this->tdb->select('devices');
            $mine = null;
            $active = 0;
            foreach ($devices as $d) {
                if ($d['fingerprint'] === $deviceId) {
                    $mine = $d;
                    continue;
                }
                if ($d['revoked_at'] !== null) {
                    continue;
                }
                $active++;
                if ($d['name'] === $name) {
                    throw new ApiException('station_name_taken', 409);
                }
            }
            if ($mine !== null && $mine['revoked_at'] !== null) {
                throw new ApiException('device_revoked', 403);
            }
            if ($mine === null) {
                $allowed = $this->db->one('SELECT stations FROM subscriptions WHERE pharmacy_id = ?', [$tenant]);
                if ($allowed !== null && $active >= (int) $allowed['stations']) {
                    throw new ApiException('station_limit_reached', 409);
                }
            }
            $now = $this->clock->stamp();
            $id = $mine === null ? Ulid::generate() : (string) $mine['id'];
            if ($mine === null) {
                $this->tdb->insert('devices', ['id' => $id, 'user_id' => $userId, 'name' => $name, 'fingerprint' => $deviceId, 'last_seen_at' => $now, 'revoked_at' => null, 'created_at' => $now]);
            } else {
                $this->tdb->update('devices', ['name' => $name, 'user_id' => $userId, 'last_seen_at' => $now], ['id' => $id]);
            }
            if ($mine === null || $mine['name'] !== $name) {
                $this->audit->record('device.register', ['user_id' => $userId, 'tenant_id' => $tenant, 'device_id' => $deviceId, 'entity' => 'device', 'entity_id' => $id]);
            }
            return ['id' => $id, 'name' => $name];
        });
    }
}
