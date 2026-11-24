<?php
declare(strict_types=1);

namespace Sanara\Common\Tenancy;

/** Registre des tables métier filtrées par tenant_id. Un test échoue si une table portant tenant_id n'y figure pas. */
final class TenantTables
{
    private const SCOPED = ['tenant_settings', 'devices', 'opening_hours', 'onboarding_progress'];

    public static function isScoped(string $table): bool
    {
        return in_array($table, self::SCOPED, true);
    }
}
