<?php
declare(strict_types=1);

namespace Sanara\Common\Tenancy;

use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Ident;

/**
 * Accès aux tables d'une pharmacie. Ajoute tenant_id à chaque lecture, écriture, mise à jour et suppression.
 * Le filtre tenant_id ne peut pas être contourné : il est appliqué en dernier et écrase toute valeur fournie.
 */
final class TenantDb
{
    public function __construct(private readonly Db $db, private readonly TenantContext $ctx)
    {
    }

    /** @param array<string, mixed> $where @return list<array<string, mixed>> */
    public function select(string $table, array $where = [], ?string $orderBy = null, ?int $limit = null): array
    {
        [$sql, $params] = $this->where($table, $where);
        $q = 'SELECT * FROM ' . $table . $sql;
        if ($orderBy !== null) {
            $q .= ' ORDER BY ' . Ident::check($orderBy);
        }
        if ($limit !== null) {
            $q .= ' LIMIT ' . (int) $limit;
        }
        return $this->db->all($q, $params);
    }

    /**
     * Recherche paginée : égalités + groupe de LIKE combinés en OU. Le filtre tenant_id reste imposé.
     *
     * @param array<string, mixed> $where
     * @param array<string, string> $likes colonne => motif déjà échappé
     * @return list<array<string, mixed>>
     */
    public function query(string $table, array $where = [], array $likes = [], ?string $orderBy = null, int $limit = 50, int $offset = 0): array
    {
        [$sql, $params] = $this->where($table, $where);
        if ($likes !== []) {
            $parts = [];
            foreach ($likes as $col => $pattern) {
                $parts[] = Ident::check((string) $col) . " LIKE ? ESCAPE '!'";
                $params[] = $pattern;
            }
            $sql .= ' AND (' . implode(' OR ', $parts) . ')';
        }
        $q = 'SELECT * FROM ' . $table . $sql;
        if ($orderBy !== null) {
            $q .= ' ORDER BY ' . Ident::check($orderBy);
        }
        return $this->db->all($q . ' LIMIT ' . max(1, min(200, $limit)) . ' OFFSET ' . max(0, $offset), $params);
    }

    /** @param array<string, mixed> $row */
    public function insert(string $table, array $row): void
    {
        $this->guard($table);
        $tid = $this->ctx->require();
        if (isset($row['tenant_id']) && $row['tenant_id'] !== $tid) {
            throw new \InvalidArgumentException('tenant_id différent de la pharmacie courante');
        }
        $row['tenant_id'] = $tid;
        $this->db->insert($table, $row);
    }

    /** @param array<string, mixed> $set @param array<string, mixed> $where */
    public function update(string $table, array $set, array $where): int
    {
        if (array_key_exists('tenant_id', $set)) {
            throw new \InvalidArgumentException('tenant_id non modifiable');
        }
        [$sql, $params] = $this->where($table, $where);
        $cols = [];
        foreach (array_keys($set) as $c) {
            $cols[] = Ident::check($c) . ' = ?';
        }
        return $this->db->execute('UPDATE ' . $table . ' SET ' . implode(', ', $cols) . $sql, [...array_values($set), ...$params]);
    }

    /** @param array<string, mixed> $where */
    public function delete(string $table, array $where): int
    {
        [$sql, $params] = $this->where($table, $where);
        return $this->db->execute('DELETE FROM ' . $table . $sql, $params);
    }

    private function guard(string $table): void
    {
        Ident::check($table);
        if (!TenantTables::isScoped($table)) {
            throw new \InvalidArgumentException("Table non filtrée par tenant : $table");
        }
    }

    /** @param array<string, mixed> $where @return array{0: string, 1: list<mixed>} */
    private function where(string $table, array $where): array
    {
        $this->guard($table);
        $tid = $this->ctx->require();
        unset($where['tenant_id']);
        $parts = ['tenant_id = ?'];
        $params = [$tid];
        foreach ($where as $col => $val) {
            Ident::check((string) $col);
            if ($val === null) {
                $parts[] = $col . ' IS NULL';
            } else {
                $parts[] = $col . ' = ?';
                $params[] = $val;
            }
        }
        return [' WHERE ' . implode(' AND ', $parts), $params];
    }
}
