<?php
declare(strict_types=1);

namespace Sanara\Modules\Security;

use Sanara\Common\Errors\ApiException;

final class CaptchaVerifier
{
    public function __construct(private readonly bool $enabled)
    {
    }

    public function verify(?string $token): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($token === null || trim($token) === '') {
            throw new ApiException('validation_failed', 422, ['fields' => ['captcha_token' => 'required']]);
        }

        return true;
    }
}
