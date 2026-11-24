<?php
declare(strict_types=1);

namespace Sanara\Tests;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Config;
use Sanara\Common\Database;

final class ConfigTest extends TestCase
{
    public function testProductionIsNotDebugByDefault(): void
    {
        $c = Config::fromEnv(['APP_ENV' => 'production']);
        $this->assertFalse($c->debug);
    }

    public function testDevelopmentIsDebugByDefault(): void
    {
        $c = Config::fromEnv(['APP_ENV' => 'development']);
        $this->assertTrue($c->debug);
    }

    public function testStrictSqlModeIsEnforced(): void
    {
        $this->assertStringContainsString('STRICT_TRANS_TABLES', Database::SQL_MODE);
        $this->assertStringContainsString('NO_ZERO_DATE', Database::SQL_MODE);
    }

    public function testCaptchaIsDisabledByDefaultInTesting(): void
    {
        $c = Config::fromEnv(['APP_ENV' => 'testing']);
        $this->assertFalse($c->captchaEnabled);
    }

    public function testCaptchaCanBeEnabledExplicitly(): void
    {
        $c = Config::fromEnv(['APP_ENV' => 'testing', 'CAPTCHA_ENABLED' => '1']);
        $this->assertTrue($c->captchaEnabled);
    }
}
