<?php
declare(strict_types=1);

namespace Sanara\Modules\Customers;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Sales\CashService;
use Sanara\Modules\Sales\Sequences;

/**
 * US13 : comptes clients. Le solde dû est la somme des écritures (achats à crédit en plus, règlements et
 * retours en moins) ; un achat ne dépasse jamais le plafond, sauf vente déjà faite hors-ligne.
 */
final class CustomerService
{
    /** Modes d'encaissement d'une dette ; tous sauf les espèces demandent une référence. */
    private const METHODS = ['cash', 'momo_mtn', 'momo_orange', 'card', 'bank'];

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly Clock $clock,
        private readonly CashService $cash,
        private readonly Sequences $seq,
        private readonly TenantContext $ctx,
    ) {
    }

    /** @return list<array{id: string, name: string, phone: string, credit_limit: int, balance: int, active: bool}> */
    public function search(string $q): array
    {
        $tid = $this->ctx->require();
        $params = [$tid];
        $filter = '';
        if (trim($q) !== '') {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($q)) . '%';
            $filter = " AND (c.name LIKE ? ESCAPE '!' OR c.phone LIKE ? ESCAPE '!')";
            $params[] = $like;
            $params[] = $like;
        }
        $rows = $this->db->all(
            'SELECT c.id, c.name, c.phone, c.credit_limit, c.active, COALESCE(SUM(e.amount), 0) AS balance FROM customers c'
            . ' LEFT JOIN customer_entries e ON e.tenant_id = c.tenant_id AND e.customer_id = c.id'
            . " WHERE c.tenant_id = ?$filter GROUP BY c.id, c.name, c.phone, c.credit_limit, c.active ORDER BY c.name LIMIT 100",
            $params,
        );
        return array_map(static fn (array $c) => [
            'id' => (string) $c['id'], 'name' => (string) $c['name'], 'phone' => (string) $c['phone'],
            'credit_limit' => (int) $c['credit_limit'], 'balance' => (int) $c['balance'], 'active' => (int) $c['active'] === 1,
        ], $rows);
    }

    /** @return array<string, mixed> fiche, solde et relevé */
    public function get(string $id): array
    {
        $c = $this->row($id);
        $entries = array_map(static fn (array $e) => [
            'id' => $e['id'], 'kind' => $e['kind'], 'amount' => (int) $e['amount'], 'method' => $e['method'], 'reference' => $e['reference'],
            'receipt_number' => $e['receipt_number'], 'sale_id' => $e['sale_id'], 'created_at' => $e['created_at'],
        ], $this->lines($id));
        return [
            'id' => $c['id'], 'name' => $c['name'], 'phone' => (string) $c['phone'], 'email' => (string) $c['email'], 'credit_limit' => (int) $c['credit_limit'],
            'reminder_consent' => (int) $c['reminder_consent'] === 1, 'active' => (int) $c['active'] === 1,
            'balance' => array_sum(array_column($entries, 'amount')), 'entries' => $entries,
        ];
    }

    /** @param array<string, mixed> $in */
    public function create(array $in): string
    {
        $id = Ulid::generate();
        $now = $this->clock->stamp();
        $this->tdb->insert('customers', ['id' => $id] + $this->fields($in) + ['created_at' => $now, 'updated_at' => $now]);
        return $id;
    }

    /** @param array<string, mixed> $in */
    public function update(string $id, array $in): void
    {
        $this->row($id);
        $this->tdb->update('customers', $this->fields($in) + ['updated_at' => $this->clock->stamp()], ['id' => $id]);
    }

    /**
     * Inscrit un achat à crédit. À appeler en premier dans la transaction de la vente.
     *
     * @return array{name: string, over: int} dépassement accepté, possible seulement avec $allowOver
     */
    public function charge(string $id, int $amount, string $saleId, string $userId, bool $allowOver = false, ?string $at = null): array
    {
        $c = $this->lock($id);
        $available = (int) $c['credit_limit'] - $this->balance($id);
        $active = (int) $c['active'] === 1;
        if (!$allowOver) {
            if (!$active) {
                throw new ApiException('customer_inactive', 422);
            }
            if ($amount > $available) {
                throw new ApiException('credit_limit_exceeded', 422, ['available' => max(0, $available)]);
            }
        }
        $this->entry($id, 'sale', $amount, $userId, ['sale_id' => $saleId, 'created_at' => $at ?? $this->clock->stamp()]);
        // Compte désactivé entre-temps : tout l'achat est à signaler.
        $over = $active ? max(0, $amount - max(0, $available)) : $amount;
        return ['name' => (string) $c['name'], 'over' => $over];
    }

    /** Retour d'une vente porté au compte : la dette diminue, sans devenir négative. */
    public function refund(string $id, int $amount, string $saleId, string $returnId, string $userId): void
    {
        $this->lock($id);
        if ($amount > $this->balance($id)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['refund_method' => 'exceeds_debt']]);
        }
        $this->entry($id, 'return', -$amount, $userId, ['sale_id' => $saleId, 'return_id' => $returnId]);
    }

    /**
     * Règlement total ou partiel d'une dette, avec reçu numéroté. Les espèces entrent dans la caisse
     * ouverte par l'encaisseur.
     *
     * @param array<string, mixed> $in amount, method, reference?
     * @return array{id: string, receipt_number: string, balance: int}
     */
    public function pay(string $id, array $in, string $userId): array
    {
        $amount = $in['amount'] ?? null;
        $method = (string) ($in['method'] ?? '');
        $reference = mb_substr(trim((string) ($in['reference'] ?? '')), 0, 80);
        $errors = [];
        if (!is_int($amount) || $amount <= 0) {
            $errors['amount'] = 'invalid';
        }
        if (!in_array($method, self::METHODS, true)) {
            $errors['method'] = 'invalid';
        } elseif ($method !== 'cash' && $reference === '') {
            $errors['reference'] = 'required';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        $session = null;
        if ($method === 'cash') {
            $session = $this->cash->current($userId) ?? throw new ApiException('cash_session_required', 409);
        }

        return $this->db->transaction(function () use ($id, $amount, $method, $reference, $userId, $session): array {
            $this->lock($id);
            $balance = $this->balance($id);
            if ($amount > $balance) {
                throw new ApiException('validation_failed', 422, ['fields' => ['amount' => 'exceeds_debt']]);
            }
            $year = substr($this->clock->stamp(), 0, 4);
            $number = sprintf('REC-%s-%06d', $year, $this->seq->next('customer-receipt:' . $year));
            $entry = $this->entry($id, 'payment', -$amount, $userId, ['method' => $method, 'reference' => $reference === '' ? null : $reference, 'receipt_number' => $number]);
            if ($session !== null) {
                $this->tdb->insert('cash_movements', ['id' => Ulid::generate(), 'session_id' => $session['id'], 'kind' => 'debt_payment', 'amount' => $amount, 'ref_id' => $entry, 'reason' => $number, 'created_at' => $this->clock->stamp()]);
            }
            return ['id' => $entry, 'receipt_number' => $number, 'balance' => $balance - $amount];
        });
    }

    /** @return array<string, mixed> données du reçu */
    public function receipt(string $id, string $entryId): array
    {
        $c = $this->row($id);
        $e = $this->tdb->select('customer_entries', ['id' => $entryId, 'customer_id' => $id, 'kind' => 'payment'], null, 1)[0] ?? throw new ApiException('not_found', 404);
        // Solde juste après ce règlement.
        $after = 0;
        foreach ($this->lines($id) as $line) {
            $after += (int) $line['amount'];
            if ($line['id'] === $entryId) {
                break;
            }
        }
        return ['number' => $e['receipt_number'], 'date' => $e['created_at'], 'customer' => $c['name'], 'amount' => -(int) $e['amount'], 'method' => $e['method'], 'reference' => $e['reference'], 'balance' => $after];
    }

    public function balance(string $id): int
    {
        // Lecture verrouillante sous MySQL : elle voit les écritures validées depuis le début de la transaction.
        $suffix = $this->db->driver() === 'mysql' ? ' FOR UPDATE' : '';
        return (int) $this->db->one('SELECT COALESCE(SUM(amount), 0) AS n FROM customer_entries WHERE tenant_id = ? AND customer_id = ?' . $suffix, [$this->ctx->require(), $id])['n'];
    }

    /** Écritures dans l'ordre où elles ont eu lieu (les identifiants départagent une même heure). @return list<array<string, mixed>> */
    private function lines(string $id): array
    {
        $lines = $this->tdb->select('customer_entries', ['customer_id' => $id]);
        usort($lines, static fn (array $a, array $b) => [(string) $a['created_at'], (string) $a['id']] <=> [(string) $b['created_at'], (string) $b['id']]);
        return $lines;
    }

    /** @return array<string, mixed> */
    private function row(string $id): array
    {
        return $this->tdb->select('customers', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
    }

    /** Verrouille le compte : deux ventes simultanées ne dépassent pas ensemble le plafond. @return array<string, mixed> */
    private function lock(string $id): array
    {
        $this->tdb->update('customers', ['updated_at' => $this->clock->stamp()], ['id' => $id]);
        return $this->row($id);
    }

    /** @param array<string, mixed> $extra */
    private function entry(string $customer, string $kind, int $amount, string $userId, array $extra): string
    {
        $id = Ulid::generate();
        $this->tdb->insert('customer_entries', $extra + [
            'id' => $id, 'customer_id' => $customer, 'kind' => $kind, 'amount' => $amount, 'sale_id' => null, 'return_id' => null,
            'method' => null, 'reference' => null, 'receipt_number' => null, 'user_id' => $userId, 'created_at' => $this->clock->stamp(),
        ]);
        return $id;
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    private function fields(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        $phone = trim((string) ($in['phone'] ?? ''));
        $email = trim((string) ($in['email'] ?? ''));
        $limit = $in['credit_limit'] ?? null;
        $errors = [];
        if ($name === '' || mb_strlen($name) > 120) {
            $errors['name'] = 'invalid';
        }
        if (mb_strlen($phone) > 30) {
            $errors['phone'] = 'invalid';
        }
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'invalid';
        }
        if (!is_int($limit) || $limit < 0) {
            $errors['credit_limit'] = 'invalid';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        return [
            'name' => $name, 'phone' => $phone === '' ? null : $phone, 'email' => $email === '' ? null : $email, 'credit_limit' => $limit,
            'reminder_consent' => empty($in['reminder_consent']) ? 0 : 1, 'active' => ($in['active'] ?? true) ? 1 : 0,
        ];
    }
}
