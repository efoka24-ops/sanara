<?php
declare(strict_types=1);

namespace Sanara\Common\Crypto;

/** TOTP RFC 6238 (HMAC-SHA1, 6 chiffres, pas de 30 s), compatible Google Authenticator, Microsoft Authenticator, Authy. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, int $time): string
    {
        return self::hotp($secret, intdiv($time, 30));
    }

    /** @return int|null compteur accepté (à mémoriser pour refuser le rejeu), ou null */
    public static function verify(string $secret, string $code, int $time, int $window = 1, int $afterCounter = 0): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = intdiv($time, 30);
        for ($i = -$window; $i <= $window; $i++) {
            $c = $now + $i;
            if ($c > $afterCounter && hash_equals(self::hotp($secret, $c), $code)) {
                return $c;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    private static function hotp(string $secret, int $counter): string
    {
        $hash = hash_hmac('sha1', pack('J', $counter), self::base32Decode($secret), true);
        $o = ord($hash[19]) & 0x0f;
        $bin = ((ord($hash[$o]) & 0x7f) << 24) | (ord($hash[$o + 1]) << 16) | (ord($hash[$o + 2]) << 8) | ord($hash[$o + 3]);
        return str_pad((string) ($bin % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $ch) {
            $bits .= str_pad(decbin(ord($ch)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    private static function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split(strtoupper(rtrim($s, '='))) as $ch) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $ch)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr((int) bindec($byte));
            }
        }
        return $out;
    }
}
