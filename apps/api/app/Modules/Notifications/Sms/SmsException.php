<?php
declare(strict_types=1);

namespace Sanara\Modules\Notifications\Sms;

final class SmsException extends \RuntimeException
{
    public function __construct(public readonly string $errorCode, string $message = '', public readonly ?int $retryAfter = null)
    {
        parent::__construct($message !== '' ? $message : $errorCode);
    }
}
