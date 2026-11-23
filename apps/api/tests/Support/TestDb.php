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
        return $db;
    }
}
