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
use Sanara\Modules\Customers\CustomerService;
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
        private readonly CustomerService $customers,
    ) {
    }

    /**
     * @param array<string, mixed> $in lines[{product_id, qty, discount_bp?}], payments[{method, amount, reference?}], customer_id?, prescription?, pharmacist?, approver?
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
        }
        $credit = $this->credit($settle['applied']);
        $customer = trim((string) ($in['customer_id'] ?? ''));
        if ($credit > 0 && $customer === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['customer_id' => 'required']]);
        }
        $name = $customer === '' ? $this->str($in['customer_name'] ?? null, 120) : (string) $this->customers->get($customer)['name'];

        $id = Ulid::generate();
        $this->db->transaction(function () use ($id, $in, $userId, $session, $lines, $total, $settle, $presc, $pharmacist, $approver, $credit, $customer, $name): void {
            // En premier : le plafond est vérifié sur le compte verrouillé.
            if ($credit > 0) {
                $this->customers->charge($customer, $credit, $id, $userId);
            }
            $year = substr($this->clock->stamp(), 0, 4);
            $number = sprintf('V-%s-%s-%05d', $session['station'], $year, $this->seq->next('sale:' . $session['station'] . ':' . $year));
            $now = $this->clock->stamp();
            $this->tdb->insert('sales', [
                'id' => $id, 'number' => $number, 'invoice_number' => null, 'station' => $session['station'], 'session_id' => $session['id'], 'cashier_id' => $userId, 'status' => 'completed',
                'gross_total' => array_sum(array_column($lines, 'gross')), 'discount_total' => array_sum(array_column($lines, 'discount')), 'total' => $total, 'vat_total' => array_sum(array_column($lines, 'vat')),
                'customer_name' => $name, 'customer_id' => $customer === '' ? null : $customer, 'prescription_ref' => $this->str($presc['ref'] ?? null, 60), 'prescriber' => $this->str($presc['prescriber'] ?? null, 120),
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

    /**
     * Vente encaissée sur un poste hors-ligne : la marchandise est déjà partie, on l'enregistre telle que
     * le ticket l'indique et on signale les écarts. Sont refusés les cas qu'aucun PIN ne peut couvrir à
     * distance : produit réglementé sans compte pharmacien et remise non autorisée.
     * À appeler dans une transaction.
     *
     * @param array<string, mixed> $in saleId, sessionId, number, lines[{productId, qty, unitPrice, discountBp, vatBp}], payments[], customerId?, customerName?, prescription?
     * @return list<array{kind: string, details: array<string, mixed>}> écarts à signaler
     */
    public function recordOffline(array $in, string $userId, string $at): array
    {
        $id = (string) ($in['saleId'] ?? '');
        $session = $this->cash->session((string) ($in['sessionId'] ?? ''));
        if ($session['opened_by'] !== $userId) {
            throw new ApiException('forbidden', 403);
        }
        $number = (string) ($in['number'] ?? '');
        if (!Ulid::isValid($id) || preg_match('/^V-' . preg_quote((string) $session['station'], '/') . '-(\d{4})-(\d{5,9})$/', $number, $m) !== 1) {
            throw new ApiException('validation_failed', 422, ['fields' => [Ulid::isValid($id) ? 'number' : 'saleId' => 'invalid']]);
        }
        if ($this->tdb->select('sales', ['id' => $id], null, 1) !== []) {
            throw new ApiException('conflict', 409, ['saleId' => $id]);
        }
        if ($this->tdb->select('sales', ['number' => $number], null, 1) !== []) {
            throw new ApiException('number_taken', 409, ['number' => $number]);
        }

        $conflicts = [];
        $lines = [];
        $maxBp = $this->maxDiscountBp();
        foreach ((array) ($in['lines'] ?? []) as $r) {
            $qty = $r['qty'] ?? null;
            $price = $r['unitPrice'] ?? null;
            $bp = $r['discountBp'] ?? 0;
            $vat = $r['vatBp'] ?? null;
            if (!is_int($qty) || $qty <= 0 || !is_int($price) || $price < 0 || !is_int($bp) || $bp < 0 || $bp > 10000 || !is_int($vat) || $vat < 0 || $vat > 10000) {
                throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'invalid']]);
            }
            $p = $this->tdb->select('products', ['id' => (string) ($r['productId'] ?? '')], null, 1)[0] ?? throw new ApiException('not_found', 404);
            if ($bp > 0 && !$this->authz->can($userId, 'sales.discount')) {
                throw new ApiException('forbidden', 403);
            }
            if ($bp > $maxBp && !$this->authz->can($userId, 'sales.discount_override')) {
                throw new ApiException('discount_needs_approval', 403);
            }
            if ($price !== (int) $p['sale_price'] || $vat !== (int) $p['vat_bp']) {
                $conflicts[] = ['kind' => 'price_mismatch', 'details' => ['productId' => $p['id'], 'sold' => $price, 'current' => (int) $p['sale_price'], 'soldVatBp' => $vat, 'currentVatBp' => (int) $p['vat_bp']]];
            }
            if ((int) $p['active'] !== 1) {
                $conflicts[] = ['kind' => 'product_inactive', 'details' => ['productId' => $p['id']]];
            }
            $lines[] = ['product_id' => (string) $p['id'], 'name' => (string) $p['name'], 'status' => (string) $p['regulatory_status'], 'qty' => $qty, 'unit_price' => $price, 'discount_bp' => $bp, 'vat_bp' => $vat]
                + SaleMath::line($qty, $price, $bp, $vat);
        }
        if ($lines === []) {
            throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'required']]);
        }

        $presc = (array) ($in['prescription'] ?? []);
        $regulated = array_filter($lines, static fn ($l) => $l['status'] !== 'free') !== [];
        if ($regulated) {
            $complete = trim((string) ($presc['ref'] ?? '')) !== '' && trim((string) ($presc['prescriber'] ?? '')) !== '' && trim((string) ($presc['patientName'] ?? '')) !== '';
            if (!$complete || !$this->authz->can($userId, 'narcotics.dispense')) {
                throw new ApiException('prescription_required', 422);
            }
        }

        $raw = array_values((array) ($in['payments'] ?? []));
        $total = array_sum(array_column($lines, 'total'));
        foreach ($raw as $p) {
            $method = (string) ($p['method'] ?? '');
            // Le Mobile Money n'est proposé par le poste que réseau présent (règle côté poste) ; référence exigée.
            if (in_array($method, ['momo_mtn', 'momo_orange', 'card'], true) && trim((string) ($p['reference'] ?? '')) === '') {
                throw new ApiException('validation_failed', 422, ['fields' => ['payments.reference' => 'required']]);
            }
        }
        $settle = SaleMath::settle($total, $this->payments($raw)) ?? throw new ApiException('payment_insufficient', 422, ['total' => $total]);

        if ($session['status'] !== 'open') {
            $conflicts[] = ['kind' => 'session_closed', 'details' => ['sessionId' => $session['id']]];
        }
        $credit = $this->credit($settle['applied']);
        $customer = trim((string) ($in['customerId'] ?? ''));
        $name = $this->str($in['customerName'] ?? null, 120);
        if ($credit > 0 && $customer === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['customerId' => 'required']]);
        }
        if ($credit > 0) {
            // La marchandise est partie : la dette est inscrite et le dépassement signalé.
            $charged = $this->customers->charge($customer, $credit, $id, $userId, true, $at);
            $name = $charged['name'];
            if ($charged['over'] > 0) {
                $conflicts[] = ['kind' => 'credit_limit_exceeded', 'details' => ['customerId' => $customer, 'over' => $charged['over']]];
            }
        } elseif ($customer !== '') {
            $name = (string) $this->customers->get($customer)['name'];
        }
        $this->tdb->insert('sales', [
            'id' => $id, 'number' => $number, 'invoice_number' => null, 'station' => $session['station'], 'session_id' => $session['id'], 'cashier_id' => $userId, 'status' => 'completed',
            'gross_total' => array_sum(array_column($lines, 'gross')), 'discount_total' => array_sum(array_column($lines, 'discount')), 'total' => $total, 'vat_total' => array_sum(array_column($lines, 'vat')),
            'customer_name' => $name, 'customer_id' => $customer === '' ? null : $customer, 'prescription_ref' => $this->str($presc['ref'] ?? null, 60), 'prescriber' => $this->str($presc['prescriber'] ?? null, 120),
            'patient_name' => $this->str($presc['patientName'] ?? null, 120), 'pharmacist_id' => $regulated ? $userId : null,
            'discount_approved_by' => max(array_column($lines, 'discount_bp')) > $maxBp ? $userId : null, 'printed_count' => 1, 'created_at' => $at,
        ]);
        $this->seq->reserve('sale:' . $session['station'] . ':' . $m[1], (int) $m[2]);
        foreach ($lines as $l) {
            $this->tdb->insert('sale_lines', [
                'id' => Ulid::generate(), 'sale_id' => $id, 'product_id' => $l['product_id'], 'name' => $l['name'], 'regulatory_status' => $l['status'], 'qty' => $l['qty'], 'unit_price' => $l['unit_price'],
                'discount_bp' => $l['discount_bp'], 'vat_bp' => $l['vat_bp'], 'line_total' => $l['total'], 'vat_amount' => $l['vat'],
            ]);
            $missing = 0;
            foreach ($this->stock->issue($l['product_id'], $l['qty'], 'sale', 'sale', $id, $userId, 'Vente ' . $number, true, $at) as $a) {
                $missing += $a['lot_id'] === null ? $a['qty'] : 0;
            }
            if ($missing > 0) {
                $conflicts[] = ['kind' => 'stock_shortage', 'details' => ['productId' => $l['product_id'], 'missing' => $missing]];
            }
        }
        foreach ($settle['applied'] as $i => $p) {
            $this->tdb->insert('payments', [
                'id' => Ulid::generate(), 'sale_id' => $id, 'session_id' => $session['id'], 'method' => $p['method'], 'amount' => $p['amount'], 'tendered' => $p['tendered'],
                'reference' => $this->str($raw[$i]['reference'] ?? null, 80), 'created_at' => $at,
            ]);
        }
        return $conflicts;
    }

    /** @param list<array<string, mixed>> $applied montant porté au compte du client */
    private function credit(array $applied): int
    {
        return array_sum(array_map(static fn ($p) => $p['method'] === 'credit' ? (int) $p['amount'] : 0, $applied));
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
