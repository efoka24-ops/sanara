<?php
declare(strict_types=1);

namespace Sanara\Modules\Catalog;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;

/** US6 : catalogue de la pharmacie. Jamais de suppression : on désactive. Tout passe par TenantDb. */
final class CatalogService
{
    private const FIELDS = ['name', 'dci', 'form', 'strength', 'pack', 'lab', 'family_id', 'generic_group_id', 'is_generic', 'regulatory_status', 'vat_bp', 'purchase_price', 'sale_price', 'min_stock', 'max_stock'];

    public function __construct(private readonly Db $db, private readonly TenantDb $tdb, private readonly Clock $clock)
    {
    }

    /** @param array<string, mixed> $in */
    public function create(array $in, string $userId): string
    {
        $in += ['vat_bp' => 0, 'purchase_price' => 0, 'sale_price' => 0, 'min_stock' => 0, 'is_generic' => 0];
        $this->validate($in, true);
        $id = Ulid::generate();
        $now = $this->clock->stamp();
        $this->db->transaction(function () use ($id, $in, $userId, $now): void {
            $row = ['id' => $id, 'active' => 1, 'created_by' => $userId, 'created_at' => $now, 'updated_at' => $now];
            foreach (self::FIELDS as $f) {
                $row[$f] = $in[$f] ?? null;
            }
            $row['is_generic'] = empty($in['is_generic']) ? 0 : 1;
            $row['name'] = trim((string) $in['name']);
            $this->tdb->insert('products', $row);
            $this->setBarcodes($id, (array) ($in['barcodes'] ?? []));
            $this->history($id, 'purchase', null, (int) $in['purchase_price'], 'Création', $userId);
            $this->history($id, 'sale', null, (int) $in['sale_price'], 'Création', $userId);
        });
        return $id;
    }

    /** @param array<string, mixed> $in */
    public function update(string $id, array $in, string $userId): void
    {
        $cur = $this->row($id);
        $this->validate($in + $cur, false);
        $set = [];
        foreach (self::FIELDS as $f) {
            if (array_key_exists($f, $in)) {
                $set[$f] = $f === 'is_generic' ? (empty($in[$f]) ? 0 : 1) : ($f === 'name' ? trim((string) $in[$f]) : $in[$f]);
            }
        }
        $this->db->transaction(function () use ($id, $in, $set, $cur, $userId): void {
            if ($set !== []) {
                $this->tdb->update('products', $set + ['updated_at' => $this->clock->stamp()], ['id' => $id]);
            }
            foreach (['purchase_price' => 'purchase', 'sale_price' => 'sale'] as $col => $kind) {
                if (isset($set[$col]) && (int) $set[$col] !== (int) $cur[$col]) {
                    $this->history($id, $kind, (int) $cur[$col], (int) $set[$col], (string) ($in['price_reason'] ?? ''), $userId);
                }
            }
            if (array_key_exists('barcodes', $in)) {
                $this->tdb->delete('product_barcodes', ['product_id' => $id]);
                $this->setBarcodes($id, (array) $in['barcodes']);
            }
        });
    }

    public function setActive(string $id, bool $active, string $userId): void
    {
        $this->row($id);
        $this->tdb->update('products', ['active' => $active ? 1 : 0, 'updated_at' => $this->clock->stamp()], ['id' => $id]);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $p = $this->row($id);
        $p['barcodes'] = array_column($this->tdb->select('product_barcodes', ['product_id' => $id], 'code'), 'code');
        $p['price_history'] = array_map(fn ($h) => ['kind' => $h['kind'], 'old_price' => $h['old_price'] === null ? null : (int) $h['old_price'], 'new_price' => (int) $h['new_price'], 'reason' => $h['reason'], 'at' => $h['created_at']],
            $this->tdb->select('price_history', ['product_id' => $id], 'created_at'));
        $p['equivalents'] = $p['generic_group_id'] === null ? [] : array_values(array_filter(
            $this->tdb->select('products', ['generic_group_id' => $p['generic_group_id'], 'active' => 1], 'name'),
            static fn ($x) => $x['id'] !== $id,
        ));
        return $this->cast($p);
    }

