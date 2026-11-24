<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

use Sanara\Common\Rbac\DefaultRoles;

/** Données de référence idempotentes (T019). */
final class Seeder
{
    public static function roles(Db $db): void
    {
        foreach (DefaultRoles::PERMISSIONS as $code => $desc) {
            if ($db->one('SELECT code FROM permissions WHERE code = ?', [$code]) === null) {
                $db->insert('permissions', ['code' => $code, 'description' => $desc]);
            }
        }
        foreach (DefaultRoles::roles() as $code => $r) {
            if ($db->one('SELECT code FROM roles WHERE code = ?', [$code]) === null) {
                $db->insert('roles', ['code' => $code, 'name' => $r['name'], 'scope' => $r['scope']]);
            }
            foreach ($r['permissions'] as $p) {
                if ($db->one('SELECT role_code FROM role_permissions WHERE role_code = ? AND permission_code = ?', [$code, $p]) === null) {
                    $db->insert('role_permissions', ['role_code' => $code, 'permission_code' => $p]);
                }
            }
        }
    }

    public static function cameroon(Db $db): void
    {
        if ($db->one('SELECT code FROM countries WHERE code = ?', ['CM']) === null) {
            $db->insert('countries', [
                'code' => 'CM', 'name_fr' => 'Cameroun', 'name_en' => 'Cameroon', 'currency' => 'XAF', 'minor_unit' => 0,
                'timezone' => 'Africa/Douala', 'default_locale' => 'fr', 'dial_code' => '+237', 'status' => 'active',
            ]);
        }
    }

    /** Configuration du Cameroun (autorité, pièces exigées, conservation) et formules de départ, modifiables ensuite par le super admin. */
    public static function platformDefaults(Db $db): void
    {
        $cfg = [
            'authority' => 'Ordre National des Pharmaciens du Cameroun',
            'required_documents' => ['Agrément de l’officine', 'Diplôme de pharmacien', 'Carte de l’Ordre', 'Pièce d’identité'],
            'retention_years' => 10,
        ];
        foreach ($cfg as $k => $v) {
            if ($db->one('SELECT id FROM country_configs WHERE country_code = ? AND config_key = ?', ['CM', $k]) === null) {
                $db->insert('country_configs', ['id' => \Sanara\Common\Ulid::generate(), 'country_code' => 'CM', 'config_key' => $k, 'config_value' => json_encode($v, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $now = gmdate('Y-m-d H:i:s') . '.000';
        foreach ([['essentiel', 'Essentiel', 15000, 1, 'Caisse, stock, catalogue'], ['standard', 'Standard', 35000, 3, 'Essentiel + postes multiples, achats, rapports'], ['pro', 'Pro', 75000, 10, 'Standard + assistant d’ordonnances et site public']] as [$code, $name, $price, $stations, $features]) {
            if ($db->one('SELECT id FROM plans WHERE code = ?', [$code]) === null) {
                $db->insert('plans', ['id' => \Sanara\Common\Ulid::generate(), 'code' => $code, 'name' => $name, 'price_month' => $price, 'max_stations' => $stations, 'features' => $features, 'active' => 1, 'created_at' => $now]);
            }
        }
    }
}
