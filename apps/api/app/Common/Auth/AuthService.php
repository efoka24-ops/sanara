<?php
declare(strict_types=1);

namespace Sanara\Common\Auth;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Modules\Security\MfaService;

final class AuthService
{
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;

    /**
     * @param (callable(array<string, mixed>): void)|null $onLocked appelé quand un compte vient d'être verrouillé
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly JwtService $jwt,
        private readonly RefreshTokenService $refresh,
        private readonly Clock $clock,
        private readonly ?MfaService $mfa = null,
        private readonly bool $enforceAdminMfa = false,
        private $onLocked = null,
    ) {
    }

    /**
     * @return array{access?: string, refresh?: string, user?: array<string, mixed>, mfa_required?: bool, mfa_token?: string}
     */
    public function login(string $email, string $password, ?string $deviceId = null, ?string $label = null): array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->hasher->verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXk$0000000000000000000000000000000000000000000'); // lisse le temps de réponse
            throw new AuthException('invalid_credentials');
        }
        $this->assertNotLocked($user);
        if ($user['status'] !== 'active' || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->fail($user);
            throw new AuthException('invalid_credentials');
        }
        if ($this->mfa !== null && (int) ($user['totp_enabled'] ?? 0) === 1) {
            return ['mfa_required' => true, 'mfa_token' => $this->jwt->issueMfa((string) $user['id'], $deviceId, $label)];
        }
        $this->users->resetFailures((string) $user['id']);
        $setupOnly = $this->enforceAdminMfa && $user['role_code'] === 'super_admin';
        return $this->tokens($user, $deviceId, $label, $setupOnly);
    }

    /** Seconde étape : code TOTP ou code de secours. Les échecs comptent pour le verrouillage. */
    public function completeMfa(string $mfaToken, string $code): array
    {
        $claims = $this->jwt->verify($mfaToken);
        if (($claims['pur'] ?? '') !== 'mfa') {
            throw new AuthException('invalid_token');
        }
        $user = $this->users->findById((string) $claims['sub']) ?? throw new AuthException('invalid_token');
        $this->assertNotLocked($user);
        if ($this->mfa === null || $user['status'] !== 'active' || !$this->mfa->verifyCode((string) $user['id'], $code)) {
            $this->fail($user);
            throw new AuthException('invalid_credentials');
        }
        $this->users->resetFailures((string) $user['id']);
        return $this->tokens($user, $claims['dev'] ?? null, $claims['lbl'] ?? null, false);
    }

    /** @return array{access: string, refresh: string} */
    public function refresh(string $token): array
    {
        $new = $this->refresh->rotate($token);
        $user = $this->users->findById($new['user_id']);
        if ($user === null || $user['status'] !== 'active') {
            $this->refresh->revokeToken($new['token']);
            throw new AuthException('invalid_refresh_token');
        }
        $setupOnly = $this->enforceAdminMfa && $user['role_code'] === 'super_admin' && (int) ($user['totp_enabled'] ?? 0) !== 1;
        return ['access' => $this->jwt->issue($user, $setupOnly ? ['scope' => 'setup'] : []), 'refresh' => $new['token']];
    }

    public function logout(string $refreshToken): void
    {
        $this->refresh->revokeToken($refreshToken);
    }

    /** @param array<string, mixed> $user */
    private function assertNotLocked(array $user): void
    {
        if ($user['locked_until'] !== null && $user['locked_until'] > $this->clock->stamp()) {
            throw new AuthException('account_locked', 423);
        }
    }

    /** @param array<string, mixed> $user */
    private function fail(array $user): void
    {
        if ($this->users->recordFailure((string) $user['id'], self::MAX_FAILURES, self::LOCK_MINUTES) && $this->onLocked !== null) {
            ($this->onLocked)($user);
        }
    }

    /** @param array<string, mixed> $user @return array{access: string, refresh: string, user: array<string, mixed>} */
    private function tokens(array $user, ?string $deviceId, ?string $label, bool $setupOnly): array
    {
        $mfaOn = (int) ($user['totp_enabled'] ?? 0) === 1;
        return [
            'access' => $this->jwt->issue($user, $setupOnly && !$mfaOn ? ['scope' => 'setup'] : []),
            'refresh' => $this->refresh->issue((string) $user['id'], $deviceId, null, $label)['token'],
            'user' => ['id' => $user['id'], 'tenant_id' => $user['tenant_id'], 'role' => $user['role_code'], 'email' => $user['email'], 'mfa_setup_required' => $setupOnly && !$mfaOn],
        ];
    }
}
