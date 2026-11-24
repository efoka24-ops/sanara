<?php
declare(strict_types=1);

namespace Sanara\Tests\Common;

use PDO;
use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Provisioner;

final class ProvisionerTest extends TestCase
{
    public function testProvisionIsIdempotentAndCreatesSuperAdminOnce(): void
    {
        $db = new Db(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
        $p = new Provisioner($db, Clock::fixed('2026-11-24T10:00:00Z'), dirname(__DIR__, 2) . '/database/migrations');
        $r1 = $p->run('admin@sanara.cm', 'Admin#Motdepasse1');
        $r2 = $p->run('admin@sanara.cm', 'Autre#Motdepasse2');
        $this->assertNotEmpty($r1['migrations']);
        $this->assertSame([], $r2['migrations']);
        $this->assertTrue($r1['admin_created']);
        $this->assertFalse($r2['admin_created']);
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) n FROM users WHERE role_code = 'super_admin'")['n']);
        $this->assertTrue(password_verify('Admin#Motdepasse1', $db->one('SELECT password_hash FROM users')['password_hash']), 'le mot de passe existant n\'est pas écrasé');
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) n FROM countries WHERE code = 'CM'")['n']);
    }

    public function testChangingTheConfiguredEmailRenamesTheExistingSuperAdminAndKeepsItsSecurity(): void
    {
        $db = new Db(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
        $p = new Provisioner($db, Clock::fixed('2026-11-24T10:00:00Z'), dirname(__DIR__, 2) . '/database/migrations');
        $p->run('admin@ancien.cm', 'Admin#Motdepasse1');
        $db->execute('UPDATE users SET totp_enabled = 1');
        $r = $p->run('nouveau@exemple.cm', 'Admin#Motdepasse1');
        $this->assertTrue($r['admin_renamed']);
        $this->assertFalse($r['admin_created']);
        $row = $db->one("SELECT email, totp_enabled FROM users WHERE role_code = 'super_admin'");
        $this->assertSame('nouveau@exemple.cm', $row['email']);
        $this->assertSame(1, (int) $row['totp_enabled']);
        $this->assertSame(1, (int) $db->one("SELECT COUNT(*) n FROM users WHERE role_code = 'super_admin'")['n']);
        $this->assertFalse($p->run('nouveau@exemple.cm', 'Admin#Motdepasse1')['admin_renamed']);
    }

    public function testRefusesWeakAdminPassword(): void
    {
        $db = new Db(new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]));
        $this->expectException(\InvalidArgumentException::class);
        (new Provisioner($db, new Clock(), dirname(__DIR__, 2) . '/database/migrations'))->run('a@b.cm', 'faible');
    }
}
