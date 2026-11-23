<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Audit\AuditLogger;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;

/** US3 : activation du compte propriétaire par lien à usage unique et durée limitée. */
final class ActivationService
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly PasswordHasher $hasher)
    {
    }

    public function isUsable(string $token): bool
    {
        return $this->find($token) !== null;
    }

    public function activate(string $token, string $password): void
    {
        if (!$this->hasher->meetsPolicy($password)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['password' => 'policy']]);
        }
        $t = $this->find($token) ?? throw new ApiException('invalid_token', 401);
        $now = $this->clock->stamp();
        $this->db->transaction(function () use ($t, $password, $now): void {
            $won = $this->db->execute('UPDATE activation_tokens SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, $t['id']]);
            if ($won !== 1) {
                throw new ApiException('invalid_token', 401);
            }
            $this->db->execute(
                "UPDATE users SET password_hash = ?, status = 'active', failed_attempts = 0, updated_at = ? WHERE id = ? AND status = 'pending_activation'",
                [$this->hasher->hash($password), $now, $t['user_id']],
            );
            $u = $this->db->one('SELECT tenant_id FROM users WHERE id = ?', [$t['user_id']]);
            (new AuditLogger($this->db, $this->clock))->record('user.activate', ['user_id' => (string) $t['user_id'], 'tenant_id' => $u['tenant_id'] ?? null, 'entity' => 'user', 'entity_id' => (string) $t['user_id']]);
        });
    }

    /** @return array<string, mixed>|null */
    private function find(string $token): ?array
    {
        return $this->db->one(
            'SELECT * FROM activation_tokens WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), $this->clock->stamp()],
        );
    }
}
