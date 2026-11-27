<?php
declare(strict_types=1);

namespace Sanara\Modules\Requests;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Applications\PublicPharmacyService;
use Sanara\Modules\Sales\Sequences;

/**
 * US21, US23, US26 : ce qu'un patient sans compte peut faire. La pharmacie est déduite de la fiche
 * publique ou du code de suivi secret, jamais d'un identifiant de pharmacie fourni par le client.
 * Aucun document ni numéro de téléphone n'est renvoyé au public.
 */
final class PatientService
{
    public const CONSENT_VERSION = '1';
    public const MAX_PAGES = 5;
    private const URGENT_PER_DAY = 3;
    /** Retrait en officine, ou coursier envoyé par le patient (il paie au comptoir). */
    public const MODES = ['pickup', 'own_delivery'];
    private const TYPES = ['application/pdf', 'image/jpeg', 'image/png'];

    public function __construct(
        private readonly Db $db,
        private readonly TenantDb $tdb,
        private readonly TenantContext $ctx,
        private readonly Clock $clock,
        private readonly EncryptedStorage $storage,
        private readonly JobQueue $jobs,
        private readonly Sequences $seq,
        private readonly RequestLedger $ledger,
        private readonly PublicPharmacyService $pharmacies,
        private readonly PresenceService $presence,
        private readonly EscalationService $escalation,
    ) {
    }

