<?php
declare(strict_types=1);

namespace Sanara\Tests\Isolation;

use PHPUnit\Framework\TestCase;

/** T017 : aucune requête SQL brute hors des couches d'accès autorisées. */
final class NoRawSqlTest extends TestCase
{
    private const ALLOWED = ['Common/Persistence/', 'Common/Tenancy/', 'Common/Database.php'];

    public function testNoRawSqlOutsideAccessLayers(): void
    {
        $root = dirname(__DIR__, 2) . '/app';
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        $bad = [];
        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }
            $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
            foreach (self::ALLOWED as $ok) {
                if (str_starts_with($rel, $ok)) {
                    continue 2;
                }
            }
            // TenantDb::query() (recherche filtrée par tenant) est autorisé ; on refuse query() avec du SQL littéral, prepare() et exec().
            $raw = '/->\s*(prepare|exec)\s*\(|->\s*query\s*\(\s*[\'"]\s*(SELECT|INSERT|UPDATE|DELETE|WITH|SET|PRAGMA)\b|Database::pdo\(\)/i';
            if (preg_match($raw, (string) file_get_contents($f->getPathname()))) {
                $bad[] = $rel;
            }
        }
        $this->assertSame([], $bad, 'SQL brut interdit hors Common/Persistence et Common/Tenancy');
    }
}
