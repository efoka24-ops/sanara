<?php
declare(strict_types=1);

namespace Sanara\Common\RateLimit;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;

/** Limiteur à fenêtre fixe, stocké en base (clé = IP ou identifiant). */
final class RateLimiter
{
    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @return array{allowed: bool, retryAfter: int} */
    public function hit(string $key, int $limit, int $windowSeconds): array
    {
        $ts = $this->clock->now()->getTimestamp();
        $window = intdiv($ts, $windowSeconds) * $windowSeconds;
        $this->db->execute('DELETE FROM rate_limits WHERE window_start < ?', [$ts - 86400]);
        $row = $this->db->one('SELECT hits FROM rate_limits WHERE rkey = ? AND window_start = ?', [$key, $window]);
        if ($row === null) {
            $this->db->insert('rate_limits', ['rkey' => $key, 'window_start' => $window, 'hits' => 1]);
            return ['allowed' => true, 'retryAfter' => 0];
        }
        if ((int) $row['hits'] >= $limit) {
            return ['allowed' => false, 'retryAfter' => $window + $windowSeconds - $ts];
        }
        $this->db->execute('UPDATE rate_limits SET hits = hits + 1 WHERE rkey = ? AND window_start = ?', [$key, $window]);
        return ['allowed' => true, 'retryAfter' => 0];
    }
}
