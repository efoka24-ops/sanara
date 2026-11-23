<?php
declare(strict_types=1);

namespace Sanara\Common\Jobs;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/** File de jobs en base (pas de Redis sur Camoo). Réservation atomique par verrou à durée limitée : un worker mort libère son job. */
final class JobQueue
{
    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly int $lockSeconds = 120,
        private readonly int $maxAttempts = 5,
    ) {
    }

    /** @param array<string, mixed> $payload */
    public function push(string $queue, array $payload, int $delaySeconds = 0): string
    {
        $id = Ulid::generate();
        $this->db->insert('jobs', [
            'id' => $id, 'queue' => $queue, 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'attempts' => 0,
            'max_attempts' => $this->maxAttempts, 'status' => 'pending',
            'available_at' => $this->clock->stamp($this->clock->now()->modify("+$delaySeconds seconds")),
            'created_at' => $this->clock->stamp(),
        ]);
        return $id;
    }

    /** @return array{id: string, payload: array<string, mixed>, attempts: int}|null */
    public function reserve(string $queue): ?array
    {
        $now = $this->clock->stamp();
        $candidates = $this->db->all(
            "SELECT id FROM jobs WHERE queue = ? AND status IN ('pending','running') AND available_at <= ? AND (locked_until IS NULL OR locked_until <= ?) ORDER BY available_at LIMIT 5",
            [$queue, $now, $now],
        );
        foreach ($candidates as $c) {
            $until = $this->clock->stamp($this->clock->now()->modify("+{$this->lockSeconds} seconds"));
            $won = $this->db->execute(
                "UPDATE jobs SET status = 'running', locked_until = ?, attempts = attempts + 1 WHERE id = ? AND (locked_until IS NULL OR locked_until <= ?) AND status IN ('pending','running')",
                [$until, $c['id'], $now],
            );
            if ($won === 1) {
                $row = $this->db->one('SELECT * FROM jobs WHERE id = ?', [$c['id']]);
                return ['id' => (string) $row['id'], 'payload' => json_decode((string) $row['payload'], true) ?? [], 'attempts' => (int) $row['attempts']];
            }
        }
        return null;
    }

    public function ack(string $id): void
    {
        $this->db->execute("UPDATE jobs SET status = 'done', locked_until = NULL WHERE id = ?", [$id]);
    }

    public function fail(string $id, string $error): void
    {
        $job = $this->db->one('SELECT attempts, max_attempts FROM jobs WHERE id = ?', [$id]);
        if ($job === null) {
            return;
        }
        if ((int) $job['attempts'] >= (int) $job['max_attempts']) {
            $this->db->execute("UPDATE jobs SET status = 'failed', locked_until = NULL, last_error = ? WHERE id = ?", [mb_substr($error, 0, 500), $id]);
            return;
        }
        $backoff = 60 * (2 ** ((int) $job['attempts'] - 1)) * 5; // 5, 10, 20 min…
        $next = $this->clock->stamp($this->clock->now()->modify("+$backoff seconds"));
        $this->db->execute("UPDATE jobs SET status = 'pending', locked_until = NULL, available_at = ?, last_error = ? WHERE id = ?", [$next, mb_substr($error, 0, 500), $id]);
    }
}
