<?php
declare(strict_types=1);

namespace Sanara\Common\Auth;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/** Jetons de rafraîchissement opaques, hachés (SHA-256) en base, rotatifs avec détection de rejeu. */
final class RefreshTokenService
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly int $ttlDays = 30)
    {
    }

    /** @return array{token: string, user_id: string} */
    public function issue(string $userId, ?string $deviceId = null, ?string $familyId = null): array
    {
        $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $id = Ulid::generate();
        $this->db->insert('refresh_tokens', [
            'id' => $id, 'user_id' => $userId, 'device_id' => $deviceId, 'family_id' => $familyId ?? Ulid::generate(),
            'token_hash' => hash('sha256', $token),
            'expires_at' => $this->clock->stamp($this->clock->now()->modify("+{$this->ttlDays} days")),
            'created_at' => $this->clock->stamp(),
        ]);
        return ['token' => $token, 'user_id' => $userId];
    }

    /** @return array{token: string, user_id: string} */
    public function rotate(string $token): array
    {
        $row = $this->db->one('SELECT * FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($row === null) {
            throw new AuthException('invalid_refresh_token');
        }
        $now = $this->clock->stamp();
        if ($row['revoked_at'] !== null) {
            $this->revokeFamily((string) $row['family_id']); // rejeu : on coupe toute la chaîne
            throw new AuthException('invalid_refresh_token');
        }
        if ($row['expires_at'] <= $now) {
            throw new AuthException('invalid_refresh_token');
        }
        $new = $this->issue((string) $row['user_id'], $row['device_id'], (string) $row['family_id']);
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE id = ?', [$now, $row['id']]);
        return $new;
    }

    public function revokeFamily(string $familyId): void
    {
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE family_id = ? AND revoked_at IS NULL', [$this->clock->stamp(), $familyId]);
    }

    public function revokeToken(string $token): void
    {
        $row = $this->db->one('SELECT family_id FROM refresh_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        if ($row !== null) {
            $this->revokeFamily((string) $row['family_id']);
        }
    }
}
