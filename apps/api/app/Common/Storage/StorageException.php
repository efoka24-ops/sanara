<?php
declare(strict_types=1);

namespace Sanara\Common\Storage;

final class StorageException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
