<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
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
    private const PLACES = ['country' => 'country_code', 'region' => 'region', 'department' => 'department', 'city' => 'city', 'district' => 'district'];
    private const FALLBACK_ZONE = 'Africa/Douala';

    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /**
     * Filtres : q, country, region, department, city, district, bbox (« ouest,sud,est,nord »), open.
     * Les distances partent de la position (lat, lng) ou, à défaut, d'un quartier saisi (near).
     *
     * @param array<string, mixed> $query paramètres de la requête
     * @return array{items: list<array<string, mixed>>, total: int, limit: int, offset: int, origin: ?string}
     */
    public function search(array $query): array
    {
        $text = static fn (string $k): string => is_string($query[$k] ?? null) ? trim($query[$k]) : '';
        $limit = max(1, min(100, (int) ($query['limit'] ?? 20)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $position = $this->position($text('lat'), $text('lng'));

        // Une pharmacie non publiée ou suspendue n'est jamais rendue, quels que soient les paramètres.
        $where = ['p.visible = ?'];
        $params = [1];
        foreach (self::PLACES as $key => $column) {
            if ($text($key) !== '') {
                $where[] = "p.$column = ?";
                $params[] = $text($key);
            }
        }
        if ($text('q') !== '') {
            $columns = ['pharmacy_name', 'city', 'district', 'department', 'region'];
            $where[] = '(' . implode(' OR ', array_map(static fn (string $c) => "LOWER(p.$c) LIKE ? ESCAPE '!'", $columns)) . ')';
            $term = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($text('q'))) . '%';
            array_push($params, ...array_fill(0, count($columns), $term));
        }
        if ($text('bbox') !== '') {
            [$west, $south, $east, $north] = $this->box($text('bbox'));
            $where[] = 'p.longitude BETWEEN ? AND ? AND p.latitude BETWEEN ? AND ?';
            array_push($params, $west, $east, $south, $north);
        }

        $rows = $this->db->all(
            'SELECT p.id, p.pharmacy_name, p.country_code, p.region, p.department, p.city, p.district, p.address, p.phone,'
            . ' p.latitude, p.longitude, p.hours, c.timezone'
            . ' FROM public_pharmacies p LEFT JOIN countries c ON c.code = p.country_code'
            . ' WHERE ' . implode(' AND ', $where),
            $params,
        );

        $origin = $position !== null ? 'position' : null;
        if ($position === null && $text('near') !== '') {
            $position = $this->districtCentre($text('near'), $text('city'));
            $origin = $position !== null ? 'district' : null;
        }

        $now = $this->clock->now();
        $items = [];
        foreach ($rows as $r) {
            $status = OpeningStatus::at($r['hours'] === null ? null : json_decode((string) $r['hours'], true), $now, (string) ($r['timezone'] ?? self::FALLBACK_ZONE));
            if (!empty($query['open']) && $status['open'] !== true) {
                continue;
            }
            $lat = $r['latitude'] === null ? null : (float) $r['latitude'];
            $lng = $r['longitude'] === null ? null : (float) $r['longitude'];
            unset($r['hours'], $r['timezone']);
            $items[] = ['latitude' => $lat, 'longitude' => $lng] + $r + $status + [
                'distance_m' => $position !== null && $lat !== null && $lng !== null ? $this->metres($position, [$lat, $lng]) : null,
            ];
        }

        // Les plus proches d'abord ; sans distance, ordre alphabétique après les autres.
        usort($items, static fn (array $a, array $b) => [$a['distance_m'] ?? PHP_INT_MAX, mb_strtolower((string) $a['pharmacy_name'])]
            <=> [$b['distance_m'] ?? PHP_INT_MAX, mb_strtolower((string) $b['pharmacy_name'])]);

        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items), 'limit' => $limit, 'offset' => $offset, 'origin' => $origin];
    }

    /**
     * T147 : lieux où au moins une pharmacie est publiée, pour les listes en cascade.
     *
     * @return list<array<string, mixed>>
     */
    public function places(): array
    {
        $rows = $this->db->all(
            'SELECT country_code, region, department, city, district, COUNT(*) AS pharmacies FROM public_pharmacies'
            . " WHERE visible = ? AND region <> '' GROUP BY country_code, region, department, city, district"
            . ' ORDER BY country_code, region, department, city, district',
            [1],
        );
        return array_map(static function (array $r): array {
            $r['pharmacies'] = (int) $r['pharmacies'];
            return $r;
        }, $rows);
    }

    /** @return array{0: float, 1: float}|null */
    private function position(string $lat, string $lng): ?array
    {
        if ($lat === '' && $lng === '') {
            return null;
        }
        if (!is_numeric($lat) || !is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            throw new ApiException('validation_failed', 422, ['fields' => ['lat' => 'position']]);
        }
        return [(float) $lat, (float) $lng];
    }

    /** @return list<float> */
    private function box(string $bbox): array
    {
        $parts = explode(',', $bbox);
        if (count($parts) !== 4 || count(array_filter($parts, 'is_numeric')) !== 4) {
            throw new ApiException('validation_failed', 422, ['fields' => ['bbox' => 'format']]);
        }
        return array_map('floatval', $parts);
    }

    /** Centre des pharmacies publiées d'un quartier. @return array{0: float, 1: float}|null */
    private function districtCentre(string $district, string $city): ?array
    {
        $points = [];
        $rows = $this->db->all('SELECT city, district, latitude, longitude FROM public_pharmacies WHERE visible = ? AND latitude IS NOT NULL AND longitude IS NOT NULL', [1]);
        foreach ($rows as $r) {
            if (mb_strtolower((string) $r['district']) === mb_strtolower($district) && ($city === '' || $r['city'] === $city)) {
                $points[] = [(float) $r['latitude'], (float) $r['longitude']];
            }
        }
        if ($points === []) {
            return null;
        }
        return [array_sum(array_column($points, 0)) / count($points), array_sum(array_column($points, 1)) / count($points)];
    }

    /** Distance à vol d'oiseau (haversine). @param array{0: float, 1: float} $a @param array{0: float, 1: float} $b */
    private function metres(array $a, array $b): int
    {
        $lat = deg2rad($b[0] - $a[0]);
        $lng = deg2rad($b[1] - $a[1]);
        $h = sin($lat / 2) ** 2 + cos(deg2rad($a[0])) * cos(deg2rad($b[0])) * sin($lng / 2) ** 2;
        return (int) round(2 * 6371000 * asin(min(1.0, sqrt($h))));
    }
}
