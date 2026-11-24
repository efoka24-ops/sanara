<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

/** Applique les fichiers `database/migrations/*.sql` dans l'ordre, une seule fois. SQL portable MySQL/SQLite. */
final class Migrator
{
    public function __construct(private readonly Db $db, private readonly string $dir)
    {
    }

    /** @return list<string> migrations appliquées */
    public function migrate(): array
    {
        $this->db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name VARCHAR(190) NOT NULL PRIMARY KEY, applied_at VARCHAR(32) NOT NULL)');
        $done = array_column($this->db->all('SELECT name FROM schema_migrations'), 'name');
        $driver = $this->db->driver();
        // « nom.mysql.sql » et « nom.sqlite.sql » ne s'appliquent qu'à leur moteur (ex. index FULLTEXT).
        $files = array_filter(glob($this->dir . '/*.sql') ?: [], static function (string $f) use ($driver): bool {
            return preg_match('/\.(mysql|sqlite)\.sql$/', $f, $m) !== 1 || $m[1] === $driver;
        });
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach ($this->statements((string) file_get_contents($file)) as $sql) {
                try {
                    $this->db->exec($this->adapt($sql));
                } catch (\PDOException $e) {
                    // MySQL ne annule pas le DDL : une migration interrompue peut être reprise sans erreur sur ce qui existe déjà.
                    $again = stripos($sql, 'CREATE') === 0 && (str_contains($e->getMessage(), 'already exists') || str_contains($e->getMessage(), 'Duplicate key name'));
                    if (!$again) {
                        throw $e;
                    }
                }
            }
            $this->db->run('INSERT INTO schema_migrations (name, applied_at) VALUES (?, ?)', [$name, gmdate('Y-m-d H:i:s')]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** @return list<string> */
    private function statements(string $sql): array
    {
        return array_values(array_filter(array_map('trim', explode(";\n", str_replace("\r\n", "\n", $sql) . "\n")), static fn ($s) => $s !== ''));
    }

    private function adapt(string $sql): string
    {
        if ($this->db->driver() === 'mysql' && stripos($sql, 'CREATE TABLE') === 0) {
            return rtrim($sql, "; \n") . ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        }
        return $sql;
    }
}
