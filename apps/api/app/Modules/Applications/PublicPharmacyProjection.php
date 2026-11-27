<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/**
 * T144 : alimente la table de lecture publique. Une pharmacie n'y est visible que si elle l'a demandé
 * et qu'elle est active. Le tenant concerné est toujours fourni par le serveur, jamais par le client.
 */
final class PublicPharmacyProjection
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    public function refresh(string $tenantId): void
    {
        $t = $this->db->one('SELECT name, address, phone, country_code, latitude, longitude, status, public_visible, deleted_at FROM tenants WHERE id = ?', [$tenantId]);
        if ($t === null) {
            return;
        }
        // La localisation administrative vient de la demande d'accès acceptée.
        $a = $this->db->one('SELECT region, department, city, district, latitude, longitude FROM pharmacy_applications WHERE tenant_id = ?', [$tenantId]) ?? [];
        $point = $t['latitude'] !== null && $t['longitude'] !== null ? $t : $a;
        $visible = (int) $t['public_visible'] === 1 && $t['status'] === 'active' && $t['deleted_at'] === null ? 1 : 0;
        $row = [
            'pharmacy_name' => $t['name'], 'region' => $a['region'] ?? '', 'city' => $a['city'] ?? '', 'district' => $a['district'] ?? null,
            'address' => $t['address'], 'phone' => $t['phone'], 'visible' => $visible, 'updated_at' => $this->clock->stamp(),
            'country_code' => $t['country_code'], 'department' => $a['department'] ?? null,
            'latitude' => $point['latitude'] ?? null, 'longitude' => $point['longitude'] ?? null, 'hours' => $this->hours($tenantId),
        ];
        $this->db->transaction(function () use ($tenantId, $row): void {
            $existing = $this->db->one('SELECT id FROM public_pharmacies WHERE tenant_id = ?', [$tenantId]);
            if ($existing === null) {
                $this->db->insert('public_pharmacies', ['id' => Ulid::generate(), 'tenant_id' => $tenantId, 'created_at' => $row['updated_at']] + $row);
                return;
            }
            $set = implode(', ', array_map(static fn (string $c) => "$c = ?", array_keys($row)));
            $this->db->execute("UPDATE public_pharmacies SET $set WHERE tenant_id = ?", [...array_values($row), $tenantId]);
        });
    }

    /** Jours ouverts en JSON, ou null tant que la pharmacie n'a pas donné ses horaires. */
    private function hours(string $tenantId): ?string
    {
        $days = $this->db->all('SELECT weekday, is_open, opens, closes FROM opening_hours WHERE tenant_id = ? ORDER BY weekday', [$tenantId]);
        if ($days === []) {
            return null;
        }
        $open = [];
        foreach ($days as $d) {
            if ((int) $d['is_open'] === 1) {
                $open[] = [(int) $d['weekday'], (string) $d['opens'], (string) $d['closes']];
            }
        }
        return json_encode($open);
    }

    /** Rattrapage au déploiement : idempotent. */
    public function refreshAll(): void
    {
        foreach ($this->db->all('SELECT id FROM tenants') as $t) {
            $this->refresh((string) $t['id']);
        }
    }
}
