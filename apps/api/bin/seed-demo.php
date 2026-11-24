<?php
declare(strict_types=1);

// Données de démonstration pour les tests de bout en bout : une pharmacie active et son titulaire.
// Refuse de s'exécuter en production.
require dirname(__DIR__) . '/vendor/autoload.php';

$env = array_merge($_ENV, getenv());
if (($env['APP_ENV'] ?? 'production') === 'production') {
    fwrite(STDERR, "Refusé en production\n");
    exit(1);
}
$cfg = Sanara\Common\Config::fromEnv($env);
Sanara\Common\Database::configure($cfg);
$db = Sanara\Common\Database::db();
$clock = new Sanara\Common\Clock();
$now = $clock->stamp();
$tid = 'DEMO' . str_repeat('0', 22);
if ($db->one('SELECT id FROM tenants WHERE id = ?', [$tid]) === null) {
    $db->insert('tenants', ['id' => $tid, 'country_code' => 'CM', 'name' => 'Pharmacie Démo', 'slug' => 'demo', 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => $now, 'updated_at' => $now]);
}
$users = new Sanara\Common\Persistence\UserRepository($db, $clock);
$hasher = new Sanara\Common\Auth\PasswordHasher();
foreach ([['owner', 'owner@demo.test'], ['cashier', 'cashier@demo.test'], ['storekeeper', 'storekeeper@demo.test']] as [$role, $email]) {
    if ($users->findByEmail($email) === null) {
        $users->create(['email' => $email, 'role_code' => $role, 'tenant_id' => $tid, 'password_hash' => $hasher->hash((string) ($env['DEMO_PASSWORD'] ?? 'Demo#Motdepasse1'))]);
    }
}
echo json_encode(['tenant' => $tid]), PHP_EOL;
