<?php
declare(strict_types=1);

namespace Sanara\Modules\Requests;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/** T188 : présence des pharmaciens par signe de vie (toutes les 15 s depuis l'écran ; absent après 45 s). */
final class PresenceService
{
    public const TIMEOUT_SECONDS = 45;

    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly TenantContext $ctx, private readonly Clock $clock)
    {
    }

    public function beat(string $userId, bool $available): void
    {
        $now = $this->clock->stamp();
        $n = $this->tdb->update('presence', ['available' => $available ? 1 : 0, 'last_seen_at' => $now], ['user_id' => $userId]);
        if ($n === 0) {
            try {
                $this->tdb->insert('presence', ['id' => Ulid::generate(), 'user_id' => $userId, 'available' => $available ? 1 : 0, 'last_seen_at' => $now]);
            } catch (\PDOException) {
                // Deux onglets en même temps : la ligne existe déjà.
                $this->tdb->update('presence', ['available' => $available ? 1 : 0, 'last_seen_at' => $now], ['user_id' => $userId]);
            }
        }
    }

    /** Un pharmacien au moins est connecté et disponible. */
    public function anyoneOnline(): bool
    {
        foreach ($this->team() as $member) {
            if ($member['online']) {
                return true;
            }
        }
        return false;
    }

    /** @return list<array{user_id: string, email: string, role: string, online: bool, available: bool}> */
    public function team(): array
    {
        $since = $this->clock->stamp($this->clock->now()->modify('-' . self::TIMEOUT_SECONDS . ' seconds'));
        $seen = array_column($this->tdb->select('presence'), null, 'user_id');
        $out = [];
        $users = $this->db->all(
            "SELECT id, email, role_code FROM users WHERE tenant_id = ? AND role_code IN ('owner', 'pharmacist') AND status = 'active' ORDER BY email",
            [$this->ctx->require()],
        );
        foreach ($users as $u) {
            $p = $seen[$u['id']] ?? null;
            $available = $p !== null && (int) $p['available'] === 1;
            $out[] = [
                'user_id' => (string) $u['id'], 'email' => (string) $u['email'], 'role' => (string) $u['role_code'],
                'available' => $available, 'online' => $available && $p['last_seen_at'] >= $since,
            ];
        }
        return $out;
    }
}