    /**
     * @param array<string, mixed> $in consent, first_name, phone, message?, urgent?, locale?, documents[{name, base64}]
     * @return array{code: string, number: string, status: string, advice: ?string}
     */
    public function submit(string $publicId, array $in, string $ip): array
    {
        $this->open($publicId);
        // Sans accord, rien n'est enregistré.
        if (($in['consent'] ?? null) !== true) {
            throw new ApiException('consent_required', 422);
        }
        $firstName = is_string($in['first_name'] ?? null) ? trim($in['first_name']) : '';
        $phone = is_string($in['phone'] ?? null) ? trim($in['phone']) : '';
        $message = is_string($in['message'] ?? null) ? trim($in['message']) : '';
        $errors = [];
        if ($firstName === '' || mb_strlen($firstName) > 80) {
            $errors['first_name'] = 'required';
        }
        if (mb_strlen($phone) > 32 || preg_match('/^\+?[0-9 .()-]+$/', $phone) !== 1 || strlen((string) preg_replace('/\D/', '', $phone)) < 8) {
            $errors['phone'] = 'phone';
        }
        $mode = $in['mode'] ?? 'pickup';
        if (!in_array($mode, self::MODES, true)) {
            $errors['mode'] = 'unknown';
        }
        if (mb_strlen($message) > 500) {
            $errors['message'] = 'too_long';
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        $files = $this->files($in['documents'] ?? null, self::MAX_PAGES);

        $advice = Guardrails::severe($message) ? 'emergency' : null;
        $phoneKey = substr((string) preg_replace('/\D/', '', $phone), -9);
        $wanted = ($in['urgent'] ?? false) === true || $advice !== null;
        // T190 : au-delà de trois urgences en 24 h pour un même numéro, la demande reste normale.
        $limited = $wanted && $this->urgentCount($phoneKey) >= self::URGENT_PER_DAY;
        $urgent = $wanted && !$limited;
        $id = Ulid::generate();
        $code = strtoupper(bin2hex(random_bytes(20)));
        $now = $this->clock->stamp();
        $number = (string) $this->db->transaction(function () use ($id, $code, $now, $firstName, $phone, $message, $urgent, $advice, $files, $in, $ip, $phoneKey, $mode): string {
            $number = sprintf('SAN-%05d', $this->seq->next('request'));
            $this->tdb->insert('patient_requests', [
                'id' => $id, 'code' => $code, 'number' => $number, 'first_name' => $firstName, 'phone' => $phone, 'phone_key' => $phoneKey, 'mode' => $mode, 'status' => 'received',
                'urgent' => $urgent ? 1 : 0, 'advice' => $advice, 'locale' => ($in['locale'] ?? 'fr') === 'en' ? 'en' : 'fr',
                'consent_version' => self::CONSENT_VERSION, 'consent_at' => $now, 'consent_ip_hash' => hash('sha256', $ip),
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->store($id, $files);
            $this->ledger->event($id, 'received');
            if ($urgent) {
                $this->ledger->event($id, 'urgent');
            }
            if ($message !== '') {
                $this->ledger->event($id, 'message', null, $message);
            }
            return $number;
        });
        if ($urgent) {
            $this->escalation->start($id);
        } elseif (!$this->presence->anyoneOnline()) {
            // US24 : personne n'est connecté, la pharmacie est prévenue de la nouvelle demande.
            $this->escalation->notifyByMail($number, 'request_new');
        }
        return ['code' => $code, 'number' => $number, 'status' => 'received', 'advice' => $advice, 'urgent_limited' => $limited];
    }

    /** @return array<string, mixed> */
    public function view(string $code): array
    {
        $r = $this->find($code);
        if ($this->escalation->advance((string) $r['id'])) {
            $r = $this->tdb->select('patient_requests', ['id' => $r['id']], null, 1)[0];
        }
        $pharmacy = $this->db->one('SELECT p.id, t.name, t.phone FROM tenants t LEFT JOIN public_pharmacies p ON p.tenant_id = t.id WHERE t.id = ?', [$this->ctx->require()]);
        $sheet = null;
        try {
            $sheet = $this->pharmacies->find((string) $pharmacy['id']);
        } catch (ApiException) {
            // Pharmacie retirée de la recherche : la demande reste consultable.
        }
        $events = $this->tdb->select('request_events', ['request_id' => $r['id']], 'seq');
        $quote = $this->tdb->select('quotes', ['request_id' => $r['id']], null, 1)[0] ?? null;
        $held = in_array($r['status'], ['accepted', 'ready'], true);
        return [
            'number' => $r['number'], 'status' => $r['status'], 'urgent' => (int) $r['urgent'] === 1, 'advice' => $r['advice'],
            'first_name' => $r['first_name'], 'created_at' => $r['created_at'], 'escalation' => $r['escalation_state'], 'mode' => $r['mode'] ?? 'pickup',
            'pages' => count($this->tdb->select('request_documents', ['request_id' => $r['id']])),
            'pharmacy' => [
                'id' => $sheet === null ? null : $sheet['id'], 'name' => $pharmacy['name'], 'phone' => $pharmacy['phone'],
                'online' => $this->presence->anyoneOnline(),
                'open' => $sheet['open'] ?? null, 'closes' => $sheet['closes'] ?? null, 'next_opening' => $sheet['next_opening'] ?? null,
            ],
            'events' => array_map(static fn (array $e) => ['kind' => $e['kind'], 'at' => $e['created_at']], $events),
            'quote' => $quote === null ? null : [
                'total' => (int) $quote['total'], 'valid_until' => $quote['valid_until'],
                'lines' => array_map(static fn (array $l) => [
                    'id' => $l['id'], 'label' => $l['label'], 'quantity' => (int) $l['quantity'], 'unit_price' => (int) $l['unit_price'],
                    'total' => (int) $l['line_total'], 'available' => (int) $l['available'] === 1,
                    'accepted' => $l['accepted'] === null ? null : (int) $l['accepted'] === 1, 'replaces' => $l['replaces'],
                ], $this->tdb->select('quote_lines', ['request_id' => $r['id']], 'position')),
            ],
            'pickup_code' => $held ? $r['pickup_code'] : null,
            'pickup_until' => $held ? $r['pickup_expires_at'] : null,
        ];
    }

    /** Pages ajoutées (document illisible ou incomplet). @param array<string, mixed> $in @return array<string, mixed> */
    public function addPages(string $code, array $in): array
    {
        $r = $this->find($code);
        if (!in_array($r['status'], ['received', 'processing', 'photo_needed'], true)) {
            throw new ApiException('conflict', 409);
        }
        $files = $this->files($in['documents'] ?? null, self::MAX_PAGES - count($this->tdb->select('request_documents', ['request_id' => $r['id']])));
        $this->db->transaction(function () use ($r, $files): void {
            $this->store((string) $r['id'], $files);
            $this->ledger->move((string) $r['id'], $r['status'] === 'photo_needed' ? ['status' => 'received'] : []);
            $this->ledger->event((string) $r['id'], 'pages_added');
        });
        return $this->view($code);
    }

    /**
     * Accepter tout ou partie du devis, ou le refuser.
     *
     * @param array<string, mixed> $in accept (identifiants de lignes) ou refuse + reason?
     * @return array<string, mixed>
     */
    public function decide(string $code, array $in): array
    {
        $r = $this->find($code);
        if ($r['status'] !== 'quoted') {
            throw new ApiException('conflict', 409);
        }
        $id = (string) $r['id'];
        if (($in['refuse'] ?? false) === true) {
            $this->db->transaction(function () use ($id, $in): void {
                $this->claim($id, ['status' => 'cancelled']);
                $this->tdb->update('quote_lines', ['accepted' => 0], ['request_id' => $id]);
                $this->ledger->event($id, 'refused', null, is_string($in['reason'] ?? null) ? trim($in['reason']) : null);
            });
            return $this->view($code);
        }

        $chosen = array_values(array_unique(array_filter((array) ($in['accept'] ?? []), 'is_string')));
        $lines = array_column($this->tdb->select('quote_lines', ['request_id' => $id]), null, 'id');
        if ($chosen === [] || array_filter($chosen, static fn (string $l) => (int) ($lines[$l]['available'] ?? 0) !== 1) !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => ['accept' => 'invalid']]);
        }
        $this->db->transaction(function () use ($id, $chosen, $lines): void {
            $this->claim($id, ['status' => 'accepted', 'pickup_code' => $this->pickupCode(), 'pickup_expires_at' => $this->ledger->holdUntil()]);
            $total = 0;
            foreach ($lines as $line) {
                $kept = in_array($line['id'], $chosen, true);
                $this->tdb->update('quote_lines', ['accepted' => $kept ? 1 : 0], ['id' => $line['id']]);
                $total += $kept ? (int) $line['line_total'] : 0;
            }
            $this->tdb->update('quotes', ['total' => $total], ['request_id' => $id]);
            $this->ledger->event($id, 'accepted');
        });
        return $this->view($code);
    }

    /** Message au pharmacien ; les signes graves déclenchent l'orientation vers les urgences. @return array<string, mixed> */
    public function message(string $code, string $text): array
    {
        $r = $this->find($code);
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 500) {
            throw new ApiException('validation_failed', 422, ['fields' => ['text' => 'required']]);
        }
        $this->ensureOpen($r);
        if (count($this->tdb->select('request_events', ['request_id' => $r['id'], 'kind' => 'message'])) >= 20) {
            throw new ApiException('too_many_requests', 429);
        }
        $severe = Guardrails::severe($text);
        $this->ledger->event((string) $r['id'], 'message', null, $text);
        if ($severe) {
            $this->ledger->move((string) $r['id'], ['advice' => 'emergency']);
            $this->raise($r);
        }
        return $this->view($code);
    }

