<?php
declare(strict_types=1);

namespace Sanara\Tests\Support;

use PDO;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Migrator;

final class TestDb
{
    public static function create(): Db
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $db = new Db($pdo);
        (new Migrator($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        self::enforceLengths($db);
        return $db;
    }

    /**
     * SQLite ignore la longueur des VARCHAR(n) et CHAR(n), pas MySQL (« Data too long »,
     * erreur 500 en préproduction). Des déclencheurs la font respecter pendant les tests.
     */
    private static function enforceLengths(Db $db): void
    {
        // Les élargissements propres à MySQL (*.mysql.sql) priment sur la longueur déclarée à la création.
        $widened = [];
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.mysql.sql') ?: [] as $file) {
            preg_match_all('/ALTER TABLE (\w+) MODIFY (\w+) VARCHAR\((\d+)\)/i', (string) file_get_contents($file), $found, PREG_SET_ORDER);
            foreach ($found as [, $table, $column, $length]) {
                $widened["$table.$column"] = (int) $length;
            }
        }
        foreach ($db->all("SELECT name FROM sqlite_master WHERE type = 'table'") as $table) {
            $t = $table['name'];
            foreach ($db->all("PRAGMA table_info($t)") as $column) {
                if (preg_match('/^(?:VAR)?CHAR\((\d+)\)$/i', (string) $column['type'], $m) !== 1) {
                    continue;
                }
                $c = $column['name'];
                $max = $widened["$t.$c"] ?? (int) $m[1];
                foreach (['INSERT' => 'INSERT', 'UPDATE' => "UPDATE OF $c"] as $key => $event) {
                    $db->exec("CREATE TRIGGER len_{$t}_{$c}_{$key} BEFORE $event ON $t WHEN length(NEW.$c) > $max
                        BEGIN SELECT RAISE(ABORT, 'Data too long for column $t.$c ($max)'); END");
                }
            }
        }
    }
}
