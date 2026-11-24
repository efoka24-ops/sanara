<?php
declare(strict_types=1);

namespace Sanara\Modules\Security;

use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/** Réinitialisation : lien valable 1 h à usage unique, historique de 5 mots de passe, sessions révoquées. */
final class PasswordResetService
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly PasswordHasher $hasher, private readonly JobQueue $jobs, private readonly string $appUrl)
    {
    }

    public function request(string $email): void
    {
        $u = $this->db->one("SELECT id, email, locale FROM users WHERE email = ? AND status = 'active' AND deleted_at IS NULL", [strtolower(trim($email))]);
        if ($u === null) {
            return; // aucune différence visible pour l'appelant
        }
        $this->db->execute('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [$this->clock->stamp(), $u['id']]);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->db->insert('password_resets', [
            'id' => Ulid::generate(), 'user_id' => $u['id'], 'token_hash' => hash('sha256', $token),
            'expires_at' => $this->clock->stamp($this->clock->now()->modify('+1 hour')), 'created_at' => $this->clock->stamp(),
        ]);
        $this->jobs->push('mail', ['template' => 'password_reset', 'to' => $u['email'], 'locale' => $u['locale'] === 'en' ? 'en' : 'fr', 'vars' => ['lien' => rtrim($this->appUrl, '/') . '/reinitialiser/' . $token]]);
    }

    public function isUsable(string $token): bool
    {
        return $this->find($token) !== null;
    }

    public function reset(string $token, string $newPassword): void
    {
        $r = $this->find($token) ?? throw new ApiException('invalid_token', 401);
        if (!$this->hasher->meetsPolicy($newPassword)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['password' => 'policy']]);
        }
        $uid = (string) $r['user_id'];
        $current = (string) $this->db->one('SELECT password_hash FROM users WHERE id = ?', [$uid])['password_hash'];
        $past = array_column($this->db->all('SELECT password_hash FROM password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$uid]), 'password_hash');
        foreach ([$current, ...$past] as $old) {
            if ($this->hasher->verify($newPassword, $old)) {
                throw new ApiException('validation_failed', 422, ['fields' => ['password' => 'reused']]);
            }
        }
        $now = $this->clock->stamp();
        $this->db->transaction(function () use ($r, $uid, $newPassword, $current, $now): void {
            if ($this->db->execute('UPDATE password_resets SET used_at = ? WHERE id = ? AND used_at IS NULL', [$now, $r['id']]) !== 1) {
                throw new ApiException('invalid_token', 401);
            }
            $this->db->insert('password_history', ['id' => Ulid::generate(), 'user_id' => $uid, 'password_hash' => $current, 'created_at' => $now]);
            $this->db->execute('UPDATE users SET password_hash = ?, failed_attempts = 0, locked_until = NULL, updated_at = ? WHERE id = ?', [$this->hasher->hash($newPassword), $now, $uid]);
            $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$now, $uid]);
        });
    }

    /** @return array<string, mixed>|null */
    private function find(string $token): ?array
    {
        return $this->db->one('SELECT * FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), $this->clock->stamp()]);
    }
}
