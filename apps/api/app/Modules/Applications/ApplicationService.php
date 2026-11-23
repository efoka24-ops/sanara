<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Phone;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Storage\StorageException;
use Sanara\Common\Ulid;

/** US1 : dépôt d'une demande d'accès, suivi par code, renvoi d'un document demandé. */
final class ApplicationService
{
    public const DOC_TYPES = ['license', 'diploma', 'order_card', 'identity'];
    private const REQUIRED = ['pharmacy_name', 'license_number', 'license_date', 'pharmacy_phone', 'region', 'department', 'city', 'district',
        'owner_last_name', 'owner_first_name', 'owner_order_number', 'owner_phone', 'owner_email'];

    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly EncryptedStorage $storage, private readonly JobQueue $jobs)
    {
    }

    /**
     * @param array<string, mixed> $f
     * @param array<string, array{name: string, bytes: string}> $docs
     * @return array{number: string, tracking_code: string}
     */
    public function submit(array $f, array $docs): array
    {
        $errors = $this->validate($f, $docs);
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        $this->assertNoDuplicate((string) $f['owner_email'], (string) $f['license_number']);

        $stored = [];
        try {
            foreach ($docs as $type => $d) {
                $stored[$type] = $this->storage->put($d['bytes'], $d['name']);
            }
        } catch (StorageException $e) {
            throw new ApiException($e->errorCode, 422, ['fields' => ['documents' => $e->errorCode]]);
        }

        $id = Ulid::generate();
        $now = $this->clock->stamp();
        $tracking = strtolower(substr(strtr(base64_encode(random_bytes(20)), '+/=', 'abc'), 0, 24));
        $number = $this->db->transaction(function () use ($f, $id, $now, $tracking, $stored): string {
            $year = $this->clock->now()->format('Y');
            $n = (int) $this->db->one('SELECT COUNT(*) AS n FROM pharmacy_applications WHERE number LIKE ?', ["PH-$year-%"])['n'] + 1;
            $number = sprintf('PH-%s-%04d', $year, $n);
            $this->db->insert('pharmacy_applications', [
                'id' => $id, 'number' => $number, 'tracking_code' => $tracking, 'country_code' => $f['country_code'] ?? 'CM', 'status' => 'pending',
                'pharmacy_name' => trim((string) $f['pharmacy_name']), 'license_number' => trim((string) $f['license_number']),
                'license_date' => $f['license_date'], 'pharmacy_phone' => Phone::normalizeCm((string) $f['pharmacy_phone']),
                'pharmacy_email' => $f['pharmacy_email'] ?? null, 'pos_count' => (int) ($f['pos_count'] ?? 1), 'current_tool' => $f['current_tool'] ?? null,
                'takes_duty' => !empty($f['takes_duty']) ? 1 : 0, 'offers_delivery_drop' => !empty($f['offers_delivery_drop']) ? 1 : 0,
                'region' => $f['region'], 'department' => $f['department'], 'city' => $f['city'], 'district' => $f['district'],
                'address' => $f['address'] ?? null, 'latitude' => $f['latitude'] ?? null, 'longitude' => $f['longitude'] ?? null,
                'owner_last_name' => trim((string) $f['owner_last_name']), 'owner_first_name' => trim((string) $f['owner_first_name']),
                'owner_order_number' => trim((string) $f['owner_order_number']), 'owner_phone' => Phone::normalizeCm((string) $f['owner_phone']),
                'owner_email' => strtolower(trim((string) $f['owner_email'])), 'locale' => ($f['locale'] ?? 'fr') === 'en' ? 'en' : 'fr',
                'allow_reapply' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($stored as $type => $s) {
                $this->insertDoc($id, $type, $s, $now);
            }
            return $number;
        });

        $this->jobs->push('mail', [
            'template' => 'application_received', 'to' => strtolower(trim((string) $f['owner_email'])), 'locale' => ($f['locale'] ?? 'fr') === 'en' ? 'en' : 'fr',
            'vars' => ['responsable' => trim($f['owner_first_name'] . ' ' . $f['owner_last_name']), 'numero' => $number, 'code_suivi' => $tracking],
        ]);
        return ['number' => $number, 'tracking_code' => $tracking];
    }

    /** @return array{number: string, status: string, message: ?string, requested_docs: list<string>, created_at: string} */
    public function status(string $trackingCode): array
    {
        $a = $this->db->one('SELECT * FROM pharmacy_applications WHERE tracking_code = ?', [$trackingCode]);
        if ($a === null) {
            throw new ApiException('not_found', 404);
        }
        return [
            'number' => (string) $a['number'], 'status' => (string) $a['status'],
            'message' => $a['status'] === 'needs_info' ? $a['info_message'] : ($a['status'] === 'rejected' ? $a['rejection_note'] : null),
            'requested_docs' => $a['status'] === 'needs_info' ? (json_decode((string) $a['info_docs'], true) ?: []) : [],
            'created_at' => (string) $a['created_at'],
        ];
    }

    public function resendDocument(string $trackingCode, string $type, string $name, string $bytes): void
    {
        $a = $this->db->one('SELECT id, status, info_docs FROM pharmacy_applications WHERE tracking_code = ?', [$trackingCode]);
        if ($a === null) {
            throw new ApiException('not_found', 404);
        }
        $requested = json_decode((string) $a['info_docs'], true) ?: [];
        if ($a['status'] !== 'needs_info' || !in_array($type, $requested, true)) {
            throw new ApiException('conflict', 409);
        }
        try {
            $s = $this->storage->put($bytes, $name);
        } catch (StorageException $e) {
            throw new ApiException($e->errorCode, 422);
        }
        $now = $this->clock->stamp();
        $this->db->transaction(function () use ($a, $type, $s, $now, $requested): void {
            $this->db->execute('UPDATE application_documents SET superseded = 1 WHERE application_id = ? AND doc_type = ?', [$a['id'], $type]);
            $this->insertDoc((string) $a['id'], $type, $s, $now);
            $left = array_values(array_diff($requested, [$type]));
            $this->db->execute(
                'UPDATE pharmacy_applications SET status = ?, info_docs = ?, updated_at = ? WHERE id = ?',
                [$left === [] ? 'pending' : 'needs_info', json_encode($left), $now, $a['id']],
            );
        });
    }

    /** @param array{id: string, mime: string, size: int, sha256: string, name: string} $s */
    private function insertDoc(string $appId, string $type, array $s, string $now): void
    {
        $this->db->insert('application_documents', [
            'id' => Ulid::generate(), 'application_id' => $appId, 'doc_type' => $type, 'storage_id' => $s['id'], 'original_name' => $s['name'],
            'mime' => $s['mime'], 'size_bytes' => $s['size'], 'sha256' => $s['sha256'], 'superseded' => 0, 'created_at' => $now,
        ]);
    }

    private function assertNoDuplicate(string $email, string $license): void
    {
        $rows = $this->db->all(
            'SELECT status, allow_reapply FROM pharmacy_applications WHERE owner_email = ? OR license_number = ?',
            [strtolower(trim($email)), trim($license)],
        );
        foreach ($rows as $r) {
            $blocked = in_array($r['status'], ['pending', 'needs_info', 'approved'], true) || ($r['status'] === 'rejected' && (int) $r['allow_reapply'] === 0);
            if ($blocked) {
                throw new ApiException('conflict', 409);
            }
        }
    }

    /**
     * @param array<string, mixed> $f
     * @param array<string, array{name: string, bytes: string}> $docs
     * @return array<string, string>
     */
    private function validate(array $f, array $docs): array
    {
        $e = [];
        foreach (self::REQUIRED as $k) {
            if (!isset($f[$k]) || trim((string) $f[$k]) === '') {
                $e[$k] = 'required';
            }
        }
        if (!isset($e['owner_email']) && !filter_var($f['owner_email'], FILTER_VALIDATE_EMAIL)) {
            $e['owner_email'] = 'invalid';
        }
        if (!empty($f['pharmacy_email']) && !filter_var($f['pharmacy_email'], FILTER_VALIDATE_EMAIL)) {
            $e['pharmacy_email'] = 'invalid';
        }
        foreach (['pharmacy_phone', 'owner_phone'] as $k) {
            if (!isset($e[$k]) && Phone::normalizeCm((string) $f[$k]) === null) {
                $e[$k] = 'invalid';
            }
        }
        if (!isset($e['license_date'])) {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $f['license_date']);
            if ($d === false || $d > $this->clock->now()) {
                $e['license_date'] = 'invalid';
            }
        }
        foreach (['accept_truth', 'accept_terms', 'accept_data'] as $k) {
            if (empty($f[$k])) {
                $e[$k] = 'required';
            }
        }
        foreach (self::DOC_TYPES as $t) {
            if (!isset($docs[$t]) || $docs[$t]['bytes'] === '') {
                $e['doc_' . $t] = 'required';
            }
        }
        return $e;
    }
}
