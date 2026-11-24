<?php
declare(strict_types=1);

namespace Sanara\Tests\Common;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Persistence\Migrator;
use Sanara\Tests\Support\TestDb;

final class MigratorResumeTest extends TestCase
{
    public function testCrlfMigrationFileIsSplitIntoStatements(): void
    {
        $dir = sys_get_temp_dir() . '/mig-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir . '/0001_x.sql', "CREATE TABLE a (id INT);\r\nCREATE TABLE b (id INT)\r\n");
        $db = TestDb::create();
        $applied = (new Migrator($db, $dir))->migrate();
        $this->assertSame(['0001_x.sql'], $applied);
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) n FROM b')['n']);
    }

    public function testInterruptedMigrationCanBeResumed(): void
    {
        $dir = sys_get_temp_dir() . '/mig-' . bin2hex(random_bytes(3));
        mkdir($dir);
        file_put_contents($dir . '/0001_x.sql', "CREATE TABLE a (id INT);\nCREATE TABLE b (id INT)\n");
        $db = TestDb::create();
        $db->exec('CREATE TABLE a (id INT)'); // restes d'une exécution interrompue
        $this->assertSame(['0001_x.sql'], (new Migrator($db, $dir))->migrate());
        $this->assertSame(0, (int) $db->one('SELECT COUNT(*) n FROM b')['n']);
    }
}
