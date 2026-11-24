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

/**
 * US12 : retours partiels ou totaux, annulation, avoir. Motif obligatoire, remise en stock décidée par un pharmacien (défaut : non),
 * jamais pour un produit réglementé. Les corrections sont des écritures nouvelles : vente, stock et caisse d'origine restent intacts.
 */
final class ReturnsService
{
    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly Clock $clock,
        private readonly SalesService $sales,
        private readonly CashService $cash,
        private readonly Sequences $seq,
        private readonly Authorizer $authz,
        private readonly PinService $pin,
        private readonly TenantContext $ctx,
    ) {
    }

    /**
     * @param array<string, mixed> $in lines[{sale_line_id, qty}], reason, restock?, refund_method(cash|credit_note|other), approver?
     * @return array<string, mixed>
     */
    public function create(string $saleId, array $in, string $userId): array
    {
        $sale = $this->sales->get($saleId);
        $reason = trim((string) ($in['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required']]);
        }
        $method = (string) ($in['refund_method'] ?? 'credit_note');
        if (!in_array($method, ['cash', 'credit_note', 'other'], true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['refund_method' => 'invalid']]);
        }
        // Retour : droit « annuler une vente », sinon validation d'un responsable par PIN. Hors délai : toujours un responsable.
        $window = $this->windowDays();
        $late = (strtotime(substr($this->clock->stamp(), 0, 10) . ' UTC') - strtotime(substr((string) $sale['created_at'], 0, 10) . ' UTC')) / 86400 > $window;
        $approver = null;
        if ($late || !$this->authz->can($userId, 'sales.cancel')) {
            $approver = $this->approver($in['approver'] ?? null);
        }
        $decider = $approver ?? $userId;

        $already = [];
        foreach ($this->tdb->select('sale_returns', ['sale_id' => $saleId]) as $r) {
            foreach ($this->tdb->select('return_lines', ['return_id' => $r['id']]) as $l) {
                $already[(string) $l['sale_line_id']] = ($already[(string) $l['sale_line_id']] ?? 0) + (int) $l['qty'];
            }
        }
        $byId = array_column($sale['lines'], null, 'id');
        $items = [];
        $amount = 0;
        foreach ((array) ($in['lines'] ?? []) as $l) {
            $line = $byId[(string) ($l['sale_line_id'] ?? '')] ?? throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'invalid']]);
            $qty = $l['qty'] ?? null;
            if (!is_int($qty) || $qty <= 0 || $qty > $line['qty'] - ($already[$line['id']] ?? 0)) {
                throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'quantity']]);
            }
            // Montant remboursé proportionnel au total de la ligne (remise comprise), arrondi à l'entier.
            $value = $qty === $line['qty'] ? (int) $line['line_total'] : SaleMath::ratio((int) $line['line_total'], $qty, (int) $line['qty']);
            $items[] = ['line' => $line, 'qty' => $qty, 'amount' => $value];
            $amount += $value;
        }
        if ($items === []) {
            throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'required']]);
        }
        $restock = !empty($in['restock']);
        if ($restock && !($this->authz->can($decider, 'stock.write') && $this->authz->can($decider, 'narcotics.dispense'))) {
            throw new ApiException('forbidden', 403, ['restock' => 'pharmacist_only']);
        }
        $session = null;
        if ($method === 'cash') {
            $session = $this->cash->current($userId) ?? throw new ApiException('cash_session_required', 409);
        }

        $id = Ulid::generate();
        $fullAfter = true;
        $this->db->transaction(function () use ($id, $sale, $saleId, $items, $reason, $restock, $method, $amount, $userId, $approver, $session, $already, &$fullAfter): void {
            $year = substr($this->clock->stamp(), 0, 4);
            $kind = substr((string) $sale['created_at'], 0, 10) === substr($this->clock->stamp(), 0, 10) && count($items) === count($sale['lines']) && $already === [] ? 'cancel' : 'return';
            $this->tdb->insert('sale_returns', [
                'id' => $id, 'number' => sprintf('R-%s-%05d', $year, $this->seq->next('return:' . $year)), 'sale_id' => $saleId, 'session_id' => $session['id'] ?? null, 'kind' => $kind, 'reason' => mb_substr($reason, 0, 190),
                'restock' => $restock ? 1 : 0, 'refund_method' => $method, 'refund_amount' => $amount, 'created_by' => $userId, 'approved_by' => $approver, 'created_at' => $this->clock->stamp(),
            ]);
            foreach ($items as $it) {
                $back = 0;
                if ($restock && $it['line']['regulatory_status'] === 'free') {
                    $back = $this->restock((string) $it['line']['product_id'], $saleId, $it['qty'], $userId, $reason);
                }
                $this->tdb->insert('return_lines', ['id' => Ulid::generate(), 'return_id' => $id, 'sale_line_id' => $it['line']['id'], 'product_id' => $it['line']['product_id'], 'qty' => $it['qty'], 'amount' => $it['amount'], 'restocked' => $back]);
                $already[$it['line']['id']] = ($already[$it['line']['id']] ?? 0) + $it['qty'];
            }
            foreach ($sale['lines'] as $l) {
                $fullAfter = $fullAfter && ($already[$l['id']] ?? 0) >= $l['qty'];
            }
            $this->tdb->update('sales', ['status' => $fullAfter ? ($kind === 'cancel' ? 'cancelled' : 'returned') : 'part_returned'], ['id' => $saleId]);
            if ($method === 'cash') {
                $this->tdb->insert('cash_movements', ['id' => Ulid::generate(), 'session_id' => $session['id'], 'kind' => 'refund', 'amount' => $amount, 'ref_id' => $id, 'reason' => mb_substr($reason, 0, 190), 'created_at' => $this->clock->stamp()]);
            }
            if ($method === 'credit_note') {
                $this->tdb->insert('credit_notes', ['id' => Ulid::generate(), 'number' => sprintf('AV-%s-%06d', $year, $this->seq->next('credit:' . $year)), 'return_id' => $id, 'sale_id' => $saleId, 'amount' => $amount, 'created_at' => $this->clock->stamp()]);
            }
        });
        return $this->get($id);
    }

    /** @return array<string, mixed> */
    public function get(string $id): array
    {
        $r = $this->tdb->select('sale_returns', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
        $r['refund_amount'] = (int) $r['refund_amount'];
        $r['restock'] = (int) $r['restock'];
        $r['lines'] = $this->tdb->select('return_lines', ['return_id' => $id]);
        $r['credit_note'] = $this->tdb->select('credit_notes', ['return_id' => $id], null, 1)[0] ?? null;
        return $r;
    }

    /** @return list<array<string, mixed>> */
    public function forSale(string $saleId): array
    {
        return array_map(fn ($r) => $this->get((string) $r['id']), $this->tdb->select('sale_returns', ['sale_id' => $saleId], 'created_at'));
    }

    /** @return array<string, mixed> */
    public function creditNote(string $returnId): array
    {
        $r = $this->get($returnId);
        $cn = $r['credit_note'] ?? throw new ApiException('not_found', 404);
        $names = array_column($this->tdb->select('products'), 'name', 'id');
        return ['number' => $cn['number'], 'date' => $cn['created_at'], 'amount' => (int) $cn['amount'], 'reason' => $r['reason'], 'sale' => $this->sales->get((string) $r['sale_id'])['number'],
            'lines' => array_map(static fn ($l) => ['name' => $names[$l['product_id']] ?? '', 'qty' => (int) $l['qty'], 'amount' => (int) $l['amount']], $r['lines'])];
    }

    /** Réintègre dans les lots d'origine de la vente. @return int quantité remise en stock */
    private function restock(string $productId, string $saleId, int $qty, string $userId, string $reason): int
    {
        $left = $qty;
        foreach ($this->tdb->select('stock_movements', ['product_id' => $productId, 'ref_id' => $saleId, 'kind' => 'sale']) as $m) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, -(int) $m['qty']);
            if ($take > 0 && $m['lot_id'] !== null) {
                $this->tdb->insert('stock_movements', ['id' => Ulid::generate(), 'product_id' => $productId, 'lot_id' => $m['lot_id'], 'qty' => $take, 'kind' => 'return', 'ref_type' => 'sale_return', 'ref_id' => $saleId, 'reversal_of' => null, 'reason' => mb_substr('Retour : ' . $reason, 0, 190), 'user_id' => $userId, 'created_at' => $this->clock->stamp()]);
                $left -= $take;
            }
        }
        return $qty - $left;
    }

    private function windowDays(): int
    {
        $r = $this->tdb->select('tenant_settings', ['setting_key' => 'return_days'], null, 1)[0] ?? null;
        return $r === null ? 7 : (int) json_decode((string) $r['setting_value'], true);
    }

    /** @param array<string, mixed>|null $a */
    private function approver(mixed $a): string
    {
        $uid = is_array($a) ? (string) ($a['user_id'] ?? '') : '';
        $u = $uid !== '' ? $this->db->one('SELECT tenant_id FROM users WHERE id = ? AND status = ?', [$uid, 'active']) : null;
        if ($u === null || (string) $u['tenant_id'] !== $this->ctx->require() || !$this->authz->can($uid, 'sales.cancel') || !$this->pin->verify($uid, is_array($a) ? (string) ($a['pin'] ?? '') : '')) {
            throw new ApiException('forbidden', 403, ['approval' => 'sales.cancel']);
        }
        return $uid;
    }
}
