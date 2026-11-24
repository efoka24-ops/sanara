<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

use Sanara\Common\Clock;
use Sanara\Common\Ulid;

/** Utilisateurs (table à tenant_id facultatif : le super admin n'a pas de pharmacie). */
final class UserRepository
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $u */
    public function create(array $u): string
    {
        $id = Ulid::generate();
        $now = $this->clock->stamp();
        $this->db->insert('users', [
            'id' => $id, 'tenant_id' => $u['tenant_id'] ?? null, 'role_code' => $u['role_code'], 'email' => strtolower((string) $u['email']),
            'phone' => $u['phone'] ?? null, 'password_hash' => $u['password_hash'], 'status' => $u['status'] ?? 'active',
            'locale' => $u['locale'] ?? 'fr', 'failed_attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findByEmail(string $email): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [strtolower($email)]);
    }

    /** @return array<string, mixed>|null */
    public function findById(string $id): ?array
    {
        return $this->db->one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    /** @return bool vrai si ce échec vient de verrouiller le compte */
    public function recordFailure(string $id, int $maxAttempts, int $lockMinutes): bool
    {
        $this->db->execute('UPDATE users SET failed_attempts = failed_attempts + 1 WHERE id = ?', [$id]);
        $u = $this->findById($id);
        if ($u !== null && (int) $u['failed_attempts'] >= $maxAttempts) {
            $until = $this->clock->stamp($this->clock->now()->modify("+$lockMinutes minutes"));
            $this->db->execute('UPDATE users SET locked_until = ? WHERE id = ?', [$until, $id]);
            return (int) $u['failed_attempts'] === $maxAttempts;
        }
        return false;
    }

    public function resetFailures(string $id): void
    {
        $this->db->execute('UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE id = ?', [$id]);
    }

    /** Une pharmacie suspendue ne peut plus ouvrir de session. */
    public function tenantSuspended(?string $tenantId): bool
    {
        if ($tenantId === null || $tenantId === '') {
            return false;
        }
        $t = $this->db->one('SELECT status FROM tenants WHERE id = ?', [$tenantId]);
        return $t !== null && $t['status'] === 'suspended';
    }
}
