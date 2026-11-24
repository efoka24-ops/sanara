<?php
declare(strict_types=1);

namespace Sanara\Modules\Sales;

use Sanara\Modules\Documents\PrintPage as P;

/** Ticket 58/80 mm, facture A4 et rapport Z, pilotés par les paramètres de la pharmacie. */
final class SalesDocuments
{
    private const METHODS = ['cash' => 'Espèces', 'momo_mtn' => 'MTN MoMo', 'momo_orange' => 'Orange Money', 'card' => 'Carte', 'credit' => 'Crédit'];

    /**
     * @param array<string, mixed> $sale vente complète (SalesService::get)
     * @param array<string, mixed> $t pharmacie : name, license_number, address, phone, currency
     * @param array<string, mixed> $settings paramètres : ticket_footer, ticket_width
     */
    public static function receipt(array $sale, array $t, array $settings, string $cashier, bool $duplicate, ?string $width = null): string
    {
        $w = in_array($width ?? ($settings['ticket_width'] ?? '80'), ['58', '80'], true) ? (string) ($width ?? $settings['ticket_width'] ?? '80') : '80';
        $cur = (string) ($t['currency'] ?? 'XAF');
        $b = '<div class="c"><b>' . P::e($t['name'] ?? '') . '</b><br>' . P::e($t['address'] ?? '') . '<br>Tél ' . P::e($t['phone'] ?? '') . '<br>Agrément ' . P::e($t['license_number'] ?? '') . '</div>';
        if ($duplicate) {
            $b .= '<p class="c"><span class="dup">DUPLICATA</span></p>';
        }
        $b .= '<div class="sep"></div><div>Ticket ' . P::e($sale['number']) . '<br>' . P::e(substr((string) $sale['created_at'], 0, 16)) . ' UTC · ' . P::e($cashier) . '</div><div class="sep"></div><table>';
        foreach ($sale['lines'] as $l) {
            $b .= '<tr><td colspan="2">' . P::e($l['name']) . '</td></tr><tr><td>' . P::e($l['qty']) . ' × ' . P::money((int) $l['unit_price'], $cur) . ((int) $l['discount_bp'] > 0 ? ' (−' . ((int) $l['discount_bp'] / 100) . ' %)' : '') . '</td><td class="n">' . P::money((int) $l['line_total'], $cur) . '</td></tr>';
        }
        $b .= '</table><div class="sep"></div><table>';
        if ((int) $sale['discount_total'] > 0) {
            $b .= '<tr><td>Remises</td><td class="n">−' . P::money((int) $sale['discount_total'], $cur) . '</td></tr>';
        }
        $b .= '<tr class="tot"><td>TOTAL TTC</td><td class="n">' . P::money((int) $sale['total'], $cur) . '</td></tr><tr><td>dont TVA</td><td class="n">' . P::money((int) $sale['vat_total'], $cur) . '</td></tr>';
        foreach ($sale['payments'] as $p) {
            $b .= '<tr><td>' . P::e(self::METHODS[$p['method']] ?? $p['method']) . '</td><td class="n">' . P::money((int) $p['tendered'], $cur) . '</td></tr>';
        }
        if ((int) $sale['change'] > 0) {
            $b .= '<tr><td>Rendu</td><td class="n">' . P::money((int) $sale['change'], $cur) . '</td></tr>';
        }
        $b .= '</table>';
        if ($sale['prescription_ref'] !== null) {
            $b .= '<div class="sep"></div><div class="muted">Ordonnance ' . P::e($sale['prescription_ref']) . ' · ' . P::e($sale['prescriber']) . '</div>';
        }
        $b .= '<div class="sep"></div><p class="c muted">' . P::e($settings['ticket_footer'] ?? 'Merci de votre visite') . '</p>';
        return P::wrap('Ticket ' . (string) $sale['number'], $b, $w);
    }

