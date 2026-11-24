<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;

/** Mise en service idempotente : migrations, rôles, pays, super admin initial (jamais écrasé). */
final class Provisioner
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly string $migrationsDir)
    {
    }

    /** @return array{migrations: list<string>, admin_created: bool} */
    public function run(string $adminEmail, string $adminPassword): array
    {
        $hasher = new PasswordHasher();
        if (!$hasher->meetsPolicy($adminPassword)) {
            throw new \InvalidArgumentException('Mot de passe du super admin trop faible');
        }
        $applied = (new Migrator($this->db, $this->migrationsDir))->migrate();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        (new \Sanara\Modules\Catalog\ReferenceCatalog($this->db))->seed('CM');
        $users = new UserRepository($this->db, $this->clock);
        $created = false;
        if ($users->findByEmail($adminEmail) === null) {
            $users->create(['email' => $adminEmail, 'role_code' => 'super_admin', 'password_hash' => $hasher->hash($adminPassword)]);
            $created = true;
        }
        return ['migrations' => $applied, 'admin_created' => $created];
    }
}
