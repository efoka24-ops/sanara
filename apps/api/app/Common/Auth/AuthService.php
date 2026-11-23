<?php
declare(strict_types=1);

namespace Sanara\Common\Auth;

use Sanara\Common\Clock;
use Sanara\Common\Persistence\UserRepository;

final class AuthService
{
    private const MAX_FAILURES = 5;
    private const LOCK_MINUTES = 15;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly JwtService $jwt,
        private readonly RefreshTokenService $refresh,
        private readonly Clock $clock,
    ) {
    }

    /** @return array{access: string, refresh: string, user: array<string, mixed>} */
    public function login(string $email, string $password, ?string $deviceId = null): array
    {
        $user = $this->users->findByEmail($email);
        if ($user === null) {
            $this->hasher->verify($password, '$argon2id$v=19$m=65536,t=4,p=1$ZHVtbXlzYWx0ZHVtbXk$0000000000000000000000000000000000000000000'); // lisse le temps de réponse
            throw new AuthException('invalid_credentials');
        }
        if ($user['locked_until'] !== null && $user['locked_until'] > $this->clock->stamp()) {
            throw new AuthException('account_locked', 423);
        }
        if ($user['status'] !== 'active' || !$this->hasher->verify($password, (string) $user['password_hash'])) {
            $this->users->recordFailure((string) $user['id'], self::MAX_FAILURES, self::LOCK_MINUTES);
            throw new AuthException('invalid_credentials');
        }
        $this->users->resetFailures((string) $user['id']);
        return [
            'access' => $this->jwt->issue($user),
            'refresh' => $this->refresh->issue((string) $user['id'], $deviceId)['token'],
            'user' => ['id' => $user['id'], 'tenant_id' => $user['tenant_id'], 'role' => $user['role_code'], 'email' => $user['email']],
        ];
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
        return ['access' => $this->jwt->issue($user), 'refresh' => $new['token']];
    }

    public function logout(string $refreshToken): void
    {
        $this->refresh->revokeToken($refreshToken);
    }
}
