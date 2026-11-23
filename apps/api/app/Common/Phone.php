<?php
declare(strict_types=1);

namespace Sanara\Common;

final class Phone
{
    /** Normalise vers +<indicatif><national> ; null si invalide. Cameroun : 9 chiffres commençant par 6 ou 2 (fixe). */
    public static function normalizeCm(string $input): ?string
    {
        $d = preg_replace('/\D+/', '', $input) ?? '';
        if (str_starts_with($d, '237') && strlen($d) === 12) {
            $d = substr($d, 3);
        }
        return preg_match('/^[26]\d{8}$/', $d) === 1 ? '+237' . $d : null;
    }
}
