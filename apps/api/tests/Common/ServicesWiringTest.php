<?php
declare(strict_types=1);

namespace Sanara\Tests\Common;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;

/** Les services se construisent comme en production, sans rien d'injecté par les tests. */
final class ServicesWiringTest extends TestCase
{
    public function testServicesBuildFromTheEnvironmentAlone(): void
    {
        $dir = sys_get_temp_dir() . '/sa-wiring-' . bin2hex(random_bytes(3));
        $s = new Services(TestDb::create(), new Clock(), Config::fromEnv([
            'APP_ENV' => 'testing',
            'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!',
            'STORAGE_KEY' => base64_encode(random_bytes(32)),
            'STORAGE_DIR' => $dir,
        ]));
        $stored = $s->storage->put("%PDF-1.4\n%%EOF", 'a.pdf');
        $this->assertSame("%PDF-1.4\n%%EOF", $s->storage->get($stored['id']));
        $this->assertNotNull($s->requests);
        $this->assertNotNull($s->patients);
    }
}