    /**
     * @param array<string, string> $filters family_id, status, active (1|0|all), limit, offset
     * @return array{items: list<array<string, mixed>>}
     */
    public function search(string $q, array $filters = []): array
    {
        $q = trim($q);
        $where = [];
        $active = $filters['active'] ?? '1';
        if ($active !== 'all') {
            $where['active'] = $active === '0' ? 0 : 1;
        }
        if (!empty($filters['family_id'])) {
            $where['family_id'] = $filters['family_id'];
        }
        if (!empty($filters['status'])) {
            $where['regulatory_status'] = $filters['status'];
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $offset = (int) ($filters['offset'] ?? 0);
        if ($q === '') {
            return ['items' => array_map($this->cast(...), $this->tdb->query('products', $where, [], 'name', $limit, $offset))];
        }
        $items = [];
        $seen = [];
        $add = function (array $rows) use (&$items, &$seen): void {
            foreach ($rows as $r) {
                if (!isset($seen[$r['id']])) {
                    $seen[$r['id']] = true;
                    $items[] = $this->cast($r);
                }
            }
        };
        foreach ($this->tdb->select('product_barcodes', ['code' => $q], null, 5) as $b) {
            $add($this->tdb->select('products', ['id' => $b['product_id']] + $where));
        }
        $e = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q);
        $add($this->tdb->query('products', $where, ['name' => "$e%"], 'name', $limit, $offset));
        $add($this->tdb->query('products', $where, ['name' => "%$e%", 'dci' => "%$e%"], 'name', $limit, $offset));
        return ['items' => array_slice($items, 0, $limit)];
    }

    public function createFamily(string $name): string
    {
        return $this->named('product_families', $name);
    }

    public function createGroup(string $name): string
    {
        return $this->named('generic_groups', $name);
    }

    /** @return list<array<string, mixed>> */
    public function families(): array
    {
        return $this->tdb->select('product_families', [], 'name');
    }

    /** @return list<array<string, mixed>> */
    public function groups(): array
    {
        return $this->tdb->select('generic_groups', [], 'name');
    }

    public function familyIdByName(string $name, bool $create = true): ?string
    {
        $r = $this->tdb->select('product_families', ['name' => $name], null, 1);
        return $r[0]['id'] ?? ($create ? $this->createFamily($name) : null);
    }

    /** @return array<string, mixed>|null */
    public function findByBarcode(string $code): ?array
    {
        $b = $this->tdb->select('product_barcodes', ['code' => $code], null, 1)[0] ?? null;
        return $b === null ? null : ($this->tdb->select('products', ['id' => $b['product_id']])[0] ?? null);
    }

    /** @return array<string, mixed>|null */
    public function findByIdentity(string $name, ?string $form, ?string $strength): ?array
    {
        return $this->tdb->select('products', ['name' => $name, 'form' => $form, 'strength' => $strength], null, 1)[0] ?? null;
    }

    private function named(string $table, string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['name' => 'required']]);
        }
        if ($this->tdb->select($table, ['name' => $name]) !== []) {
            throw new ApiException('conflict', 409);
        }
        $id = Ulid::generate();
        $this->tdb->insert($table, ['id' => $id, 'name' => $name]);
        return $id;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        return $this->tdb->select('products', ['id' => $id])[0] ?? throw new ApiException('not_found', 404);
    }

    /** @param list<string> $codes */
    private function setBarcodes(string $productId, array $codes): void
    {
        foreach (array_values(array_unique(array_filter(array_map(static fn ($c) => trim((string) $c), $codes)))) as $code) {
            if (mb_strlen($code) > 64 || $this->tdb->select('product_barcodes', ['code' => $code]) !== []) {
                throw new ApiException('conflict', 409, ['barcode' => $code]);
            }
            $this->tdb->insert('product_barcodes', ['id' => Ulid::generate(), 'product_id' => $productId, 'code' => $code]);
        }
    }

    private function history(string $productId, string $kind, ?int $old, int $new, string $reason, string $userId): void
    {
        $this->tdb->insert('price_history', ['id' => Ulid::generate(), 'product_id' => $productId, 'kind' => $kind, 'old_price' => $old, 'new_price' => $new,
            'reason' => $reason !== '' ? mb_substr($reason, 0, 190) : null, 'user_id' => $userId, 'created_at' => $this->clock->stamp()]);
    }

    /** @param array<string, mixed> $in */
    private function validate(array $in, bool $creating): void
    {
        $e = [];
        if (trim((string) ($in['name'] ?? '')) === '' || mb_strlen((string) $in['name']) > 190) {
            $e['name'] = 'required';
        }
        if (!in_array($in['regulatory_status'] ?? '', ProductPolicy::STATUSES, true)) {
            $e['regulatory_status'] = 'required';
        }
        foreach (['vat_bp', 'purchase_price', 'sale_price', 'min_stock'] as $k) {
            if (isset($in[$k]) && (!is_int($in[$k]) && !(is_string($in[$k]) && ctype_digit($in[$k])) || (int) $in[$k] < 0)) {
                $e[$k] = 'invalid';
            }
        }
        if ($e !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $e]);
        }
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function cast(array $r): array
    {
        foreach (['vat_bp', 'purchase_price', 'sale_price', 'min_stock', 'active', 'is_generic'] as $k) {
            if (isset($r[$k])) {
                $r[$k] = (int) $r[$k];
            }
        }
        return $r;
    }
}
