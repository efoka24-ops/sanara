<?php
declare(strict_types=1);

namespace Sanara\Modules\Documents;

/** Pages HTML imprimables (ticket 58/80 mm, facture A4, procès-verbal, rapport Z). Impression par le navigateur ou l'application desktop. */
final class PrintPage
{
    public static function e(mixed $v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Montant entier en francs CFA, séparateur de milliers insécable. */
    public static function money(int $v, string $currency = 'XAF'): string
    {
        return number_format($v, 0, ',', "\u{202F}") . "\u{00A0}" . ($currency === 'XAF' ? 'FCFA' : $currency);
    }

    /** @param string $format 'a4' | '80' | '58' */
    public static function wrap(string $title, string $body, string $format = 'a4'): string
    {
        $width = match ($format) {
            '58' => '58mm',
            '80' => '80mm',
            default => '210mm',
        };
        $font = $format === 'a4' ? '12pt' : '9pt';
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::e($title) . '</title><style>'
            . '@page{size:' . ($format === 'a4' ? 'A4' : $width . ' auto') . ';margin:' . ($format === 'a4' ? '15mm' : '3mm') . '}'
            . 'body{font-family:system-ui,Segoe UI,Arial,sans-serif;font-size:' . $font . ';color:#111;max-width:' . $width . ';margin:0 auto;padding:8px}'
            . 'h1{font-size:1.25em;margin:.2em 0}h2{font-size:1.05em;margin:.8em 0 .2em}table{width:100%;border-collapse:collapse}'
            . 'td,th{padding:2px 0;text-align:left;vertical-align:top}td.n,th.n{text-align:right;white-space:nowrap}'
            . '.c{text-align:center}.muted{color:#555}.sep{border-top:1px dashed #333;margin:6px 0}.tot td{font-weight:700}'
            . '.dup{border:2px solid #000;padding:2px 6px;display:inline-block;font-weight:700;letter-spacing:.1em}'
            . '@media print{.noprint{display:none}}</style></head><body>' . $body
            . '<p class="noprint c"><button onclick="window.print()">Imprimer</button></p></body></html>';
    }
}