    /** Déclaration d'urgence, une seule fois par demande. @return array<string, mixed> */
    public function urgent(string $code): array
    {
        $r = $this->find($code);
        $this->ensureOpen($r);
        $this->raise($r);
        return $this->view($code);
    }

    /** @param array<string, mixed> $r */
    private function ensureOpen(array $r): void
    {
        if (in_array($r['status'], ['picked_up', 'cancelled', 'expired'], true)) {
            throw new ApiException('conflict', 409);
        }
    }

    /** @param array<string, mixed> $r */
    private function raise(array $r): void
    {
        if ((int) $r['urgent'] === 1 || $this->urgentCount((string) $r['phone_key']) >= self::URGENT_PER_DAY) {
            return;
        }
        if ($this->tdb->update('patient_requests', ['urgent' => 1, 'updated_at' => $this->clock->stamp()], ['id' => $r['id'], 'urgent' => 0]) === 1) {
            $this->ledger->event((string) $r['id'], 'urgent');
            $this->escalation->start((string) $r['id']);
        }
    }

    /** Une décision n'est prise qu'une fois, même envoyée deux fois. @param array<string, mixed> $set */
    private function claim(string $id, array $set): void
    {
        if ($this->tdb->update('patient_requests', $set + ['updated_at' => $this->clock->stamp()], ['id' => $id, 'status' => 'quoted']) !== 1) {
            throw new ApiException('conflict', 409);
        }
    }

