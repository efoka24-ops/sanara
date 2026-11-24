<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

final class CaptchaHttpTest extends TestCase
{
    private App $app;

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        $clock = Clock::fixed('2026-11-24T10:00:00Z');
        $config = Config::fromEnv([
            'APP_ENV' => 'testing',
            'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!',
            'CAPTCHA_ENABLED' => '1',
        ]);
        $storage = new EncryptedStorage(sys_get_temp_dir() . '/sanara-captcha-' . bin2hex(random_bytes(4)), random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 'url-secret-0123456789-0123456789-xx');
        $services = new Services($db, $clock, $config, $storage);
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $services);
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        $pdf = base64_encode("%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF");
        $docs = [];
        foreach (['license', 'diploma', 'order_card', 'identity'] as $t) {
            $docs[$t] = ['name' => "$t.pdf", 'base64' => $pdf];
        }

        return [
            'country_code' => 'CM',
            'pharmacy_name' => 'Pharmacie Saint-Luc',
            'license_number' => 'MINSANTE/0412',
            'license_date' => '2019-03-14',
            'pharmacy_phone' => '+237600000001',
            'pos_count' => 2,
            'takes_duty' => true,
            'region' => 'Nord',
            'department' => 'Benoue',
            'city' => 'Garoua',
            'district' => 'Plateau',
            'owner_last_name' => 'Mballa',
            'owner_first_name' => 'Alain',
            'owner_order_number' => 'ONPC-2214',
            'owner_phone' => '+237600000002',
            'owner_email' => 'a.mballa@exemple.cm',
            'locale' => 'fr',
            'accept_truth' => true,
            'accept_terms' => true,
            'accept_data' => true,
            'documents' => $docs,
        ];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(array $json): array
    {
        $req = (new ServerRequestFactory())->createServerRequest('POST', '/public/applications')
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream(json_encode($json)));

        $res = $this->app->handle($req);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    public function testCaptchaEnabledWithoutTokenReturnsValidationFailed(): void
    {
        [$st, $r] = $this->call($this->payload());

        $this->assertSame(422, $st);
        $this->assertSame('validation_failed', $r['error']['code']);
        $this->assertSame('required', $r['error']['details']['fields']['captcha_token']);
    }
}
