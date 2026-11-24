<?php
declare(strict_types=1);

namespace Sanara\Modules\Team;

use Sanara\Common\Audit\AuditLogger;
use Sanara\Common\Auth\RefreshTokenService;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Phone;
use Sanara\Common\Rbac\Authorizer;
use Sanara\Common\Rbac\DefaultRoles;
use Sanara\Modules\Applications\ActivationService;

/** US4 : équipe de la pharmacie. Toutes les opérations sont bornées au tenant et auditées. */
final class TeamService
{
    private const ASSIGNABLE = ['pharmacist', 'storekeeper', 'cashier', 'accountant'];
    private readonly AuditLogger $audit;

    public function __construct(
        private readonly Db $db,
        private readonly Clock $clock,
        private readonly UserRepository $users,
        private readonly Authorizer $authz,
        private readonly ActivationService $activation,
        private readonly RefreshTokenService $refresh,
        private readonly JobQueue $jobs,
        private readonly string $appUrl,
    ) {
        $this->audit = new AuditLogger($db, $clock);
    }

    /** @return list<array<string, mixed>> */
    public function members(string $tenantId): array
    {
        return $this->db->all('SELECT id, email, phone, role_code, status, created_at FROM users WHERE tenant_id = ? AND deleted_at IS NULL ORDER BY created_at', [$tenantId]);
    }

    /** @param array{name: string, email: string, phone?: ?string, role: string} $in */
    public function invite(string $tenantId, string $actorId, array $in): string
    {
        $errors = [];
        if (!in_array($in['role'] ?? '', self::ASSIGNABLE, true)) {
            $errors['role'] = 'invalid';
        }
        if (!filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'invalid';
        }
        if (trim((string) ($in['name'] ?? '')) === '') {
            $errors['name'] = 'required';
        }
        $phone = null;
        if (!empty($in['phone'])) {
            $phone = Phone::normalizeCm((string) $in['phone']);
            if ($phone === null) {
                $errors['phone'] = 'invalid';
            }
        }
        if ($errors !== []) {
            throw new ApiException('validation_failed', 422, ['fields' => $errors]);
        }
        if ($this->users->findByEmail($in['email']) !== null) {
            throw new ApiException('conflict', 409);
        }
        $id = $this->users->create([
            'tenant_id' => $tenantId, 'role_code' => $in['role'], 'email' => $in['email'], 'phone' => $phone,
            'status' => 'pending_activation', 'password_hash' => '!' . bin2hex(random_bytes(16)),
        ]);
        $token = $this->activation->issueToken($id, 72);
        $this->audit->record('team.invite', ['tenant_id' => $tenantId, 'user_id' => $actorId, 'entity' => 'user', 'entity_id' => $id, 'after' => ['role' => $in['role']]]);
        $this->jobs->push('mail', ['template' => 'member_invited', 'to' => strtolower($in['email']), 'locale' => 'fr', 'vars' => [
            'nom' => trim($in['name']), 'role' => DefaultRoles::roles()[$in['role']]['name'], 'lien_activation' => rtrim($this->appUrl, '/') . '/activer/' . $token,
        ]]);
        return $id;
    }

    public function changeRole(string $tenantId, string $actorId, string $userId, string $role): void
    {
        $u = $this->member($tenantId, $userId);
        if (!in_array($role, [...self::ASSIGNABLE, 'owner'], true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['role' => 'invalid']]);
        }
        if ($u['role_code'] === 'owner' && $role !== 'owner') {
            $this->assertNotLastOwner($tenantId, $userId);
        }
        $this->db->execute('UPDATE users SET role_code = ?, updated_at = ? WHERE id = ?', [$role, $this->clock->stamp(), $userId]);
        $this->audit->record('team.change_role', ['tenant_id' => $tenantId, 'user_id' => $actorId, 'entity' => 'user', 'entity_id' => $userId, 'before' => ['role' => $u['role_code']], 'after' => ['role' => $role]]);
    }

    public function deactivate(string $tenantId, string $actorId, string $userId): void
    {
        $u = $this->member($tenantId, $userId);
        if ($u['role_code'] === 'owner') {
            $this->assertNotLastOwner($tenantId, $userId);
        }
        $this->db->execute("UPDATE users SET status = 'disabled', updated_at = ? WHERE id = ?", [$this->clock->stamp(), $userId]);
        $this->db->execute('UPDATE refresh_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [$this->clock->stamp(), $userId]);
        $this->audit->record('team.deactivate', ['tenant_id' => $tenantId, 'user_id' => $actorId, 'entity' => 'user', 'entity_id' => $userId]);
    }

    public function reactivate(string $tenantId, string $actorId, string $userId): void
    {
        $this->member($tenantId, $userId);
        $this->db->execute("UPDATE users SET status = 'active', updated_at = ? WHERE id = ? AND status = 'disabled'", [$this->clock->stamp(), $userId]);
        $this->audit->record('team.reactivate', ['tenant_id' => $tenantId, 'user_id' => $actorId, 'entity' => 'user', 'entity_id' => $userId]);
    }

    public function setOverride(string $tenantId, string $actorId, string $userId, string $permission, bool $granted): void
    {
        $this->member($tenantId, $userId);
        $platform = ['platform.manage', 'applications.review'];
        if (!array_key_exists($permission, DefaultRoles::PERMISSIONS) || in_array($permission, $platform, true)) {
            throw new ApiException('validation_failed', 422, ['fields' => ['permission' => 'invalid']]);
        }
        $this->authz->override($userId, $permission, $granted);
        $this->audit->record('team.override', ['tenant_id' => $tenantId, 'user_id' => $actorId, 'entity' => 'user', 'entity_id' => $userId, 'after' => [$permission => $granted]]);
    }

    /** @return array<string, mixed> */
    private function member(string $tenantId, string $userId): array
    {
        $u = $this->db->one('SELECT * FROM users WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$userId, $tenantId]);
        return $u ?? throw new ApiException('not_found', 404);
    }

    private function assertNotLastOwner(string $tenantId, string $userId): void
    {
        $others = (int) $this->db->one("SELECT COUNT(*) AS n FROM users WHERE tenant_id = ? AND role_code = 'owner' AND status = 'active' AND id <> ? AND deleted_at IS NULL", [$tenantId, $userId])['n'];
        if ($others === 0) {
            throw new ApiException('conflict', 409);
        }
    }
}
