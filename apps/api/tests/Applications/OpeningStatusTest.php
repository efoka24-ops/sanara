<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;
use Sanara\Modules\Applications\OpeningStatus;

/** T155 : ouvert ou fermé selon l'heure locale du pays, et prochaine ouverture. */
final class OpeningStatusTest extends TestCase
{
    /** Lundi à vendredi 8 h - 18 h, samedi 9 h - 13 h, dimanche fermé. */
    private const WEEK = [[1, '08:00', '18:00'], [2, '08:00', '18:00'], [3, '08:00', '18:00'], [4, '08:00', '18:00'], [5, '08:00', '18:00'], [6, '09:00', '13:00']];

    private function at(string $utc, ?array $week = self::WEEK, string $zone = 'Africa/Douala'): array
    {
        return OpeningStatus::at($week, new DateTimeImmutable($utc, new DateTimeZone('UTC')), $zone);
    }

    public function testOpeningAndClosingFollowTheLocalClock(): void
    {
        // Douala est à UTC+1 : 07:00 UTC = 08:00 sur place.
        $this->assertSame(['open' => true, 'closes' => '18:00', 'next_opening' => null], $this->at('2026-11-27 07:00'));
        $this->assertSame(['open' => false, 'closes' => null, 'next_opening' => ['weekday' => 5, 'opens' => '08:00', 'in_days' => 0]], $this->at('2026-11-27 06:59'));
        $this->assertFalse($this->at('2026-11-27 17:00')['open'], 'fermée à l’heure de fermeture');
        $this->assertTrue($this->at('2026-11-27 16:59')['open']);
        $this->assertFalse($this->at('2026-11-27 07:00', self::WEEK, 'UTC')['open']);
    }

    public function testNextOpeningSkipsClosedDaysAndCrossesTheWeek(): void
    {
        // Samedi après-midi : dimanche fermé, réouverture lundi.
        $this->assertSame(['weekday' => 1, 'opens' => '08:00', 'in_days' => 2], $this->at('2026-11-28 14:00')['next_opening']);
        // Un seul jour d'ouverture : la semaine suivante.
        $this->assertSame(['weekday' => 5, 'opens' => '08:00', 'in_days' => 7], $this->at('2026-11-27 20:00', [[5, '08:00', '18:00']])['next_opening']);
        // La date locale fait foi : vendredi 23:30 UTC est déjà samedi à Douala.
        $this->assertSame(['weekday' => 6, 'opens' => '09:00', 'in_days' => 0], $this->at('2026-11-27 23:30')['next_opening']);
    }

    public function testUnknownHoursAreNotReportedAsClosed(): void
    {
        $unknown = ['open' => null, 'closes' => null, 'next_opening' => null];
        $this->assertSame($unknown, $this->at('2026-11-27 10:00', null));
        $this->assertSame(['open' => false, 'closes' => null, 'next_opening' => null], $this->at('2026-11-27 10:00', []), 'fermée tous les jours');
    }
}
