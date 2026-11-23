<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

use PDO;
use PDOStatement;

/** Mince couche autour de PDO. Seule porte d'entrée SQL (avec TenantDb et Migrator). Requêtes toujours préparées. */
final class Db
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @param list<mixed> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    /** @param list<mixed> $params @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @param list<mixed> $params @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $r = $this->run($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $r === false ? null : $r;
    }

    /** @param list<mixed> $params */
    public function execute(string $sql, array $params = []): int
    {
        return $this->run($sql, $params)->rowCount();
    }

    public function exec(string $sql): void
    {
        $this->pdo->exec($sql);
    }

    public function ping(): bool
    {
        return $this->one('SELECT 1 AS ok') !== null;
    }

    public function version(): string
    {
        return $this->driver() === 'mysql' ? (string) $this->one('SELECT VERSION() AS v')['v'] : 'sqlite';
    }

    /** @param callable():mixed $fn */
    public function transaction(callable $fn): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $r = $fn();
            $this->pdo->commit();
            return $r;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): void
    {
        Ident::check($table);
        $cols = array_keys($row);
        foreach ($cols as $c) {
            Ident::check($c);
        }
        $this->run(
            'INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
            array_values($row),
        );
    }
}
