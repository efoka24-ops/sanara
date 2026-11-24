<?php
declare(strict_types=1);

namespace Sanara\Common;

use PDO;

/** Connexion MySQL unique par requête. Mode SQL strict imposé (le serveur Camoo a sql_mode vide). */
final class Database
{
    public const SQL_MODE = 'STRICT_TRANS_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION';

    private static ?Config $config = null;
    private static ?PDO $pdo = null;

    public static function configure(Config $config): void
    {
        self::$config = $config;
        self::$pdo = null;
    }

    public static function db(): \Sanara\Common\Persistence\Db
    {
        return new \Sanara\Common\Persistence\Db(self::pdo());
    }

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $c = self::$config ?? throw new \LogicException('Database non configurée');
        if ($c->dbDriver === 'sqlite') { // développement et tests de bout en bout sans MySQL
            $dir = dirname($c->dbName);
            if (!is_dir($dir)) {
                mkdir($dir, 0775, true);
            }
            return self::$pdo = new PDO('sqlite:' . $c->dbName, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c->dbHost, $c->dbPort, $c->dbName);
        $pdo = new PDO($dsn, $c->dbUser, $c->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        $pdo->exec("SET SESSION sql_mode = '" . self::SQL_MODE . "'");
        return self::$pdo = $pdo;
    }
}
