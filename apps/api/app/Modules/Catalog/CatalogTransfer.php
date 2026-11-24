<?php
declare(strict_types=1);

namespace Sanara\Modules\Catalog;

use Sanara\Common\Errors\ApiException;

/** Import et export CSV du catalogue (Excel ouvre et enregistre le CSV). Rapport ligne par ligne. */
final class CatalogTransfer
{
    private const HEADER = ['name', 'dci', 'form', 'strength', 'pack', 'lab', 'family', 'status', 'vat', 'purchase', 'sale', 'min', 'barcode'];

    public function __construct(private readonly CatalogService $catalog)
    {
    }

    /** @return array{created: int, updated: int, rejected: list<array{line: int, reason: string}>, total: int} */
    public function import(string $csv, string $userId): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $lines = preg_split('/\r\n|\n|\r/', trim($csv)) ?: [];
        if (count($lines) < 2) {
            throw new ApiException('validation_failed', 422, ['fields' => ['file' => 'empty']]);
        }
        $delim = substr_count($lines[0], ';') >= substr_count($lines[0], ',') ? ';' : ',';
        $head = array_map(static fn ($h) => strtolower(trim($h)), str_getcsv($lines[0], $delim, '"', ''));
        if (!in_array('name', $head, true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['file' => 'header']]);
        }
        $r = ['created' => 0, 'updated' => 0, 'rejected' => [], 'total' => 0];
        foreach (array_slice($lines, 1, null, true) as $i => $line) {
            if (trim($line) === '') {
                continue;
            }
            $r['total']++;
            $n = $i + 1;
            $cells = str_getcsv($line, $delim, '"', '');
            $row = [];
            foreach ($head as $k => $h) {
                $row[$h] = trim((string) ($cells[$k] ?? ''));
            }
            try {
                $in = $this->map($row);
                $codes = ($row['barcode'] ?? '') !== '' ? array_map('trim', explode('|', $row['barcode'])) : [];
                $existing = null;
                foreach ($codes as $c) {
                    $existing = $this->catalog->findByBarcode($c) ?? $existing;
                }
                $existing ??= $this->catalog->findByIdentity($in['name'], $in['form'], $in['strength']);
                if ($existing !== null) {
                    $this->catalog->update((string) $existing['id'], $in + ['price_reason' => 'Import CSV'], $userId);
                    $r['updated']++;
                } else {
                    $this->catalog->create($in + ['barcodes' => $codes], $userId);
                    $r['created']++;
                }
            } catch (ApiException $e) {
                $fields = array_keys((array) ($e->details['fields'] ?? []));
                $r['rejected'][] = ['line' => $n, 'reason' => $e->errorCode . ($fields !== [] ? ' : ' . implode(', ', $fields) : '')];
            }
        }
        return $r;
    }

    public function export(): string
    {
        $out = fopen('php://temp', 'w+');
        fputcsv($out, self::HEADER, ';', '"', '');
        $offset = 0;
        $families = array_column($this->catalog->families(), 'name', 'id');
        do {
            $page = $this->catalog->search('', ['active' => 'all', 'limit' => '200', 'offset' => (string) $offset])['items'];
            foreach ($page as $p) {
                $full = $this->catalog->get((string) $p['id']);
                fputcsv($out, [$p['name'], $p['dci'], $p['form'], $p['strength'], $p['pack'], $p['lab'], $families[$p['family_id']] ?? '', $p['regulatory_status'], $p['vat_bp'], $p['purchase_price'], $p['sale_price'], $p['min_stock'], implode('|', $full['barcodes'])], ';', '"', '');
            }
            $offset += 200;
        } while (count($page) === 200);
        rewind($out);
        return "\xEF\xBB\xBF" . stream_get_contents($out);
    }

    /** @param array<string, string> $row @return array<string, mixed> */
    private function map(array $row): array
    {
        $num = static function (string $v, string $field): int {
            if ($v === '') {
                return 0;
            }
            if (!ctype_digit($v)) {
                throw new ApiException('validation_failed', 422, ['fields' => [$field => 'invalid']]);
            }
            return (int) $v;
        };
        return [
            'name' => $row['name'] ?? '', 'dci' => $row['dci'] ?? null ?: null, 'form' => ($row['form'] ?? '') ?: null, 'strength' => ($row['strength'] ?? '') ?: null,
            'pack' => ($row['pack'] ?? '') ?: null, 'lab' => ($row['lab'] ?? '') ?: null,
            'family_id' => ($row['family'] ?? '') !== '' ? $this->catalog->familyIdByName($row['family']) : null,
            'regulatory_status' => $row['status'] ?? '', 'vat_bp' => $num($row['vat'] ?? '', 'vat'), 'purchase_price' => $num($row['purchase'] ?? '', 'purchase'),
            'sale_price' => $num($row['sale'] ?? '', 'sale'), 'min_stock' => $num($row['min'] ?? '', 'min'),
        ];
    }
}
