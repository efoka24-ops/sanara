<?php
declare(strict_types=1);

// Mise en service en ligne de commande : php bin/provision.php (variables lues dans l'environnement ou .env).
require dirname(__DIR__) . '/vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}
$cfg = Sanara\Common\Config::fromEnv(array_merge($_ENV, getenv()));
Sanara\Common\Database::configure($cfg);
$r = (new Sanara\Common\Persistence\Provisioner(Sanara\Common\Database::db(), new Sanara\Common\Clock(), dirname(__DIR__) . '/database/migrations'))
    ->run((string) (getenv('SUPERADMIN_EMAIL') ?: ($_ENV['SUPERADMIN_EMAIL'] ?? '')), (string) (getenv('SUPERADMIN_PASSWORD') ?: ($_ENV['SUPERADMIN_PASSWORD'] ?? '')));
echo json_encode($r), PHP_EOL;
