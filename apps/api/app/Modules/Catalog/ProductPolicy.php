<?php
declare(strict_types=1);

namespace Sanara\Modules\Catalog;

/** Règles réglementaires partagées (constitution I). Un produit listé n'entre jamais dans un devis automatique. */
final class ProductPolicy
{
    public const STATUSES = ['free', 'prescription_only', 'list_i', 'list_ii', 'narcotic'];

    public static function autoQuoteAllowed(string $status): bool
    {
        return in_array($status, ['free', 'prescription_only'], true);
    }
}
