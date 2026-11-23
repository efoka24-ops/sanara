<?php
declare(strict_types=1);

namespace Sanara\Modules\Applications;

use Sanara\Common\Audit\AuditLogger;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Ulid;

/** US2 : décisions du super admin. Une demande ne peut être traitée qu'une fois (mise à jour conditionnelle). */
final class ReviewService
{
    public const ACTIVATION_HOURS = 48;
    private readonly AuditLogger $audit;
    private readonly UserRepository $users;

    public function __construct(private readonly Db $db, private readonly Clock $clock, private readonly JobQueue $jobs, private readonly string $appUrl)
    {
        $this->audit = new AuditLogger($db, $clock);
        $this->users = new UserRepository($db, $clock);
    }

    /** @return array{tenant_id: string, user_id: string, activation_token: string} */
    public function approve(string $applicationId, string $adminId, string $planCode, int $posCount): array
    {
        $out = $this->db->transaction(function () use ($applicationId, $adminId, $planCode): array {
            $now = $this->clock->stamp();
            $won = $this->db->execute(
                "UPDATE pharmacy_applications SET status = 'approved', plan_code = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
                [$planCode, $adminId, $now, $now, $applicationId],
            );
            if ($won !== 1) {
                throw new ApiException('conflict', 409);
            }
            $a = $this->db->one('SELECT * FROM pharmacy_applications WHERE id = ?', [$applicationId]);
            $tenantId = Ulid::generate();
            $this->db->insert('tenants', [
                'id' => $tenantId, 'country_code' => $a['country_code'], 'name' => $a['pharmacy_name'], 'slug' => $this->slug((string) $a['pharmacy_name']),
                'status' => 'active', 'address' => $a['address'], 'latitude' => $a['latitude'], 'longitude' => $a['longitude'],
                'phone' => $a['pharmacy_phone'], 'email' => $a['pharmacy_email'], 'license_number' => $a['license_number'],
                'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $userId = $this->users->create([
                'tenant_id' => $tenantId, 'role_code' => 'owner', 'email' => $a['owner_email'], 'phone' => $a['owner_phone'],
                'status' => 'pending_activation', 'locale' => $a['locale'], 'password_hash' => '!' . bin2hex(random_bytes(16)), // inutilisable avant activation
            ]);
            $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->db->insert('activation_tokens', [
                'id' => Ulid::generate(), 'user_id' => $userId, 'token_hash' => hash('sha256', $token),
                'expires_at' => $this->clock->stamp($this->clock->now()->modify('+' . self::ACTIVATION_HOURS . ' hours')), 'created_at' => $now,
            ]);
            $this->db->execute('UPDATE pharmacy_applications SET tenant_id = ? WHERE id = ?', [$tenantId, $applicationId]);
            $this->audit->record('application.approve', ['user_id' => $adminId, 'tenant_id' => $tenantId, 'entity' => 'application', 'entity_id' => $applicationId, 'after' => ['plan' => $planCode]]);
            $this->jobs->push('mail', [
                'template' => 'access_granted', 'to' => $a['owner_email'], 'locale' => $a['locale'],
                'vars' => [
                    'responsable' => trim($a['owner_first_name'] . ' ' . $a['owner_last_name']), 'pharmacie' => $a['pharmacy_name'],
                    'duree_lien' => $a['locale'] === 'en' ? self::ACTIVATION_HOURS . ' hours' : self::ACTIVATION_HOURS . ' heures',
                    'lien_activation' => rtrim($this->appUrl, '/') . '/activer/' . $token,
                ],
            ]);
            return ['tenant_id' => $tenantId, 'user_id' => $userId, 'activation_token' => $token];
        });
        return $out;
    }

    public function reject(string $applicationId, string $adminId, string $reason, ?string $note, bool $allowReapply): void
    {
        if (trim($reason) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['reason' => 'required']]);
        }
        $now = $this->clock->stamp();
        $won = $this->db->execute(
            "UPDATE pharmacy_applications SET status = 'rejected', rejection_reason = ?, rejection_note = ?, allow_reapply = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE id = ? AND status IN ('pending','needs_info')",
            [$reason, $note, $allowReapply ? 1 : 0, $adminId, $now, $now, $applicationId],
        );
        if ($won !== 1) {
            throw new ApiException('conflict', 409);
        }
        $a = $this->db->one('SELECT * FROM pharmacy_applications WHERE id = ?', [$applicationId]);
        $this->audit->record('application.reject', ['user_id' => $adminId, 'entity' => 'application', 'entity_id' => $applicationId, 'after' => ['reason' => $reason]]);
        $this->jobs->push('mail', ['template' => 'access_rejected', 'to' => $a['owner_email'], 'locale' => $a['locale'], 'vars' => ['responsable' => $a['owner_first_name'] . ' ' . $a['owner_last_name'], 'motif' => $note ?? $reason]]);
    }

    /** @param list<string> $docTypes */
    public function requestInfo(string $applicationId, string $adminId, array $docTypes, string $message): void
    {
        $docTypes = array_values(array_intersect($docTypes, ApplicationService::DOC_TYPES));
        if ($docTypes === [] || trim($message) === '') {
            throw new ApiException('validation_failed', 422);
        }
        $now = $this->clock->stamp();
        $won = $this->db->execute(
            "UPDATE pharmacy_applications SET status = 'needs_info', info_docs = ?, info_message = ?, reviewed_by = ?, updated_at = ? WHERE id = ? AND status = 'pending'",
            [json_encode($docTypes), $message, $adminId, $now, $applicationId],
        );
        if ($won !== 1) {
            throw new ApiException('conflict', 409);
        }
        $a = $this->db->one('SELECT * FROM pharmacy_applications WHERE id = ?', [$applicationId]);
        $this->audit->record('application.request_info', ['user_id' => $adminId, 'entity' => 'application', 'entity_id' => $applicationId, 'after' => ['docs' => $docTypes]]);
        $this->jobs->push('mail', ['template' => 'info_requested', 'to' => $a['owner_email'], 'locale' => $a['locale'], 'vars' => ['responsable' => $a['owner_first_name'] . ' ' . $a['owner_last_name'], 'message' => $message, 'code_suivi' => $a['tracking_code']]]);
    }

    private function slug(string $name): string
    {
        $s = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', (string) iconv('UTF-8', 'ASCII//TRANSLIT', $name)), '-'));
        $base = $s !== '' ? $s : 'pharmacie';
        $slug = $base;
        for ($i = 2; $this->db->one('SELECT id FROM tenants WHERE slug = ?', [$slug]) !== null; $i++) {
            $slug = $base . '-' . $i;
        }
        return $slug;
    }
}
