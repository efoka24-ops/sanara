<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Persistence\Db;

/**
 * US18 : lecture publique cross-tenant de la table de projection `public_pharmacies`.
 * Lecture volontairement en dehors de TenantDb : ce service sert un visiteur anonyme
 * qui doit voir les pharmacies visibles de TOUTES les pharmacies, pas une seule.
 * La table reste listée dans TenantTables::SCOPED pour que les écritures (une par
 * pharmacie, déclenchées par les changements de visibilité/horaires de cette pharmacie)
 * passent par TenantDb et restent isolées comme toute donnée tenant.
 */
final class PublicPharmacyService
{
    public function __construct(private readonly Db $db)
    {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function search(string $q, string $region, string $city, int $limit, int $offset): array
    {
        // Une pharmacie non publiée ou suspendue n'est jamais rendue, quels que soient les paramètres.
        $where = ['visible = ?'];
        $params = [1];

        if ($region !== '') {
            $where[] = 'region = ?';
            $params[] = $region;
        }

        if ($city !== '') {
            $where[] = 'city = ?';
            $params[] = $city;
        }

        if ($q !== '') {
            $where[] = "(LOWER(pharmacy_name) LIKE ? ESCAPE '!' OR LOWER(city) LIKE ? ESCAPE '!' OR LOWER(district) LIKE ? ESCAPE '!')";
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($q)) . '%';
            array_push($params, $term, $term, $term);
        }

        $sqlWhere = ' WHERE ' . implode(' AND ', $where);

        $total = (int) ($this->db->one('SELECT COUNT(*) AS n FROM public_pharmacies' . $sqlWhere, $params)['n'] ?? 0);

        $items = $this->db->all(
            'SELECT id, pharmacy_name, region, city, district, address, phone'
            . ' FROM public_pharmacies'
            . $sqlWhere
            . ' ORDER BY pharmacy_name ASC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
            $params,
        );

        return ['items' => $items, 'total' => $total, 'limit' => $limit, 'offset' => $offset];
    }
}
