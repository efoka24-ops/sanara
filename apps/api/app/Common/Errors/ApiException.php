<?php
declare(strict_types=1);

namespace Sanara\Common\Errors;

final class ApiException extends \RuntimeException
{
    /** @param array<string, mixed> $details */
    public function __construct(public readonly string $errorCode, public readonly int $status = 400, public readonly array $details = [])
    {
        parent::__construct($errorCode);
    }
}