    /**
     * @param array<string, mixed> $sale
     * @param array<string, mixed> $t
     * @param array<string, mixed> $settings
     */
    public static function invoice(array $sale, array $t, array $settings, string $invoiceNumber, string $cashier, bool $duplicate): string
    {
        $cur = (string) ($t['currency'] ?? 'XAF');
        $b = '<h1>' . P::e($t['name'] ?? '') . '</h1><p class="muted">' . P::e($settings['invoice_header'] ?? '') . ' ' . P::e($t['address'] ?? '') . ' · Tél ' . P::e($t['phone'] ?? '') . ' · Agrément ' . P::e($t['license_number'] ?? '') . '</p>';
        $b .= '<h2>Facture ' . P::e($invoiceNumber) . ($duplicate ? ' <span class="dup">DUPLICATA</span>' : '') . '</h2><p>Ticket ' . P::e($sale['number']) . ' · ' . P::e(substr((string) $sale['created_at'], 0, 10)) . ' · caissier ' . P::e($cashier);
        if ($sale['customer_name'] !== null) {
            $b .= ' · client ' . P::e($sale['customer_name']);
        }
        $b .= '</p><table><tr><th>Désignation</th><th class="n">Qté</th><th class="n">P.U. TTC</th><th class="n">Remise</th><th class="n">TVA</th><th class="n">Total TTC</th></tr>';
        foreach ($sale['lines'] as $l) {
            $b .= '<tr><td>' . P::e($l['name']) . '</td><td class="n">' . P::e($l['qty']) . '</td><td class="n">' . P::money((int) $l['unit_price'], $cur) . '</td><td class="n">' . ((int) $l['discount_bp'] / 100) . ' %</td><td class="n">' . ((int) $l['vat_bp'] / 100) . ' %</td><td class="n">' . P::money((int) $l['line_total'], $cur) . '</td></tr>';
        }
        $b .= '</table><div class="sep"></div><table class="tot"><tr><td>Total HT</td><td class="n">' . P::money((int) $sale['total'] - (int) $sale['vat_total'], $cur) . '</td></tr><tr><td>TVA</td><td class="n">' . P::money((int) $sale['vat_total'], $cur) . '</td></tr><tr><td>Total TTC</td><td class="n">' . P::money((int) $sale['total'], $cur) . '</td></tr></table>';
        if ($sale['prescription_ref'] !== null) {
            $b .= '<p>Ordonnance n° ' . P::e($sale['prescription_ref']) . ' · prescripteur ' . P::e($sale['prescriber']) . ' · patient ' . P::e($sale['patient_name']) . '</p>';
        }
        $b .= '<div class="sep"></div><table><tr><td>Vérificateur</td><td>Cachet de la pharmacie</td></tr><tr><td style="height:70px"></td><td></td></tr></table>';
        return P::wrap('Facture ' . $invoiceNumber, $b, 'a4');
    }

    /** @param array<string, mixed> $r CashService::report() */
    public static function zReport(array $r): string
    {
        $s = $r['session'];
        $t = $r['totals'];
        $cur = (string) $r['currency'];
        $b = '<div class="c"><b>' . P::e($r['pharmacy']) . '</b><br>Rapport Z ' . P::e($s['z_number'] ?? '(session ouverte)') . '</div><div class="sep"></div>'
            . '<div>Caisse ' . P::e($s['station']) . ' · ' . P::e($r['cashier']) . '<br>Ouverture ' . P::e(substr((string) $s['opened_at'], 0, 16)) . '<br>Clôture ' . P::e(substr((string) ($s['closed_at'] ?? ''), 0, 16)) . '</div><div class="sep"></div><table>'
            . '<tr><td>Ventes</td><td class="n">' . P::e($t['sales_count']) . '</td></tr><tr><td>Chiffre d’affaires TTC</td><td class="n">' . P::money((int) $t['sales_total'], $cur) . '</td></tr>'
            . '<tr><td>dont TVA</td><td class="n">' . P::money((int) $t['vat_total'], $cur) . '</td></tr><tr><td>Remises</td><td class="n">' . P::money((int) $t['discount_total'], $cur) . '</td></tr></table><div class="sep"></div><table>';
        foreach ($t['by_method'] as $m => $amount) {
            $b .= '<tr><td>' . P::e(self::METHODS[$m] ?? $m) . '</td><td class="n">' . P::money((int) $amount, $cur) . '</td></tr>';
        }
        $b .= '</table><div class="sep"></div><table><tr><td>Fond de caisse</td><td class="n">' . P::money((int) $t['opening_float'], $cur) . '</td></tr><tr><td>Remboursements espèces</td><td class="n">' . P::money((int) $t['cash_refunds'], $cur) . '</td></tr>'
            . '<tr><td>Espèces attendues</td><td class="n">' . P::money((int) $t['expected_cash'], $cur) . '</td></tr>';
        if ($s['counted_cash'] !== null) {
            $b .= '<tr><td>Espèces comptées</td><td class="n">' . P::money((int) $s['counted_cash'], $cur) . '</td></tr><tr class="tot"><td>Écart</td><td class="n">' . P::money((int) $s['gap'], $cur) . '</td></tr>';
            if ($s['gap_reason'] !== null) {
                $b .= '<tr><td colspan="2" class="muted">Motif : ' . P::e($s['gap_reason']) . '</td></tr>';
            }
        }
        return P::wrap('Rapport Z', $b . '</table>', '80');
    }
}
