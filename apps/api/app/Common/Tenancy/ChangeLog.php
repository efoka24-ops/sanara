<?php
declare(strict_types=1);

namespace Sanara\Common\Tenancy;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Ulid;

/**
 * T137 : retient, à l'heure de réception par le serveur, quels produits et clients ont changé. Les postes
 * ne retéléchargent qu'eux. L'heure d'une vente faite hors-ligne ne convient pas : elle est antérieure.
 */
final class ChangeLog
{
    /** table => [objet du référentiel, colonne qui le désigne] */
    private const TRACKED = [
        'products' => ['product', 'id'],
        'product_barcodes' => ['product', 'product_id'],
        'lots' => ['product', 'product_id'],
        'stock_movements' => ['product', 'product_id'],
        'customers' => ['customer', 'id'],
        'customer_entries' => ['customer', 'customer_id'],
    ];

    public function __construct(private readonly Db $db, private readonly Clock $clock)
    {
    }

    /** @return array{0: string, 1: string}|null */
    public function tracked(string $table): ?array
    {
        return self::TRACKED[$table] ?? null;
    }

    public function mark(string $tenant, string $entity, string $id): void
    {
        $now = $this->clock->stamp();
        $key = [$tenant, $entity, $id];
        $update = 'UPDATE ref_changes SET changed_at = ? WHERE tenant_id = ? AND entity = ? AND entity_id = ?';
        if ($this->db->execute($update, [$now, ...$key]) > 0) {
            return;
        }
        try {
            $this->db->insert('ref_changes', ['id' => Ulid::generate(), 'tenant_id' => $tenant, 'entity' => $entity, 'entity_id' => $id, 'changed_at' => $now]);
        } catch (\PDOException) {
            // Ligne déjà là : même heure (MySQL ne compte pas une mise à jour sans changement) ou écriture simultanée.
            $this->db->execute($update, [$now, ...$key]);
        }
    }
}
