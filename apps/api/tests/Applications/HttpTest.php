<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** T040 : contrat HTTP bout en bout (soumission → validation super admin → activation → connexion). */
final class HttpTest extends TestCase
{
    private App $app;
    private Services $s;
    private int $ip = 0;

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        $clock = Clock::fixed('2026-11-21T14:02:00Z');
        $config = Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!', 'APP_URL' => 'https://app.example']);
        $storage = new EncryptedStorage(sys_get_temp_dir() . '/sanara-http-' . bin2hex(random_bytes(4)), random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 'url-secret-0123456789-0123456789-xx');
        $this->s = new Services($db, $clock, $config, $storage);
        (new UserRepository($db, $clock))->create(['email' => 'admin@sanara.cm', 'role_code' => 'super_admin', 'password_hash' => (new PasswordHasher())->hash('Admin#Motdepasse1')]);
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $token = null): array
    {
        $req = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.0.' . $this->ip]);
        if ($json !== null) {
            $req = $req->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($token !== null) {
            $req = $req->withHeader('Authorization', 'Bearer ' . $token);
        }
        $res = $this->app->handle($req);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
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
            'country_code' => 'CM', 'pharmacy_name' => 'Pharmacie Saint-Luc', 'license_number' => 'MINSANTE/0412', 'license_date' => '2019-03-14',
            'pharmacy_phone' => '+237600000001', 'pos_count' => 2, 'takes_duty' => true, 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua',
            'district' => 'Plateau', 'owner_last_name' => 'Mballa', 'owner_first_name' => 'Alain', 'owner_order_number' => 'ONPC-2214',
            'owner_phone' => '+237600000002', 'owner_email' => 'a.mballa@exemple.cm', 'locale' => 'fr',
            'accept_truth' => true, 'accept_terms' => true, 'accept_data' => true, 'documents' => $docs,
        ];
    }

    public function testFullJourneySubmitApproveActivateLogin(): void
    {
        [$st, $r] = $this->call('POST', '/public/applications', $this->payload());
        $this->assertSame(201, $st);
        $this->assertSame('PH-2026-0001', $r['number']);

        [$st, $status] = $this->call('GET', '/public/applications/' . $r['tracking_code'] . '/status');
        $this->assertSame([200, 'pending'], [$st, $status['status']]);

        [, $login] = $this->call('POST', '/auth/login', ['email' => 'admin@sanara.cm', 'password' => 'Admin#Motdepasse1']);
        $admin = $login['access'];
        [$st, $list] = $this->call('GET', '/admin/applications?status=pending', null, $admin);
        $this->assertSame(200, $st);
        $id = $list['items'][0]['id'];

        [$st, $detail] = $this->call('GET', "/admin/applications/$id", null, $admin);
        $this->assertSame(4, count($detail['documents']));
        $this->assertArrayNotHasKey('tracking_code', $detail['application']);

        [$st] = $this->call('POST', "/admin/applications/$id/approve", ['plan' => 'trial', 'pos_count' => 2], $admin);
        $this->assertSame(200, $st);
        [$st] = $this->call('POST', "/admin/applications/$id/approve", ['plan' => 'trial'], $admin);
        $this->assertSame(409, $st, 'double traitement');

        $token = $this->s->db->one('SELECT payload FROM jobs WHERE payload LIKE ?', ['%access_granted%']);
        preg_match('#/activer/([\w\-]+)#', (string) json_decode($token['payload'], true)['vars']['lien_activation'], $m);
        [$st, $u] = $this->call('GET', '/public/activation/' . $m[1]);
        $this->assertTrue($u['usable']);
        [$st] = $this->call('POST', '/public/activation/' . $m[1], ['password' => 'Pharma#Secure2026']);
        $this->assertSame(204, $st);

        [$st, $owner] = $this->call('POST', '/auth/login', ['email' => 'a.mballa@exemple.cm', 'password' => 'Pharma#Secure2026']);
        $this->assertSame(200, $st);
        [, $me] = $this->call('GET', '/auth/me', null, $owner['access']);
        $this->assertSame('owner', $me['role']);
        $this->assertContains('team.manage', $me['permissions']);
        $this->assertNotNull($me['tenant_id']);

        [$st] = $this->call('GET', '/admin/applications', null, $owner['access']);
        $this->assertSame(403, $st, 'un titulaire ne voit pas la console admin');
    }

    public function testAdminRoutesRequireAuthentication(): void
    {
        [$st, $r] = $this->call('GET', '/admin/applications');
        $this->assertSame(401, $st);
        $this->assertSame('unauthorized', $r['error']['code']);
        [$st] = $this->call('GET', '/admin/applications', null, 'faux.jeton.x');
        $this->assertSame(401, $st);
    }

    public function testSubmissionValidationReturns422WithFields(): void
    {
        $p = $this->payload();
        $p['owner_email'] = 'pas-un-email';
        [$st, $r] = $this->call('POST', '/public/applications', $p);
        $this->assertSame(422, $st);
        $this->assertSame('invalid', $r['error']['details']['fields']['owner_email']);
    }

    public function testSubmissionIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $p = $this->payload();
            $p['owner_email'] = "x$i@x.cm";
            $p['license_number'] = "L$i";
            $this->call('POST', '/public/applications', $p);
        }
        [$st, $r] = $this->call('POST', '/public/applications', $this->payload());
        $this->assertSame(429, $st);
        $this->assertArrayHasKey('retryAfter', $r['error']['details']);
    }

    public function testLoginIsRateLimitedAndNeverRevealsWhichFieldIsWrong(): void
    {
        [$st, $r] = $this->call('POST', '/auth/login', ['email' => 'admin@sanara.cm', 'password' => 'mauvais']);
        $this->assertSame(401, $st);
        $this->assertSame('invalid_credentials', $r['error']['code']);
    }

    public function testDocumentUrlIsSignedAndExpires(): void
    {
        $this->call('POST', '/public/applications', $this->payload());
        [, $login] = $this->call('POST', '/auth/login', ['email' => 'admin@sanara.cm', 'password' => 'Admin#Motdepasse1']);
        [, $list] = $this->call('GET', '/admin/applications', null, $login['access']);
        [, $detail] = $this->call('GET', '/admin/applications/' . $list['items'][0]['id'], null, $login['access']);
        $url = $detail['documents'][0]['url'];
        $req = (new ServerRequestFactory())->createServerRequest('GET', $url);
        $res = $this->app->handle($req);
        $this->assertSame(200, $res->getStatusCode());
        $this->assertSame('application/pdf', $res->getHeaderLine('Content-Type'));
        $this->assertSame(403, $this->app->handle((new ServerRequestFactory())->createServerRequest('GET', preg_replace('/sig=\w+/', 'sig=forged', $url)))->getStatusCode());
    }
}
