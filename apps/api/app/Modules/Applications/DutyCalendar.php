<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use DateTimeImmutable;
use DateTimeZone;

/** US19 : périodes de garde, enregistrées en UTC et lues à l'heure locale du pays. */
final class DutyCalendar
{
    public const PERIODS = ['tonight', 'week', 'weekend'];

    /**
     * Fenêtre demandée sur la page de garde, en UTC.
     *
     * @return array{0: string, 1: string} début et fin au format de la base
     */
    public static function window(string $period, DateTimeImmutable $now, string $timezone): array
    {
        $local = $now->setTimezone(new DateTimeZone($timezone));
        if ($period === 'week') {
            [$from, $to] = [$local, $local->modify('+7 days')];
        } elseif ($period === 'weekend') {
            $saturday = (int) $local->format('N') === 7 ? $local->modify('-1 day') : $local->modify('+' . (6 - (int) $local->format('N')) . ' days');
            [$from, $to] = [max($local, $saturday->setTime(0, 0)), $saturday->setTime(0, 0)->modify('+2 days')];
        } elseif ((int) $local->format('H') < 8) {
            // Avant 8 h, « ce soir » désigne la nuit en cours.
            [$from, $to] = [$local, $local->setTime(8, 0)];
        } else {
            [$from, $to] = [max($local, $local->setTime(18, 0)), $local->modify('+1 day')->setTime(8, 0)];
        }
        return [self::utc($from), self::utc($to)];
    }

    /**
     * Première période pas encore terminée, qui recoupe la fenêtre si elle est donnée.
     *
     * @param list<array{0: string, 1: string}> $periods triées par début, en UTC
     * @param array{0: string, 1: string}|null  $window
     * @return array{starts: string, ends: string, now: bool}|null heures locales « 2026-11-27T20:00 »
     */
    public static function next(array $periods, DateTimeImmutable $now, string $timezone, ?array $window = null): ?array
    {
        $current = self::utc($now);
        foreach ($periods as [$starts, $ends]) {
            if ($ends > $current && ($window === null || ($starts < $window[1] && $ends > $window[0]))) {
                return ['starts' => self::local($starts, $timezone), 'ends' => self::local($ends, $timezone), 'now' => $starts <= $current];
            }
        }
        return null;
    }

    public static function local(string $utc, string $timezone): string
    {
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($timezone))->format('Y-m-d\TH:i');
    }

    public static function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.v');
    }
}
