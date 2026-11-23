<?php
declare(strict_types=1);

namespace Sanara\Common\Auth;

final class AuthException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, public readonly int $status = 401)
    {
        parent::__construct($errorCode);
    }
}
