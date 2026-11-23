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
}
