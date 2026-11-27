<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** US19, T156-T158 : la pharmacie tient sa vitrine et ses gardes ; le public voit la fiche et la page de garde. */
final class StorefrontHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    private Clock $clock;
    private Services $s;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        // Vendredi 27 novembre 2026, 10 h à Douala (UTC+1).
        $this->clock = Clock::fixed('2026-11-27T09:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        foreach (['T1' => 'Pharmacie du Marché', 'T2' => 'Pharmacie des Palmiers'] as $id => $name) {
            $this->db->insert('tenants', ['id' => $id, 'country_code' => 'CM', 'name' => $name, 'slug' => strtolower($id), 'status' => 'active', 'public_visible' => 1, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
            $users->create(['email' => strtolower("owner@$id.cm"), 'role_code' => 'owner', 'tenant_id' => $id, 'password_hash' => $hash]);
        }
        $users->create(['email' => 'cashier@t1.cm', 'role_code' => 'cashier', 'tenant_id' => 'T1', 'password_hash' => $hash]);
        $this->s = new Services($this->db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
        $this->s->publicProjection->refreshAll();
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $email = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.5.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($email !== null) {
            $this->tokens[$email] ??= (string) $this->call('POST', '/auth/login', ['email' => $email, 'password' => self::PW])[1]['access'];
            $r = $r->withHeader('Authorization', 'Bearer ' . $this->tokens[$email]);
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    /** @return array<string, mixed> */
    private function profile(array $changes = []): array
    {
        return $changes + [
            'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Marouaré', 'address' => 'Face au marché central',
            'phone' => '+237600000001', 'email' => 'contact@t1.cm', 'latitude' => 9.3017, 'longitude' => 13.3921,
            'services' => ['malaria_test', 'mobile_money'], 'insurances' => ['Mutuelle des enseignants', '  '],
        ];
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return array_column($this->call('GET', "/public/pharmacies$query")[1]['items'], 'pharmacy_name');
    }

    /** @return int statut de l'ajout */
    private function duty(string $date, string $from, string $to, string $email = 'owner@t1.cm'): int
    {
        return $this->call('POST', '/app/storefront/duties', ['date' => $date, 'from' => $from, 'to' => $to], $email)[0];
    }

    public function testTheProfileFeedsThePublicPage(): void
    {
        $this->assertSame(204, $this->call('PUT', '/app/storefront', $this->profile(), 'owner@t1.cm')[0]);
        $this->call('PUT', '/app/opening-hours', ['days' => [['weekday' => 5, 'is_open' => true, 'opens' => '08:00', 'closes' => '21:00'], ['weekday' => 7, 'is_open' => false]]], 'owner@t1.cm');

        $mine = $this->call('GET', '/app/storefront', null, 'owner@t1.cm')[1];
        $this->assertSame('Garoua', $mine['profile']['city']);
        $this->assertSame(['Mutuelle des enseignants'], $mine['profile']['insurances']);
        $this->assertContains('vitals', $mine['available_services']);
        $this->assertTrue($mine['visible']);

        $id = $this->call('GET', '/public/pharmacies?city=Garoua')[1]['items'][0]['id'];
        [$status, $page] = $this->call('GET', "/public/pharmacies/$id");
        $this->assertSame(200, $status);
        $this->assertSame('Pharmacie du Marché', $page['pharmacy_name']);
        $this->assertSame('Face au marché central', $page['address']);
        $this->assertSame('contact@t1.cm', $page['email']);
        $this->assertSame(['malaria_test', 'mobile_money'], $page['services']);
        $this->assertSame(['Mutuelle des enseignants'], $page['insurances']);
        $this->assertSame([['weekday' => 5, 'opens' => '08:00', 'closes' => '21:00']], $page['week']);
        $this->assertTrue($page['open']);
        $this->assertSame([], $page['duties']);
        $this->assertArrayNotHasKey('tenant_id', $page);
    }

    public function testAHiddenPharmacyHasNoPublicPage(): void
    {
        $id = $this->call('GET', '/public/pharmacies?q=march')[1]['items'][0]['id'];
        $this->call('PUT', '/app/visibility', ['visible' => false], 'owner@t1.cm');
        $this->assertSame(404, $this->call('GET', "/public/pharmacies/$id")[0]);
        $this->assertSame(404, $this->call('GET', '/public/pharmacies/inconnue')[0]);
    }

    public function testInvalidProfilesAreRefusedAndOnlyTheOwnerMayWrite(): void
    {
        foreach ([['latitude' => 95], ['longitude' => null], ['services' => ['chirurgie']], ['email' => 'pas-une-adresse'], ['city' => str_repeat('a', 81)]] as $bad) {
            $this->assertSame(422, $this->call('PUT', '/app/storefront', $this->profile($bad), 'owner@t1.cm')[0], json_encode($bad));
        }
        $this->assertSame(403, $this->call('PUT', '/app/storefront', $this->profile(), 'cashier@t1.cm')[0]);
        $this->assertSame(401, $this->call('PUT', '/app/storefront', $this->profile())[0]);
        $this->assertSame(403, $this->duty('2026-11-28', '08:00', '20:00', 'cashier@t1.cm'));
    }

    public function testADutyNightIsListedWithItsLocalHours(): void
    {
        $this->assertSame(201, $this->duty('2026-11-27', '20:00', '07:00'));

        $this->assertSame(['Pharmacie du Marché'], $this->names('?duty=tonight'));
        $item = $this->call('GET', '/public/pharmacies?duty=tonight')[1]['items'][0];
        $this->assertSame(['starts' => '2026-11-27T20:00', 'ends' => '2026-11-28T07:00', 'now' => false], $item['duty']);
        $this->assertCount(2, $this->names(''), 'sans filtre, toutes les pharmacies restent listées');

        $this->clock->advance('+11 hours'); // 21 h : la garde a commencé
        $this->assertTrue($this->call('GET', '/public/pharmacies?duty=tonight')[1]['items'][0]['duty']['now']);
        $this->clock->advance('+11 hours'); // le lendemain 8 h : elle est finie
        $this->assertSame([], $this->names('?duty=week'));
        $this->assertNull($this->call('GET', '/public/pharmacies?q=march')[1]['items'][0]['duty']);
    }

    public function testDutyPeriodsFollowTonightWeekAndWeekend(): void
    {
        $this->duty('2026-11-29', '08:00', '20:00'); // dimanche
        $this->duty('2026-12-02', '20:00', '07:00', 'owner@t2.cm'); // mercredi suivant
        $this->assertSame([], $this->names('?duty=tonight'));
        $this->assertSame(['Pharmacie du Marché'], $this->names('?duty=weekend'));
        $this->assertSame(['Pharmacie des Palmiers', 'Pharmacie du Marché'], $this->names('?duty=week'));
        $this->assertSame(422, $this->call('GET', '/public/pharmacies?duty=jamais')[0]);
    }

    public function testDutiesCannotBePastOverlappingOrBelongToAnotherPharmacy(): void
    {
        $this->assertSame(422, $this->duty('2026-11-26', '08:00', '20:00'), 'déjà terminée');
        $this->assertSame(422, $this->duty('2026-02-30', '08:00', '20:00'));
        $this->assertSame(422, $this->duty('2028-01-01', '08:00', '20:00'), 'trop lointaine');
        $this->assertSame(422, $this->duty('2026-11-28', '25:00', '20:00'));
        $this->assertSame(201, $this->duty('2026-11-28', '20:00', '07:00'));
        $this->assertSame(422, $this->duty('2026-11-29', '06:00', '12:00'), 'chevauche la nuit précédente');
        $this->assertSame(201, $this->duty('2026-11-29', '07:00', '12:00'));

        $mine = $this->call('GET', '/app/storefront', null, 'owner@t1.cm')[1]['duties'];
        $this->assertCount(2, $mine);
        $this->assertSame(404, $this->call('DELETE', '/app/storefront/duties/' . $mine[0]['id'], null, 'owner@t2.cm')[0]);
        $this->assertSame(204, $this->call('DELETE', '/app/storefront/duties/' . $mine[0]['id'], null, 'owner@t1.cm')[0]);
        $this->assertSame([], $this->names('?duty=tonight&q=palmiers'));
        $this->assertCount(1, $this->call('GET', '/app/storefront', null, 'owner@t1.cm')[1]['duties']);
    }
}
