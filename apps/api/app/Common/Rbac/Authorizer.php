<?php
declare(strict_types=1);

namespace Sanara\Common\Rbac;

use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/** Permissions effectives = permissions du rôle, plus les accords et moins les retraits propres à l'utilisateur. */
final class Authorizer
{
    public function __construct(private readonly Db $db)
    {
    }

    /** @return list<string> */
    public function permissionsFor(string $userId): array
    {
        $u = $this->db->one('SELECT role_code FROM users WHERE id = ? AND deleted_at IS NULL', [$userId]);
        if ($u === null) {
            return [];
        }
        $set = array_column($this->db->all('SELECT permission_code FROM role_permissions WHERE role_code = ?', [$u['role_code']]), 'permission_code');
        foreach ($this->db->all('SELECT permission_code, granted FROM user_permission_overrides WHERE user_id = ?', [$userId]) as $o) {
            $set = (int) $o['granted'] === 1 ? [...$set, $o['permission_code']] : array_diff($set, [$o['permission_code']]);
        }
        return array_values(array_unique($set));
    }

    public function can(string $userId, string $permission): bool
    {
        return in_array($permission, $this->permissionsFor($userId), true);
    }

    public function override(string $userId, string $permission, bool $granted): void
    {
        $this->db->execute('DELETE FROM user_permission_overrides WHERE user_id = ? AND permission_code = ?', [$userId, $permission]);
        $this->db->insert('user_permission_overrides', ['id' => Ulid::generate(), 'user_id' => $userId, 'permission_code' => $permission, 'granted' => $granted ? 1 : 0]);
    }
}
