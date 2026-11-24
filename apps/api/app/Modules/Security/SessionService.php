<?php
declare(strict_types=1);

namespace Sanara\Modules\Security;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;

/** Sessions actives d'un utilisateur (une par famille de jetons) et révocation à distance. */
final class SessionService
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @return list<array{family_id: string, label: ?string, created_at: string}> */
    public function list(string $userId): array
    {
        return $this->db->all(
            'SELECT family_id, label, created_at FROM refresh_tokens WHERE user_id = ? AND revoked_at IS NULL AND expires_at > ? ORDER BY created_at DESC',
            [$userId, $this->clock->stamp()],
        );
    }

    public function revoke(string $userId, string $familyId): void
    {
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND family_id = ? AND revoked_at IS NULL', [$this->clock->stamp(), $userId, $familyId]);
    }

    public function revokeAll(string $userId): void
    {
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$this->clock->stamp(), $userId]);
    }
}
