<?php
declare(strict_types=1);

namespace Sanara\Modules\Security;

use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;

/** PIN à 4 chiffres pour déverrouiller l'écran de caisse après inactivité. Verrouillé après 5 échecs. */
final class PinService
{
    private const MAX = 5;

    public function __construct(private readonly Db $db, private readonly PasswordHasher $hasher)
    {
    }

    public function set(string $userId, string $pin): void
    {
        if (!preg_match('/^\d{4}$/', $pin)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['pin' => 'format']]);
        }
        $this->db->execute('UPDATE users SET pin_hash = ?, pin_failures = 0 WHERE id = ?', [$this->hasher->hash($pin), $userId]);
    }

    public function verify(string $userId, string $pin): bool
    {
        $u = $this->db->one('SELECT pin_hash, pin_failures FROM users WHERE id = ?', [$userId]);
        if ($u === null || $u['pin_hash'] === null) {
            return false;
        }
        if ((int) $u['pin_failures'] >= self::MAX) {
            throw new ApiException('account_locked', 423);
        }
        if ($this->hasher->verify($pin, (string) $u['pin_hash'])) {
            $this->db->execute('UPDATE users SET pin_failures = 0 WHERE id = ?', [$userId]);
            return true;
        }
        $this->db->execute('UPDATE users SET pin_failures = pin_failures + 1 WHERE id = ?', [$userId]);
        return false;
    }
}
