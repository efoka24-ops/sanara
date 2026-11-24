<?php
declare(strict_types=1);

namespace Sanara\Modules\Sales;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Security\PinService;
use Sanara\Modules\Stock\StockService;

/** US10 : vente au comptoir. Prix lus côté serveur, FEFO à la vente, contrôles réglementaires et remises plafonnées. */
final class SalesService
{
    public const METHODS = ['cash', 'momo_mtn', 'momo_orange', 'card', 'credit'];

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly Clock $clock,
        private readonly StockService $stock,
        private readonly CashService $cash,
        private readonly Sequences $seq,
        private readonly Authorizer $authz,
        private readonly PinService $pin,
        private readonly TenantContext $ctx,
    ) {
    }

    /**
     * @param array<string, mixed> $in lines[{product_id, qty, discount_bp?}], payments[{method, amount, reference?}], prescription?, pharmacist?, approver?
     * @return array<string, mixed> vente complète avec rendu de monnaie
     */
    public function create(array $in, string $userId): array
    {
        $session = $this->cash->current($userId) ?? throw new ApiException('cash_session_required', 409);
        if ($session['stale']) {
            throw new ApiException('cash_session_stale', 409);
        }
        $lines = $this->lines((array) ($in['lines'] ?? []));
        $maxBp = $this->maxDiscountBp();
        $needsApproval = false;
        foreach ($lines as $l) {
            if ($l['discount_bp'] > 0 && !$this->authz->can($userId, 'sales.discount')) {
                throw new ApiException('forbidden', 403);
            }
            $needsApproval = $needsApproval || $l['discount_bp'] > $maxBp;
        }
        $approver = $needsApproval ? $this->approver($in['approver'] ?? null, $userId) : null;

        $regulated = array_filter($lines, static fn ($l) => $l['status'] !== 'free');
        $presc = (array) ($in['prescription'] ?? []);
        $pharmacist = null;
        if ($regulated !== []) {
            foreach (['ref', 'prescriber', 'patient_name'] as $f) {
                if (trim((string) ($presc[$f] ?? '')) === '') {
                    throw new ApiException('prescription_required', 422, ['fields' => ["prescription.$f" => 'required']]);
                }
            }
            $pharmacist = $this->pharmacist($in['pharmacist'] ?? null, $userId);
        }

        $total = array_sum(array_column($lines, 'total'));
        $settle = SaleMath::settle($total, $this->payments((array) ($in['payments'] ?? [])));
        if ($settle === null) {
            throw new ApiException('payment_insufficient', 422, ['total' => $total]);
        }
        foreach ((array) ($in['payments'] ?? []) as $p) {
            if (in_array($p['method'] ?? '', ['momo_mtn', 'momo_orange', 'card'], true) && trim((string) ($p['reference'] ?? '')) === '') {
                throw new ApiException('validation_failed', 422, ['fields' => ['payments.reference' => 'required']]);
            }
            if (($p['method'] ?? '') === 'credit' && trim((string) ($in['customer_name'] ?? '')) === '') {
                throw new ApiException('validation_failed', 422, ['fields' => ['customer_name' => 'required']]);
            }
        }

        $id = Ulid::generate();
        $this->db->transaction(function () use ($id, $in, $userId, $session, $lines, $total, $settle, $presc, $pharmacist, $approver): void {
            $year = substr($this->clock->stamp(), 0, 4);
            $number = sprintf('V-%s-%s-%05d', $session['station'], $year, $this->seq->next('sale:' . $session['station'] . ':' . $year));
            $now = $this->clock->stamp();
            $this->tdb->insert('sales', [
                'id' => $id, 'number' => $number, 'invoice_number' => null, 'station' => $session['station'], 'session_id' => $session['id'], 'cashier_id' => $userId, 'status' => 'completed',
                'gross_total' => array_sum(array_column($lines, 'gross')), 'discount_total' => array_sum(array_column($lines, 'discount')), 'total' => $total, 'vat_total' => array_sum(array_column($lines, 'vat')),
                'customer_name' => $this->str($in['customer_name'] ?? null, 120), 'prescription_ref' => $this->str($presc['ref'] ?? null, 60), 'prescriber' => $this->str($presc['prescriber'] ?? null, 120),
                'patient_name' => $this->str($presc['patient_name'] ?? null, 120), 'pharmacist_id' => $pharmacist, 'discount_approved_by' => $approver, 'printed_count' => 0, 'created_at' => $now,
            ]);
            foreach ($lines as $l) {
                $this->tdb->insert('sale_lines', [
                    'id' => Ulid::generate(), 'sale_id' => $id, 'product_id' => $l['product_id'], 'name' => $l['name'], 'regulatory_status' => $l['status'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'],
                    'discount_bp' => $l['discount_bp'], 'vat_bp' => $l['vat_bp'], 'line_total' => $l['total'], 'vat_amount' => $l['vat'],
                ]);
                $this->stock->issue($l['product_id'], $l['qty'], 'sale', 'sale', $id, $userId, 'Vente ' . $number);
            }
            foreach ($settle['applied'] as $i => $p) {
                $ref = (array) ($in['payments'] ?? []);
                $this->tdb->insert('payments', [
                    'id' => Ulid::generate(), 'sale_id' => $id, 'session_id' => $session['id'], 'method' => $p['method'], 'amount' => $p['amount'], 'tendered' => $p['tendered'],
                    'reference' => $this->str($ref[$i]['reference'] ?? null, 80), 'created_at' => $now,
                ]);
            }
        });
        return $this->get($id) + ['change' => $settle['change']];
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $s = $this->tdb->select('sales', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
        foreach (['gross_total', 'discount_total', 'total', 'vat_total', 'printed_count'] as $k) {
            $s[$k] = (int) $s[$k];
        }
        $s['lines'] = array_map(static function ($l) {
            foreach (['qty', 'unit_price', 'discount_bp', 'vat_bp', 'line_total', 'vat_amount'] as $k) {
                $l[$k] = (int) $l[$k];
            }
            return $l;
        }, $this->tdb->select('sale_lines', ['sale_id' => $id]));
        $s['payments'] = array_map(static function ($p) {
            $p['amount'] = (int) $p['amount'];
            $p['tendered'] = (int) $p['tendered'];
            return $p;
        }, $this->tdb->select('payments', ['sale_id' => $id]));
        $cash = array_sum(array_map(static fn ($p) => $p['method'] === 'cash' ? $p['tendered'] - $p['amount'] : 0, $s['payments']));
        $s['change'] = $cash;
        return $s;
    }

    /** @return list<array<string, mixed>> */
    public function list(?string $onlyCashier, ?string $date): array
    {
        $where = $onlyCashier !== null ? ['cashier_id' => $onlyCashier] : [];
        $rows = array_reverse($this->tdb->select('sales', $where, 'created_at', 200));
        if ($date !== null && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1) {
            $rows = array_values(array_filter($rows, static fn ($r) => str_starts_with((string) $r['created_at'], $date)));
        }
        return array_map(static fn ($r) => ['id' => $r['id'], 'number' => $r['number'], 'status' => $r['status'], 'total' => (int) $r['total'], 'created_at' => $r['created_at'], 'cashier_id' => $r['cashier_id'], 'customer_name' => $r['customer_name']], $rows);
    }

    /** Génériques disponibles pour un produit en rupture, FEFO respecté. @return list<array<string, mixed>> */
    public function alternatives(string $productId): array
    {
        $p = $this->tdb->select('products', ['id' => $productId], null, 1)[0] ?? throw new ApiException('not_found', 404);
        $out = [];
        $candidates = $p['generic_group_id'] !== null ? $this->tdb->select('products', ['generic_group_id' => $p['generic_group_id'], 'active' => 1]) : [];
        foreach ($candidates as $c) {
            if ($c['id'] === $productId || $c['regulatory_status'] !== $p['regulatory_status']) {
                continue; // jamais d'alternative d'un autre statut réglementaire
            }
            $qty = $this->stock->availableQty((string) $c['id']);
            if ($qty > 0) {
                $out[] = ['product_id' => $c['id'], 'name' => $c['name'], 'sale_price' => (int) $c['sale_price'], 'available' => $qty, 'is_generic' => (int) $c['is_generic']];
            }
        }
        return $out;
    }

    public function markPrinted(string $id): int
    {
        $s = $this->get($id);
        $n = $s['printed_count'] + 1;
        $this->tdb->update('sales', ['printed_count' => $n], ['id' => $id]);
        return $n;
    }

    /** Numéro de facture A4, attribué à la première demande puis conservé. */
    public function invoiceNumber(string $id): string
    {
        $s = $this->get($id);
        if ($s['invoice_number'] !== null) {
            return (string) $s['invoice_number'];
        }
        $year = substr((string) $s['created_at'], 0, 4);
        $num = sprintf('F-%s-%06d', $year, $this->seq->next('invoice:' . $year));
        $this->tdb->update('sales', ['invoice_number' => $num], ['id' => $id]);
        return $num;
    }

    /** Paniers en attente (F2) : par utilisateur. @return list<array<string, mixed>> */
    public function held(string $userId): array
    {
        return array_map(static fn ($h) => ['id' => $h['id'], 'label' => $h['label'], 'cart' => json_decode((string) $h['payload'], true), 'created_at' => $h['created_at']], $this->tdb->select('held_carts', ['user_id' => $userId], 'created_at'));
    }

    public function hold(string $userId, string $label, mixed $cart): string
    {
        $json = json_encode($cart, JSON_UNESCAPED_UNICODE);
        if (!is_array($cart) || $json === false || strlen($json) > 20000) {
            throw new ApiException('validation_failed', 422, ['fields' => ['cart' => 'invalid']]);
        }
        $id = Ulid::generate();
        $this->tdb->insert('held_carts', ['id' => $id, 'user_id' => $userId, 'label' => mb_substr(trim($label) !== '' ? trim($label) : 'Panier', 0, 60), 'payload' => $json, 'created_at' => $this->clock->stamp()]);
        return $id;
    }

    public function resume(string $userId, string $id): void
    {
        $this->tdb->delete('held_carts', ['id' => $id, 'user_id' => $userId]);
    }

    /** @param array<int, mixed> $raw @return list<array<string, mixed>> */
    private function lines(array $raw): array
    {
        if ($raw === []) {
            throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'required']]);
        }
        $out = [];
        foreach ($raw as $r) {
            $qty = $r['qty'] ?? null;
            $bp = $r['discount_bp'] ?? 0;
            if (!is_int($qty) || $qty <= 0 || !is_int($bp) || $bp < 0 || $bp > 10000) {
                throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'invalid']]);
            }
            $p = $this->tdb->select('products', ['id' => (string) ($r['product_id'] ?? '')], null, 1)[0] ?? throw new ApiException('not_found', 404);
            if ((int) $p['active'] !== 1) {
                throw new ApiException('conflict', 409, ['product_id' => $p['id']]);
            }
            $m = SaleMath::line($qty, (int) $p['sale_price'], $bp, (int) $p['vat_bp']);
            $out[] = ['product_id' => (string) $p['id'], 'name' => (string) $p['name'], 'status' => (string) $p['regulatory_status'], 'qty' => $qty, 'unit_price' => (int) $p['sale_price'],
                'discount_bp' => $bp, 'vat_bp' => (int) $p['vat_bp']] + $m;
        }
        return $out;
    }

    /** @param array<int, mixed> $raw @return list<array{method: string, amount: int}> */
    private function payments(array $raw): array
    {
        $out = [];
        foreach ($raw as $p) {
            if (!in_array($p['method'] ?? '', self::METHODS, true) || !is_int($p['amount'] ?? null)) {
                throw new ApiException('validation_failed', 422, ['fields' => ['payments' => 'invalid']]);
            }
            $out[] = ['method' => (string) $p['method'], 'amount' => (int) $p['amount']];
        }
        return $out;
    }

    private function maxDiscountBp(): int
    {
        $r = $this->tdb->select('tenant_settings', ['setting_key' => 'max_discount_bp'], null, 1)[0] ?? null;
        return $r === null ? 1000 : (int) json_decode((string) $r['setting_value'], true);
    }

    /** Remise au-delà du plafond : l'acteur doit avoir le droit, ou un responsable valide par PIN. */
    private function approver(mixed $a, string $userId): string
    {
        if ($this->authz->can($userId, 'sales.discount_override')) {
            return $userId;
        }
        return $this->validated($a, 'sales.discount_override', 'discount_needs_approval');
    }

    private function pharmacist(mixed $a, string $userId): string
    {
        return $this->authz->can($userId, 'narcotics.dispense') ? $userId : $this->validated($a, 'narcotics.dispense', 'prescription_required');
    }

    /** @param array<string, mixed>|null $a user_id + pin d'un collègue de la même pharmacie */
    private function validated(mixed $a, string $permission, string $error): string
    {
        $uid = is_array($a) ? (string) ($a['user_id'] ?? '') : '';
        $pin = is_array($a) ? (string) ($a['pin'] ?? '') : '';
        $u = $uid !== '' ? $this->db->one('SELECT tenant_id FROM users WHERE id = ? AND status = ?', [$uid, 'active']) : null;
        if ($u === null || (string) $u['tenant_id'] !== $this->ctx->require() || !$this->authz->can($uid, $permission) || !$this->pin->verify($uid, $pin)) {
            throw new ApiException($error, 403, ['approval' => $permission]);
        }
        return $uid;
    }

    private function str(mixed $v, int $max): ?string
    {
        $v = trim((string) $v);
        return $v === '' ? null : mb_substr($v, 0, $max);
    }
}
