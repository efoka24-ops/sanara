<?php
declare(strict_types=1);

namespace Sanara\Common\Logging;

/** Journal JSON une ligne par événement, vers le journal d'erreurs PHP, avec masquage systématique. */
final class Log
{
    /** @param array<string, mixed> $ctx */
    public static function write(string $level, string $message, array $ctx = []): void
    {
        $r = new Redactor();
        error_log(json_encode(['t' => gmdate('c'), 'level' => $level, 'msg' => $r->redact($message), 'ctx' => $r->context($ctx)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
