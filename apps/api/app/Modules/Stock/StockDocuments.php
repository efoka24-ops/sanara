<?php
declare(strict_types=1);

namespace Sanara\Modules\Stock;

use Sanara\Modules\Documents\PrintPage as P;

final class StockDocuments
{
    /** @param array<string, mixed> $d données de StockService::destruction() */
    public static function destruction(array $d): string
    {
        $body = '<h1>Procès-verbal de destruction n° ' . P::e($d['number']) . '</h1>'
            . '<p class="muted">' . P::e($d['pharmacy']) . ' · agrément ' . P::e($d['license']) . '</p>'
            . '<table><tr><td>Date</td><td>' . P::e(substr((string) $d['date'], 0, 16)) . ' UTC</td></tr>'
            . '<tr><td>Produit</td><td>' . P::e($d['product']) . '</td></tr>'
            . '<tr><td>Lot</td><td>' . P::e($d['lot']) . ' · péremption ' . P::e($d['expiry']) . '</td></tr>'
            . '<tr><td>Quantité détruite</td><td>' . P::e($d['qty']) . '</td></tr>'
            . '<tr><td>Valeur (prix d’achat)</td><td>' . P::money((int) $d['value']) . '</td></tr>'
            . '<tr><td>Motif</td><td>' . P::e($d['reason']) . '</td></tr>'
            . '<tr><td>Méthode</td><td>' . P::e($d['method'] ?? '') . '</td></tr>'
            . '<tr><td>Témoin</td><td>' . P::e($d['witness'] ?? '') . '</td></tr>'
            . '<tr><td>Opérateur</td><td>' . P::e($d['user']) . '</td></tr></table>'
            . '<div class="sep"></div><table><tr><td>Signature du pharmacien</td><td>Signature du témoin</td></tr><tr><td style="height:60px"></td><td></td></tr></table>';
        return P::wrap('PV de destruction ' . (string) $d['number'], $body);
    }
}
