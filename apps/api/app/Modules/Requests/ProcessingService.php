<?php
declare(strict_types=1);

namespace Sanara\Modules\Requests;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Stock\StockService;

/** US22 : file de traitement, prise en charge verrouillée, devis et retrait, côté pharmacie. */
final class ProcessingService
{
    private const LOCK_MINUTES = 15;
    private const ACCENTS = [
        'à' => 'a', 'â' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'î' => 'i', 'ï' => 'i',
        'ô' => 'o', 'ö' => 'o', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'µ' => 'u',
    ];
    private const OPEN = ['received', 'processing', 'photo_needed', 'quoted', 'accepted', 'ready'];

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly Clock $clock,
        private readonly EncryptedStorage $storage,
        private readonly StockService $stock,
        private readonly RequestLedger $ledger,
        private readonly EscalationService $escalation,
    ) {
    }

    /** Demandes en cours, urgences d'abord puis les plus anciennes. @return list<array<string, mixed>> */
    public function queue(bool $closed = false): array
    {
        $this->ledger->expire();
        $items = [];
        foreach ($this->tdb->select('patient_requests', [], 'created_at') as $r) {
            if (in_array($r['status'], self::OPEN, true) === $closed) {
                continue;
            }
            $items[] = $this->summary($r) + ['pages' => count($this->tdb->select('request_documents', ['request_id' => $r['id']]))];
        }
        usort($items, static fn (array $a, array $b) => [!$a['urgent'], $a['created_at'], $a['number']] <=> [!$b['urgent'], $b['created_at'], $b['number']]);
        return $closed ? array_slice(array_reverse($items), 0, 100) : $items;
    }

    /** @return array<string, mixed> */
    public function get(string $id, ?string $actor = null): array
    {
        $this->ledger->expire();
        $r = $this->request($id);
        $documents = $this->tdb->select('request_documents', ['request_id' => $id], 'created_at');
        // Le même fichier déjà reçu : ordonnance peut-être envoyée deux fois ou déjà délivrée (T178).
        $duplicates = [];
        foreach ($documents as $d) {
            foreach ($this->tdb->select('request_documents', ['sha256' => $d['sha256']]) as $same) {
                if ($same['request_id'] !== $id) {
                    $other = $this->request((string) $same['request_id']);
                    $duplicates[$other['id']] = ['number' => $other['number'], 'status' => $other['status']];
                }
            }
        }
        $events = $this->tdb->select('request_events', ['request_id' => $id], 'seq');
        $quote = $this->tdb->select('quotes', ['request_id' => $id], null, 1)[0] ?? null;
        return $this->summary($r) + [
            'mine' => $actor !== null && $r['status'] === 'processing' && $r['locked_by'] === $actor,
            'phone' => $r['phone'], 'advice' => $r['advice'], 'pickup_code' => $r['pickup_code'],
            'documents' => array_map(static fn (array $d) => ['id' => $d['id'], 'name' => $d['original_name'], 'mime' => $d['mime'], 'size' => (int) $d['size_bytes']], $documents),
            'duplicates' => array_values($duplicates),
            'events' => array_map(static fn (array $e) => ['kind' => $e['kind'], 'at' => $e['created_at'], 'note' => $e['note']], $events),
            'quote' => $quote === null ? null : [
                'total' => (int) $quote['total'], 'valid_until' => $quote['valid_until'],
                'lines' => array_map(static fn (array $l) => [
                    'id' => $l['id'], 'product_id' => $l['product_id'], 'label' => $l['label'], 'quantity' => (int) $l['quantity'], 'unit_price' => (int) $l['unit_price'],
                    'total' => (int) $l['line_total'], 'available' => (int) $l['available'] === 1, 'accepted' => $l['accepted'] === null ? null : (int) $l['accepted'] === 1,
                    'read_text' => $l['read_text'], 'corrected' => (int) $l['corrected'] === 1,
                ], $this->tdb->select('quote_lines', ['request_id' => $id], 'position')),
            ],
        ];
    }

    /** @return array{bytes: string, mime: string} */
    public function document(string $id, string $documentId): array
    {
        $this->request($id);
        $d = $this->tdb->select('request_documents', ['id' => $documentId, 'request_id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
        return ['bytes' => $this->storage->get((string) $d['storage_id']), 'mime' => (string) $d['mime']];
    }

    /** Prise en charge : un seul pharmacien à la fois, le verrou abandonné expire. */
    public function take(string $id, string $userId): void
    {
        $this->ledger->expire();
        $this->db->transaction(function () use ($id, $userId): void {
            $r = $this->request($id);
            if (!in_array($r['status'], ['received', 'processing', 'photo_needed'], true)) {
                throw new ApiException('conflict', 409);
            }
            $stale = $this->clock->stamp($this->clock->now()->modify('-' . self::LOCK_MINUTES . ' minutes'));
            if ($r['locked_by'] !== null && $r['locked_by'] !== $userId && $r['locked_at'] > $stale) {
                throw new ApiException('request_locked', 409);
            }
            // La condition sur l'ancien porteur départage deux prises simultanées.
            $won = $this->tdb->update(
                'patient_requests',
                ['status' => 'processing', 'locked_by' => $userId, 'locked_at' => $this->clock->stamp(), 'updated_at' => $this->clock->stamp()],
                ['id' => $id, 'locked_by' => $r['locked_by']],
            );
            if ($won !== 1) {
                throw new ApiException('request_locked', 409);
            }
            if ($r['locked_by'] !== $userId) {
                $this->ledger->event($id, 'taken', $userId);
            }
            $this->escalation->stopIfTaken($id);
        });
    }

    public function release(string $id, string $userId): void
    {
        $this->held($id, $userId);
        $this->ledger->move($id, ['status' => 'received', 'locked_by' => null, 'locked_at' => null]);
    }

    /** Document illisible : le patient est invité à renvoyer une photo. */
    public function retake(string $id, string $userId): void
    {
        $this->held($id, $userId);
        $this->db->transaction(function () use ($id, $userId): void {
            $this->ledger->move($id, ['status' => 'photo_needed', 'locked_by' => null, 'locked_at' => null]);
            $this->ledger->event($id, 'photo_needed', $userId);
        });
    }

    public function cancel(string $id, string $userId, string $reason): void
    {
        $r = $this->request($id);
        if (!in_array($r['status'], self::OPEN, true)) {
            throw new ApiException('conflict', 409);
        }
        if (trim($reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required']]);
        }
        $this->db->transaction(function () use ($id, $userId, $reason): void {
            $this->ledger->move($id, ['status' => 'cancelled', 'pickup_code' => null, 'locked_by' => null, 'locked_at' => null]);
            $this->ledger->event($id, 'cancelled', $userId, trim($reason));
        });
    }

    /**
     * Devis aux prix du catalogue ; le stock disponible, moins ce qui est promis à d'autres, est exigé.
     *
     * @param array<int, mixed> $lines [{product_id?, label?, quantity, read_text?, read_confidence?}]
     */
    public function quote(string $id, string $userId, array $lines): void
    {
        $this->held($id, $userId);
        if ($lines === [] || count($lines) > 50) {
            throw new ApiException('validation_failed', 422, ['fields' => ['lines' => 'required']]);
        }
        $this->db->transaction(function () use ($id, $userId, $lines): void {
            $quoteId = Ulid::generate();
            $total = 0;
            $asked = [];
            foreach (array_values($lines) as $position => $line) {
                $qty = $line['quantity'] ?? null;
                if (!is_int($qty) || $qty < 1 || $qty > 999) {
                    throw new ApiException('validation_failed', 422, ['fields' => ["lines.$position.quantity" => 'invalid']]);
                }
                $productId = is_string($line['product_id'] ?? null) && $line['product_id'] !== '' ? $line['product_id'] : null;
                $price = 0;
                if ($productId === null) {
                    // Produit introuvable : indiqué comme indisponible.
                    $label = is_string($line['label'] ?? null) ? trim($line['label']) : '';
                    if ($label === '') {
                        throw new ApiException('validation_failed', 422, ['fields' => ["lines.$position.label" => 'required']]);
                    }
                    $available = false;
                } else {
                    $p = $this->tdb->select('products', ['id' => $productId], null, 1)[0] ?? throw new ApiException('not_found', 404);
                    $label = (string) $p['name'];
                    $available = ($line['available'] ?? true) !== false;
                    if ($available) {
                        if ((int) $p['active'] !== 1) {
                            throw new ApiException('conflict', 409, ['product_id' => $productId]);
                        }
                        $asked[$productId] = ($asked[$productId] ?? 0) + $qty;
                        $free = $this->stock->availableQty($productId) - $this->ledger->reserved($productId, $id);
                        if ($asked[$productId] > $free) {
                            throw new ApiException('insufficient_stock', 422, ['product_id' => $productId, 'available' => max(0, $free)]);
                        }
                        $price = (int) $p['sale_price'];
                    }
                }
                $read = is_string($line['read_text'] ?? null) && trim($line['read_text']) !== '' ? mb_substr(trim($line['read_text']), 0, 190) : null;
                $confidence = is_int($line['read_confidence'] ?? null) ? max(0, min(100, $line['read_confidence'])) : null;
                $total += $price * $qty;
                $this->tdb->insert('quote_lines', [
                    'id' => Ulid::generate(), 'quote_id' => $quoteId, 'request_id' => $id, 'position' => $position + 1, 'product_id' => $productId,
                    'label' => mb_substr($label, 0, 190), 'quantity' => $qty, 'unit_price' => $price, 'line_total' => $price * $qty,
                    'available' => $available ? 1 : 0, 'accepted' => null, 'read_text' => $read, 'read_confidence' => $confidence,
                    // Mesure de fiabilité : la lecture proposée a-t-elle été changée ?
                    'corrected' => $read !== null && mb_strtolower($read) !== mb_strtolower($label) ? 1 : 0,
                ]);
            }
            $this->tdb->insert('quotes', [
                'id' => $quoteId, 'request_id' => $id, 'total' => $total, 'created_by' => $userId, 'created_at' => $this->clock->stamp(),
                'valid_until' => $this->clock->stamp($this->clock->now()->modify('+' . RequestLedger::QUOTE_HOURS . ' hours')),
            ]);
            $this->ledger->move($id, ['status' => 'quoted', 'locked_by' => null, 'locked_at' => null]);
            $this->ledger->event($id, 'quoted', $userId);
        });
    }

    /** Commande préparée : le délai de retrait repart de maintenant. */
    public function ready(string $id, string $userId): void
    {
        $this->ledger->expire();
        $this->request($id);
        $this->db->transaction(function () use ($id, $userId): void {
            $now = $this->clock->stamp();
            if ($this->tdb->update('patient_requests', ['status' => 'ready', 'pickup_expires_at' => $this->ledger->holdUntil(), 'updated_at' => $now], ['id' => $id, 'status' => 'accepted']) !== 1) {
                throw new ApiException('conflict', 409);
            }
            $this->ledger->event($id, 'ready', $userId);
        });
    }

    /** Au comptoir : la commande retrouvée par son code, sans ressaisie. @return array<string, mixed> */
    public function pickup(string $code): array
    {
        $this->ledger->expire();
        $code = strtoupper(trim($code));
        $r = $code === '' ? null : ($this->tdb->select('patient_requests', ['pickup_code' => $code], null, 1)[0] ?? null);
        if ($r === null || !in_array($r['status'], ['accepted', 'ready'], true)) {
            throw new ApiException('not_found', 404);
        }
        $lines = array_map(function (array $l): array {
            $p = $this->tdb->select('products', ['id' => $l['product_id']], null, 1)[0];
            return [
                'product_id' => $l['product_id'], 'name' => $l['label'], 'quantity' => (int) $l['quantity'], 'unit_price' => (int) $l['unit_price'],
                'regulatory_status' => $p['regulatory_status'], 'vat_bp' => (int) $p['vat_bp'],
            ];
        }, $this->ledger->accepted((string) $r['id']));
        return [
            'request_id' => $r['id'], 'number' => $r['number'], 'first_name' => $r['first_name'], 'status' => $r['status'],
            'total' => array_sum(array_map(static fn (array $l) => $l['quantity'] * $l['unit_price'], $lines)), 'lines' => $lines,
        ];
    }

    /**
     * Ce que le lecteur a appris dans cette pharmacie : pour chaque texte lu, le produit retenu par le
     * pharmacien et le nombre de confirmations. Plus la fiabilité mesurée sur les 90 derniers jours.
     *
     * @return array{links: list<array<string, mixed>>, reliability: array{lines: int, corrected: int, accepted_rate: ?int}}
     */
    public function lexicon(): array
    {
        $since = $this->clock->stamp($this->clock->now()->modify('-90 days'));
        $quotes = array_column(array_filter($this->tdb->select('quotes'), static fn (array $q) => $q['created_at'] >= $since), 'id', 'id');
        $links = [];
        $lines = 0;
        $corrected = 0;
        foreach ($this->tdb->select('quote_lines') as $l) {
            if ($l['read_text'] === null) {
                continue;
            }
            if (isset($quotes[$l['quote_id']])) {
                $lines++;
                $corrected += (int) $l['corrected'];
            }
            if ($l['product_id'] === null) {
                continue;
            }
            // Même clé que le poste (readKey) : minuscules, sans accents ni ponctuation.
            $key = trim((string) preg_replace('/[^a-z0-9]+/', ' ', strtr(mb_strtolower((string) $l['read_text']), self::ACCENTS)));
            if ($key === '') {
                continue;
            }
            $id = $key . '|' . $l['product_id'];
            $links[$id] ??= ['read' => $key, 'product_id' => (string) $l['product_id'], 'uses' => 0];
            $links[$id]['uses']++;
        }
        $out = [];
        foreach ($links as $link) {
            $p = $this->tdb->select('products', ['id' => $link['product_id']], null, 1)[0] ?? null;
            if ($p === null || (int) $p['active'] !== 1) {
                continue;
            }
            $out[] = ['read' => $link['read'], 'uses' => $link['uses'], 'product' => [
                'id' => $p['id'], 'name' => $p['name'], 'dci' => $p['dci'], 'strength' => $p['strength'] ?? null,
                'form' => $p['form'] ?? null, 'regulatory_status' => $p['regulatory_status'],
                'sale_price' => (int) $p['sale_price'], 'vat_bp' => (int) $p['vat_bp'],
            ]];
        }
        usort($out, static fn (array $a, array $b) => $b['uses'] <=> $a['uses']);
        return [
            'links' => array_slice($out, 0, 3000),
            'reliability' => ['lines' => $lines, 'corrected' => $corrected, 'accepted_rate' => $lines === 0 ? null : intdiv(($lines - $corrected) * 100, $lines)],
        ];
    }

    /** @param array<string, mixed> $r @return array<string, mixed> */
    private function summary(array $r): array
    {
        $holder = $r['locked_by'] === null ? null : $this->db->one('SELECT email FROM users WHERE id = ?', [$r['locked_by']]);
        $quote = $this->tdb->select('quotes', ['request_id' => $r['id']], null, 1)[0] ?? null;
        return [
            'id' => $r['id'], 'number' => $r['number'], 'status' => $r['status'], 'urgent' => (int) $r['urgent'] === 1, 'first_name' => $r['first_name'],
            'created_at' => $r['created_at'], 'locked_by' => $r['locked_by'], 'locked_by_name' => $holder['email'] ?? null,
            'total' => $quote === null ? null : (int) $quote['total'],
        ];
    }

    /** @return array<string, mixed> */
    private function request(string $id): array
    {
        return $this->tdb->select('patient_requests', ['id' => $id], null, 1)[0] ?? throw new ApiException('not_found', 404);
    }

    /** La demande doit être prise en charge par l'acteur. */
    private function held(string $id, string $userId): void
    {
        $this->ledger->expire();
        $r = $this->request($id);
        if ($r['status'] !== 'processing' || $r['locked_by'] !== $userId) {
            throw new ApiException('request_locked', 409);
        }
    }
}