    private function pickupCode(): string
    {
        do {
            $code = 'ABCDEFGHJKLMNPQRSTUVWXYZ'[random_int(0, 23)] . sprintf('%04d', random_int(0, 9999));
        } while ($this->tdb->select('patient_requests', ['pickup_code' => $code], null, 1) !== []);
        return $code;
    }

    /** Pharmacie publiée qui reçoit la demande. */
    private function open(string $publicId): void
    {
        $p = $this->db->one('SELECT tenant_id FROM public_pharmacies WHERE id = ? AND visible = ?', [$publicId, 1]) ?? throw new ApiException('not_found', 404);
        $this->ctx->set((string) $p['tenant_id']);
    }

    /** @return array<string, mixed> */
    private function find(string $code): array
    {
        $r = preg_match('/^[0-9A-F]{40}$/i', $code) === 1
            ? $this->db->one('SELECT id, tenant_id FROM patient_requests WHERE code = ?', [strtoupper($code)])
            : null;
        if ($r === null) {
            throw new ApiException('not_found', 404);
        }
        $this->ctx->set((string) $r['tenant_id']);
        $this->ledger->expire();
        return $this->tdb->select('patient_requests', ['id' => $r['id']], null, 1)[0];
    }

    /**
     * Contrôle de tous les fichiers avant d'en écrire un seul.
     *
     * @return list<array{name: string, bytes: string}>
     */
    private function files(mixed $documents, int $room): array
    {
        if (!is_array($documents) || $documents === [] || count($documents) > $room) {
            throw new ApiException('validation_failed', 422, ['fields' => ['documents' => $documents === [] || !is_array($documents) ? 'required' : 'too_many']]);
        }
        $out = [];
        foreach ($documents as $d) {
            $bytes = is_array($d) ? base64_decode((string) ($d['base64'] ?? ''), true) : false;
            if ($bytes === false || $bytes === '') {
                throw new ApiException('validation_failed', 422, ['fields' => ['documents' => 'invalid']]);
            }
            if (strlen($bytes) > EncryptedStorage::MAX_BYTES) {
                throw new ApiException('file_too_large', 422);
            }
            if (!in_array((string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes), self::TYPES, true)) {
                throw new ApiException('file_type_not_allowed', 422);
            }
            $out[] = ['name' => (string) ($d['name'] ?? 'document'), 'bytes' => $bytes];
        }
        return $out;
    }

    /** @param list<array{name: string, bytes: string}> $files */
    private function store(string $requestId, array $files): void
    {
        foreach ($files as $f) {
            $s = $this->storage->put($f['bytes'], $f['name']);
            $this->tdb->insert('request_documents', [
                'id' => Ulid::generate(), 'request_id' => $requestId, 'storage_id' => $s['id'], 'original_name' => $s['name'],
                'mime' => $s['mime'], 'size_bytes' => $s['size'], 'sha256' => $s['sha256'], 'created_at' => $this->clock->stamp(),
            ]);
        }
    }

    /** Urgences déclarées par ce numéro dans la pharmacie ces dernières 24 h. */
    private function urgentCount(string $phoneKey): int
    {
        if ($phoneKey === '') {
            return 0;
        }
        $since = $this->clock->stamp($this->clock->now()->modify('-24 hours'));
        return count(array_filter(
            $this->tdb->select('patient_requests', ['phone_key' => $phoneKey, 'urgent' => 1]),
            static fn (array $r) => $r['created_at'] >= $since,
        ));
    }
}
