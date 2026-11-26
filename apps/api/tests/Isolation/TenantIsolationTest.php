<?php
declare(strict_types=1);

namespace Sanara\Tests\Isolation;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Tenancy\TenantTables;
use Sanara\Common\Ulid;
use Sanara\Tests\Support\TestDb;

/** T016 : pour chaque table portant tenant_id NOT NULL, le tenant A ne voit ni ne modifie rien du tenant B. */
final class TenantIsolationTest extends TestCase
{
    private Db $db;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
    }

    /** @return list<string> */
    private function tenantTables(): array
    {
        $out = [];
        foreach ($this->db->all("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'") as $t) {
            foreach ($this->db->all('PRAGMA table_info(' . $t['name'] . ')') as $c) {
                if ($c['name'] === 'tenant_id' && (int) $c['notnull'] === 1) {
                    $out[] = $t['name'];
                }
            }
        }
        return $out;
    }

    public function testEveryTenantTableIsRegistered(): void
    {
        $tables = $this->tenantTables();
        $this->assertNotEmpty($tables);
        foreach ($tables as $t) {
            $this->assertTrue(TenantTables::isScoped($t), "La table $t porte tenant_id mais n'est pas enregistrée dans TenantTables");
        }
    }

    /** @return array<string, mixed> */
    private function dummyRow(string $table, string $tenantId): array
    {
        $row = [];
        foreach ($this->db->all("PRAGMA table_info($table)") as $c) {
            $n = $c['name'];
            if ((int) $c['notnull'] !== 1 && $n !== 'id') {
                continue;
            }
            $type = strtoupper((string) $c['type']);
            $row[$n] = match (true) {
                $n === 'id' => Ulid::generate(),
                $n === 'tenant_id' => $tenantId,
                str_contains($type, 'INT') || str_contains($type, 'DECIMAL') => 1,
                str_contains($type, 'JSON') => '{}',
                str_contains($type, 'DATETIME') => '2026-01-01 00:00:00.000',
                // Valeur factice à la taille de la colonne (les longueurs sont contrôlées par TestDb)
                default => substr('v' . bin2hex(random_bytes(6)), 0, preg_match('/\((\d+)\)/', $type, $m) === 1 ? (int) $m[1] : 13),
            };
        }
        return $row;
    }

    public function testTenantACannotReadUpdateOrDeleteTenantBRows(): void
    {
        $a = Ulid::generate();
        $b = Ulid::generate();
        foreach ($this->tenantTables() as $table) {
            $ctx = new TenantContext();
            $ctx->set($b);
            $rowB = $this->dummyRow($table, $b);
            (new TenantDb($this->db, $ctx))->insert($table, $rowB);
            $ctx->set($a);
            $tdb = new TenantDb($this->db, $ctx);
            $tdb->insert($table, $this->dummyRow($table, $a));

            $this->assertCount(1, $tdb->select($table), "$table : A ne doit voir que ses lignes");
            $this->assertSame(0, $tdb->update($table, ['id' => 'x'], ['id' => $rowB['id']]), "$table : mise à jour inter-tenant");
            $this->assertSame(1, $tdb->delete($table, []), "$table : A ne supprime que ses lignes");
            $left = $this->db->one("SELECT COUNT(*) AS n FROM $table WHERE tenant_id = ?", [$b]);
            $this->assertSame(1, (int) $left['n'], "$table : la ligne de B doit rester");
        }
    }
}
