<?php
declare(strict_types=1);

namespace Sanara\Common\Auth;

final class PasswordHasher
{
    public function hash(string $password): string
    {
        return password_hash($password, PASSWORD_ARGON2ID);
    }

    public function verify(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /** 10 caractères minimum, une majuscule, un chiffre, un caractère spécial (écran A6). */
    public function meetsPolicy(string $p): bool
    {
        return mb_strlen($p) >= 10 && preg_match('/\p{Lu}/u', $p) === 1 && preg_match('/\d/', $p) === 1 && preg_match('/[^\p{L}\d]/u', $p) === 1;
    }
}
