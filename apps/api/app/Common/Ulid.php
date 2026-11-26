<?php
declare(strict_types=1);

namespace Sanara\Common;

/** ULID (26 car. Crockford base32), triable dans le temps ; compatible avec packages/shared/src/ids. */
final class Ulid
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function isValid(string $id): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $id) === 1;
    }

    public static function generate(?int $millis = null): string
    {
        $t = $millis ?? (int) floor(microtime(true) * 1000);
        $time = '';
        for ($i = 0; $i < 10; $i++) {
            $time = self::ALPHABET[$t % 32] . $time;
            $t = intdiv($t, 32);
        }
        $rand = '';
        $bytes = random_bytes(16);
        for ($i = 0; $i < 16; $i++) {
            $rand .= self::ALPHABET[ord($bytes[$i]) & 31];
        }
        return $time . $rand;
    }
}
