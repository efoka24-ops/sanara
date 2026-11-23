<?php
declare(strict_types=1);

namespace Sanara\Common\Logging;

/** Masque les données personnelles avant toute écriture dans les journaux (constitution III). */
final class Redactor
{
    private const SECRET_KEYS = ['password', 'password_hash', 'token', 'refresh_token', 'access_token', 'authorization', 'secret', 'pin', 'code', 'totp_secret', 'api_key'];
    private const PERSONAL_KEYS = ['email', 'phone', 'telephone', 'name', 'nom', 'address'];

    public function redact(string $s): string
    {
        $s = preg_replace('/Bearer\s+[\w\-\.=]+/i', 'Bearer [masqué]', $s) ?? $s;
        $s = preg_replace('/\b(pin|password|token|code)\s*[=:]\s*\S+/i', '$1=[masqué]', $s) ?? $s;
        $s = preg_replace_callback('/([A-Za-z0-9._%+\-])[A-Za-z0-9._%+\-]*@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/', static fn ($m) => $m[1] . '***@' . $m[2], $s) ?? $s;
        $s = preg_replace('/(?:\+?237[\s.\-]?)?\b6(?:[\s.\-]?\d){8}\b/', '[tél masqué]', $s) ?? $s;
        return $s;
    }

    /** @param array<string, mixed> $ctx @return array<string, mixed> */
    public function context(array $ctx): array
    {
        $out = [];
        foreach ($ctx as $k => $v) {
            $key = strtolower((string) $k);
            if (in_array($key, self::SECRET_KEYS, true) || in_array($key, self::PERSONAL_KEYS, true)) {
                $out[$k] = '[masqué]';
            } elseif (is_array($v)) {
                $out[$k] = $this->context($v);
            } elseif (is_string($v)) {
                $out[$k] = $this->redact($v);
            } else {
                $out[$k] = $v;
            }
        }
        return $out;
    }
}
