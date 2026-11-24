<?php
declare(strict_types=1);

namespace Sanara\Common\Persistence;

use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;

/** Mise en service idempotente : migrations, rôles, pays, super admin initial (jamais écrasé, adresse modifiable). */
final class Provisioner
{
    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly string $migrationsDir)
    {
    }

    /** @return array{migrations: list<string>, admin_created: bool, admin_renamed: bool} */
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
        $renamed = false;
        if ($users->findByEmail($adminEmail) === null) {
            // Un super admin existe déjà sous une autre adresse : on change son adresse (mot de passe et 2FA conservés), sans créer de second compte.
            $existing = $this->db->one("SELECT id FROM users WHERE role_code = 'super_admin' ORDER BY created_at LIMIT 1");
            if ($existing !== null) {
                $this->db->execute('UPDATE users SET email = ?, updated_at = ? WHERE id = ?', [$adminEmail, $this->clock->stamp(), $existing['id']]);
                $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$this->clock->stamp(), $existing['id']]);
                $renamed = true;
            } else {
                $users->create(['email' => $adminEmail, 'role_code' => 'super_admin', 'password_hash' => $hasher->hash($adminPassword)]);
                $created = true;
            }
        }
        return ['migrations' => $applied, 'admin_created' => $created, 'admin_renamed' => $renamed];
    }

    /** Lève les verrouillages de comptes et remet les limites de débit à zéro (demande explicite du propriétaire, au déploiement). */
    public function unlockAccounts(): int
    {
        $n = $this->db->execute('UPDATE users SET failed_attempts = 0, locked_until = NULL, pin_failures = 0 WHERE failed_attempts > 0 OR locked_until IS NOT NULL OR pin_failures > 0');
        $this->db->execute('DELETE FROM rate_limits');
        return $n;
    }
}
