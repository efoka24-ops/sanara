<?php
declare(strict_types=1);

namespace Sanara\Common;

final class Config
{
    public function __construct(
        public readonly string $env,
        public readonly bool $debug,
        public readonly string $appUrl,
        public readonly string $dbHost,
        public readonly int $dbPort,
        public readonly string $dbName,
        public readonly string $dbUser,
        public readonly string $dbPassword,
        public readonly string $jwtSecret,
        public readonly string $storageKey = '',
        public readonly string $storageDir = '',
        public readonly string $corsOrigin = '',
    ) {
    }

    /** @param array<string, mixed> $env */
    public static function fromEnv(array $env): self
    {
        $get = static fn (string $k, string $d = ''): string => (string) ($env[$k] ?? $d);
        $appEnv = $get('APP_ENV', 'production');
        return new self(
            env: $appEnv,
            debug: filter_var($get('APP_DEBUG', $appEnv === 'production' ? 'false' : 'true'), FILTER_VALIDATE_BOOLEAN),
            appUrl: $get('APP_URL', 'http://localhost'),
            dbHost: $get('DB_HOST', 'localhost'),
            dbPort: (int) $get('DB_PORT', '3306'),
            dbName: $get('DB_DATABASE'),
            dbUser: $get('DB_USERNAME'),
            dbPassword: $get('DB_PASSWORD'),
            jwtSecret: $get('JWT_SECRET'),
            storageKey: $get('STORAGE_KEY'),
            storageDir: $get('STORAGE_DIR'),
            corsOrigin: $get('CORS_ORIGIN'),
        );
    }
}
