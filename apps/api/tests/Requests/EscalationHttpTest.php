<?php
declare(strict_types=1);

namespace Sanara\Tests\Requests;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Ulid;
use Sanara\Modules\Http\Services;
use Sanara\Modules\Notifications\Sms\SmsProvider;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** Garde les SMS au lieu de les envoyer. */
final class RecordedSms implements SmsProvider
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    public function send(string $phone, string $message): string
    {
        $this->sent[] = [$phone, $message];
        return 'test-' . count($this->sent);
    }
}

/**
 * US24 (T186 à T193, T296) : présence des pharmaciens, messages adaptés au patient et chaîne d'escalade
 * des urgences : e-mail à 0 min, SMS au titulaire puis à l'adjoint, repli honnête au patient.
 */
final class EscalationHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    private App $app;
    private Db $db;
    private Clock $clock;
    private Services $s;
    private RecordedSms $sms;
    private string $pharmacy;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        // Vendredi 27 novembre 2026, 10 h à Douala.
        $this->clock = Clock::fixed('2026-11-27T09:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        $phones = ['owner' => '+237600000011', 'pharmacist' => '+237600000022', 'cashier' => '+237600000033'];
        foreach ($phones as $role => $phone) {
            $users->create(['email' => "$role@t.cm", 'role_code' => $role, 'tenant_id' => 'T1', 'phone' => $phone, 'password_hash' => $hash]);
        }
        $this->db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie T1', 'slug' => 't1', 'status' => 'active', 'phone' => '+237600000001', 'public_visible' => 1, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        $this->hours('08:00', '20:00');
        $this->sms = new RecordedSms();
        $config = Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!', 'CRON_KEY' => 'cron-key']);
        $this->s = new Services($this->db, $this->clock, $config, new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(4)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'), $this->sms);
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
        $this->s->publicProjection->refreshAll();
        $this->pharmacy = (string) $this->db->one('SELECT id FROM public_pharmacies WHERE tenant_id = ?', ['T1'])['id'];
    }

    /** Horaires du vendredi uniquement. */
    private function hours(string $opens, string $closes): void
    {
        $this->db->execute('DELETE FROM opening_hours WHERE tenant_id = ?', ['T1']);
        $this->db->insert('opening_hours', ['id' => Ulid::generate(), 'tenant_id' => 'T1', 'weekday' => 5, 'is_open' => 1, 'opens' => $opens, 'closes' => $closes]);
        if (isset($this->s)) {
            $this->s->publicProjection->refresh('T1');
        }
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $user = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.7.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($user !== null) {
            $this->tokens[$user] ??= (string) $this->call('POST', '/auth/login', ['email' => "$user@t.cm", 'password' => self::PW])[1]['access'];
            $r = $r->withHeader('Authorization', "Bearer {$this->tokens[$user]}");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    /** @return array<string, mixed> */
    private function send(bool $urgent = false, string $phone = '+237 690 11 22 33'): array
    {
        [$status, $body] = $this->call('POST', "/public/pharmacies/{$this->pharmacy}/requests", [
            'consent' => true, 'first_name' => 'Awa', 'phone' => $phone, 'urgent' => $urgent,
            'documents' => [['name' => 'o.pdf', 'base64' => base64_encode(self::PDF)]],
        ]);
        $this->assertSame(201, $status, json_encode($body));
        return $body;
    }

    /** @return array<string, mixed> */
    private function follow(string $code): array
    {
        return $this->call('GET', "/public/requests/$code")[1];
    }

    private function minutes(int $n): void
    {
        $this->clock->advance("+$n minutes");
        $this->tokens = [];
        $this->s->escalation->run();
    }

    /** @return list<string> */
    private function mailsTo(string $template): array
    {
        $to = [];
        foreach ($this->db->all('SELECT payload FROM jobs WHERE queue = ?', ['mail']) as $j) {
            $p = json_decode((string) $j['payload'], true);
            if ($p['template'] === $template) {
                $to[] = $p['to'];
            }
        }
        return $to;
    }

    /** @return list<string> */
    private function texted(): array
    {
        return array_column($this->sms->sent, 0);
    }

    public function testPresenceTellsThePatientWhetherAPharmacistIsConnected(): void
    {
        $code = $this->send()['code'];
        $this->assertFalse($this->follow($code)['pharmacy']['online']);
        $this->assertSame(204, $this->call('POST', '/app/presence', ['available' => true], 'pharmacist')[0]);
        $this->assertTrue($this->follow($code)['pharmacy']['online']);
        $team = $this->call('GET', '/app/presence', null, 'owner')[1]['items'];
        $this->assertSame([true], array_column(array_filter($team, static fn ($u) => $u['email'] === 'pharmacist@t.cm'), 'online'));

        $this->clock->advance('+46 seconds');
        $this->assertFalse($this->follow($code)['pharmacy']['online'], 'sans signe de vie depuis 45 s');
        $this->call('POST', '/app/presence', ['available' => false], 'pharmacist');
        $this->assertFalse($this->follow($code)['pharmacy']['online'], 'connecté mais indisponible');
        $this->assertSame(403, $this->call('POST', '/app/presence', [], 'cashier')[0]);
    }

    public function testANormalRequestWarnsThePharmacyOnlyWhenNobodyIsConnected(): void
    {
        $this->send();
        $this->assertEqualsCanonicalizing(['owner@t.cm', 'pharmacist@t.cm'], $this->mailsTo('request_new'));
        $this->call('POST', '/app/presence', ['available' => true], 'pharmacist');
        $this->send();
        $this->assertCount(2, $this->mailsTo('request_new'), 'un pharmacien connecté voit la file');
    }

    public function testTheChainGoesFromMailToTheOwnerThenTheDeputyThenAnHonestFallback(): void
    {
        $code = $this->send(true)['code'];
        $this->assertEqualsCanonicalizing(['owner@t.cm', 'pharmacist@t.cm'], $this->mailsTo('request_urgent'));
        $this->assertSame('alerted', $this->follow($code)['escalation']);

        $this->minutes(2);
        $this->assertSame([], $this->texted(), 'pas avant le délai');
        $this->minutes(1);
        $this->assertSame(['+237600000011'], $this->texted(), 'le titulaire d’abord');
        $this->assertStringContainsString('SAN-00001', $this->sms->sent[0][1]);
        $this->assertStringNotContainsString('690', $this->sms->sent[0][1], 'aucune donnée du patient');
        $this->minutes(3);
        $this->assertSame(['+237600000011', '+237600000022'], $this->texted(), 'puis l’adjoint');
        $this->assertSame('texted', $this->follow($code)['escalation']);
        $this->minutes(3);
        $this->assertSame('exhausted', $this->follow($code)['escalation']);
        $this->minutes(10);
        $this->assertCount(2, $this->sms->sent, 'la chaîne s’arrête');

        $id = (string) $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'][0]['id'];
        $kinds = array_column($this->call('GET', "/app/requests/$id", null, 'pharmacist')[1]['events'], 'kind');
        $this->assertSame(['received', 'urgent', 'escalation_mail', 'escalation_sms', 'escalation_sms', 'escalation_exhausted'], $kinds);
    }

    public function testTakingTheRequestStopsTheChain(): void
    {
        $code = $this->send(true)['code'];
        $this->minutes(3);
        $id = (string) $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'][0]['id'];
        $this->call('POST', "/app/requests/$id/take", [], 'pharmacist');
        $this->minutes(3);
        $this->minutes(3);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame('handled', $this->follow($code)['escalation']);
    }

    public function testTheDelayIsSetByThePharmacy(): void
    {
        $this->call('PUT', '/app/settings', ['settings' => ['urgent_escalation_minutes' => 1]], 'owner');
        $this->send(true);
        $this->minutes(1);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(422, $this->call('PUT', '/app/settings', ['settings' => ['urgent_escalation_minutes' => 0]], 'owner')[0]);
    }

    public function testOutsideOpeningHoursThePatientIsSentToAnOnDutyPharmacy(): void
    {
        $this->hours('14:00', '20:00');
        $code = $this->send(true)['code'];
        $this->minutes(10);
        $this->assertSame([], $this->texted());
        $view = $this->follow($code);
        $this->assertSame('closed', $view['escalation']);
        $this->assertFalse($view['pharmacy']['open']);

        $this->call('PUT', '/app/settings', ['settings' => ['urgent_outside_hours' => true]], 'owner');
        $this->send(true, '+237 690 99 99 99');
        $this->minutes(3);
        $this->assertCount(1, $this->sms->sent, 'la pharmacie a choisi d’être alertée hors horaires');
    }

    public function testRepeatedEmergenciesFromOneNumberAreLimited(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertFalse($this->send(true)['urgent_limited']);
        }
        $fourth = $this->send(true);
        $this->assertTrue($fourth['urgent_limited']);
        $this->assertFalse($this->follow($fourth['code'])['urgent']);
        $this->assertCount(6, $this->mailsTo('request_urgent'));
        $this->assertFalse($this->send(true, '+237 677 00 00 00')['urgent_limited'], 'un autre numéro');
    }

    public function testTheCronRunsTheChain(): void
    {
        $this->send(true);
        $this->clock->advance('+3 minutes');
        [$status, $body] = $this->call('GET', '/internal/run-jobs?key=cron-key');
        $this->assertSame(200, $status);
        $this->assertSame(1, $body['escalations']);
        $this->assertCount(1, $this->sms->sent);
    }
}
