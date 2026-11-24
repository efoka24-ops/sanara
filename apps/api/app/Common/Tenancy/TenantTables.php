<?php
declare(strict_types=1);

namespace Sanara\Common\Tenancy;

/** Registre des tables métier filtrées par tenant_id. Un test échoue si une table portant tenant_id n'y figure pas. */
final class TenantTables
{
    private const SCOPED = ['tenant_settings', 'devices', 'opening_hours', 'onboarding_progress', 'product_families', 'generic_groups', 'products', 'product_barcodes', 'price_history', 'lots', 'stock_movements', 'stock_alerts', 'destructions', 'inventories', 'inventory_lines', 'inventory_counts', 'public_pharmacies', 'document_sequences', 'cash_sessions', 'cash_counts', 'sales', 'sale_lines', 'payments', 'held_carts', 'cash_movements', 'sale_returns', 'return_lines', 'credit_notes'];

    public static function isScoped(string $table): bool
    {
        return in_array($table, self::SCOPED, true);
    }
}
