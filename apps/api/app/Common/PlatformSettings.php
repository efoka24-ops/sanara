<?php
declare(strict_types=1);

namespace Sanara\Common;

use Sanara\Common\Persistence\Db;

/**
 * Paramètres globaux de la plateforme (console B10, T312), lus là où ils s'appliquent. Valeurs par
 * défaut quand rien n'a été enregistré ; relus à chaque requête.
 */
final class PlatformSettings
{
    public const DEFAULTS = [
        'link_validity_hours' => '48',
        'admin_mfa' => '1',
        'request_quota_per_day' => '5',
        'reservation_release_hours' => '24',
        'maintenance' => '0',
        'desktop_version' => '1.0.0',
    ];

    /** @var array<string, string>|null */
    private ?array $values = null;

    public function __construct(private readonly Db $db)
    {
    }

    public function get(string $key): string
    {
        if ($this->values === null) {
            $this->values = self::DEFAULTS;
            try {
                foreach ($this->db->all('SELECT skey, svalue FROM platform_settings') as $r) {
                    $this->values[(string) $r['skey']] = (string) $r['svalue'];
                }
            } catch (\PDOException) {
                // Base pas encore migrée : valeurs par défaut.
            }
        }
        return $this->values[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public function int(string $key): int
    {
        return (int) $this->get($key);
    }

    public function on(string $key): bool
    {
        return $this->get($key) === '1';
    }

    /** À appeler après une modification dans la même requête. */
    public function forget(): void
    {
        $this->values = null;
    }
}
