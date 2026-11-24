<?php
declare(strict_types=1);

namespace Sanara\Common\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Sanara\Common\Clock;

final class JwtService
{
    public function __construct(private readonly string $secret, private readonly Clock $clock, private readonly int $ttl = 900)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT_SECRET doit contenir au moins 32 caractères');
        }
    }

    /** @param array<string, mixed> $user @param array<string, mixed> $extra */
    public function issue(array $user, array $extra = []): string
    {
        $now = $this->clock->now()->getTimestamp();
        return JWT::encode($extra + [
            'iss' => 'sanara', 'sub' => $user['id'], 'tid' => $user['tenant_id'], 'role' => $user['role_code'],
            'iat' => $now, 'exp' => $now + $this->ttl,
        ], $this->secret, 'HS256');
    }

    /** Jeton intermédiaire (5 min) valable uniquement pour la seconde étape ; refusé partout ailleurs. */
    public function issueMfa(string $userId, ?string $deviceId = null, ?string $label = null): string
    {
        $now = $this->clock->now()->getTimestamp();
        return JWT::encode(['iss' => 'sanara', 'sub' => $userId, 'pur' => 'mfa', 'dev' => $deviceId, 'lbl' => $label, 'iat' => $now, 'exp' => $now + 300], $this->secret, 'HS256');
    }

    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        JWT::$timestamp = $this->clock->now()->getTimestamp();
        try {
            return (array) JWT::decode($token, new Key($this->secret, 'HS256'));
        } catch (\Throwable) {
            throw new AuthException('invalid_token');
        } finally {
            JWT::$timestamp = null;
        }
    }
}
