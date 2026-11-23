<?php
declare(strict_types=1);

namespace Sanara\Common;

use DateTimeImmutable;
use DateTimeZone;

/** Horloge injectable (UTC). `Clock::fixed()` pour les tests. */
class Clock
{
    private ?DateTimeImmutable $fixed = null;

    public static function fixed(string $iso): self
    {
        $c = new self();
        $c->fixed = new DateTimeImmutable($iso, new DateTimeZone('UTC'));
        return $c;
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function advance(string $modify): void
    {
        $this->fixed = $this->now()->modify($modify);
    }

    /** Format stocké en base : UTC, millisecondes. */
    public function stamp(?DateTimeImmutable $d = null): string
    {
        return ($d ?? $this->now())->format('Y-m-d H:i:s.v');
    }
}
