<?php
declare(strict_types=1);

namespace Sanara\Modules\Security;

use Sanara\Common\Clock;
use Sanara\Common\Crypto\SecretBox;
use Sanara\Common\Crypto\Totp;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;

/** Double vérification TOTP : secret chiffré, codes de secours hachés et à usage unique, refus du rejeu. */
final class MfaService
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly SecretBox $box, private readonly string $issuer)
    {
    }

    public function isEnabled(string $userId): bool
    {
        return (int) ($this->db->one('SELECT totp_enabled FROM users WHERE id = ?', [$userId])['totp_enabled'] ?? 0) === 1;
    }

    /** @return array{secret: string, uri: string} */
    public function begin(string $userId): array
    {
        $u = $this->db->one('SELECT email, totp_enabled FROM users WHERE id = ?', [$userId]) ?? throw new ApiException('not_found', 404);
        if ((int) $u['totp_enabled'] === 1) {
            throw new ApiException('conflict', 409);
        }
        $secret = Totp::generateSecret();
        $this->db->execute('UPDATE users SET totp_secret_enc = ?, totp_last_counter = 0 WHERE id = ?', [$this->box->encrypt($secret), $userId]);
        return ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $u['email'], $this->issuer)];
    }

    /** @return list<string> codes de secours en clair, affichés une seule fois */
    public function confirm(string $userId, string $code): array
    {
        $secret = $this->secret($userId) ?? throw new ApiException('conflict', 409);
        $counter = Totp::verify($secret, $code, $this->clock->now()->getTimestamp());
        if ($counter === null) {
            throw new ApiException('validation_failed', 422, ['fields' => ['code' => 'invalid']]);
        }
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < 8; $i++) {
            $c = strtolower(bin2hex(random_bytes(2)) . '-' . bin2hex(random_bytes(2)));
            $codes[] = $c;
            $hashes[] = hash('sha256', $c);
        }
        $this->db->execute('UPDATE users SET totp_enabled = 1, totp_last_counter = ?, totp_backup_codes = ? WHERE id = ?', [$counter, json_encode($hashes), $userId]);
        return $codes;
    }

    /** Vérifie un code TOTP (sans rejeu) ou un code de secours (consommé). */
    public function verifyCode(string $userId, string $code): bool
    {
        $u = $this->db->one('SELECT totp_enabled, totp_last_counter, totp_backup_codes FROM users WHERE id = ?', [$userId]);
        if ($u === null || (int) $u['totp_enabled'] !== 1) {
            return false;
        }
        $code = strtolower(trim($code));
        if (preg_match('/^\d{6}$/', $code)) {
            $secret = $this->secret($userId);
            $counter = $secret === null ? null : Totp::verify($secret, $code, $this->clock->now()->getTimestamp(), 1, (int) $u['totp_last_counter']);
            if ($counter === null) {
                return false;
            }
            $this->db->execute('UPDATE users SET totp_last_counter = ? WHERE id = ? AND totp_last_counter < ?', [$counter, $userId, $counter]);
            return true;
        }
        $hashes = json_decode((string) $u['totp_backup_codes'], true) ?: [];
        $h = hash('sha256', $code);
        if (!in_array($h, $hashes, true)) {
            return false;
        }
        $this->db->execute('UPDATE users SET totp_backup_codes = ? WHERE id = ?', [json_encode(array_values(array_diff($hashes, [$h]))), $userId]);
        return true;
    }

    public function disable(string $userId, string $code): void
    {
        if (!$this->verifyCode($userId, $code)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['code' => 'invalid']]);
        }
        $this->db->execute('UPDATE users SET totp_enabled = 0, totp_secret_enc = NULL, totp_backup_codes = NULL WHERE id = ?', [$userId]);
    }

    private function secret(string $userId): ?string
    {
        $enc = $this->db->one('SELECT totp_secret_enc FROM users WHERE id = ?', [$userId])['totp_secret_enc'] ?? null;
        return $enc === null ? null : $this->box->decrypt((string) $enc);
    }
}
