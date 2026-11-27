<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use DateTimeImmutable;
use DateTimeZone;

/** T155 : statut d'ouverture à l'heure locale du pays (jours ISO : 1 = lundi). */
final class OpeningStatus
{
    /**
     * @param list<array{0: int, 1: string, 2: string}>|null $week jours ouverts [jour, ouverture, fermeture] ;
     *                                                             null si la pharmacie n'a pas donné ses horaires
     * @return array{open: ?bool, closes: ?string, next_opening: ?array{weekday: int, opens: string, in_days: int}}
     */
    public static function at(?array $week, DateTimeImmutable $now, string $timezone): array
    {
        if ($week === null) {
            return ['open' => null, 'closes' => null, 'next_opening' => null];
        }
        $local = $now->setTimezone(new DateTimeZone($timezone));
        $today = (int) $local->format('N');
        $time = $local->format('H:i');
        $days = [];
        foreach ($week as [$weekday, $opens, $closes]) {
            $days[$weekday] = [$opens, $closes];
        }
        if (isset($days[$today]) && $days[$today][0] <= $time && $time < $days[$today][1]) {
            return ['open' => true, 'closes' => $days[$today][1], 'next_opening' => null];
        }
        for ($in = 0; $in <= 7; $in++) {
            $weekday = ($today - 1 + $in) % 7 + 1;
            if (isset($days[$weekday]) && ($in > 0 || $days[$weekday][0] > $time)) {
                return ['open' => false, 'closes' => null, 'next_opening' => ['weekday' => $weekday, 'opens' => $days[$weekday][0], 'in_days' => $in]];
            }
        }
        return ['open' => false, 'closes' => null, 'next_opening' => null];
    }
}
