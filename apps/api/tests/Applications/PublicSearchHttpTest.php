<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Common\Ulid;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;

/** T145, T146 : distance, hiérarchie géographique, statut ouvert/fermé et cache de la recherche publique. */
final class PublicSearchHttpTest extends TestCase
{
    private App $app;
    private Db $db;
    private Services $s;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        // Vendredi 27 novembre 2026, 10 h à Douala (UTC+1).
        $this->clock = Clock::fixed('2026-11-27T09:00:00Z');
        $this->s = new Services($this->db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);

        // Ouverte le vendredi de 8 h à 21 h.
        $this->pharmacy('T1', 'Pharmacie du Marché', ['Nord', 'Bénoué', 'Garoua', 'Marouaré'], [9.3017, 13.3921], ['08:00', '21:00']);
        // Environ 2,2 km plus au nord ; n'ouvre que l'après-midi.
        $this->pharmacy('T2', 'Pharmacie des Palmiers', ['Nord', 'Bénoué', 'Garoua', 'Plateau'], [9.3217, 13.3921], ['15:00', '19:00']);
        // Sans point GPS ni horaires.
        $this->pharmacy('T3', 'Pharmacie Annexe', ['Nord', 'Mayo-Louti', 'Guider', 'Centre'], null, null);
        $this->pharmacy('T4', 'Pharmacie du Mfoundi', ['Centre', 'Mfoundi', 'Yaoundé', 'Bastos'], [3.8900, 11.5100], ['08:00', '21:00']);
    }

    /**
     * @param array{0: string, 1: string, 2: string, 3: string} $place
     * @param array{0: float, 1: float}|null $point
     * @param array{0: string, 1: string}|null $friday
     */
    private function pharmacy(string $id, string $name, array $place, ?array $point, ?array $friday): void
    {
        $now = '2026-01-01 00:00:00.000';
        $this->db->insert('tenants', ['id' => $id, 'country_code' => 'CM', 'name' => $name, 'slug' => strtolower($id), 'status' => 'active', 'latitude' => $point[0] ?? null, 'longitude' => $point[1] ?? null, 'public_visible' => 1, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $application = ['id' => "A$id", 'number' => "PH-$id", 'tracking_code' => $id, 'country_code' => 'CM', 'status' => 'approved', 'pharmacy_name' => $name, 'region' => $place[0], 'department' => $place[1], 'city' => $place[2], 'district' => $place[3], 'tenant_id' => $id];
        foreach ($this->db->all('PRAGMA table_info(pharmacy_applications)') as $c) {
            if ((int) $c['notnull'] === 1 && !isset($application[$c['name']])) {
                $application[$c['name']] = str_contains((string) $c['type'], 'INT') ? 0 : 'x';
            }
        }
        $this->db->insert('pharmacy_applications', $application);
        if ($friday !== null) {
            $this->db->insert('opening_hours', ['id' => Ulid::generate(), 'tenant_id' => $id, 'weekday' => 5, 'is_open' => 1, 'opens' => $friday[0], 'closes' => $friday[1]]);
        }
        $this->s->publicProjection->refresh($id);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: \Psr\Http\Message\ResponseInterface} */
    private function get(string $path, array $headers = []): array
    {
        $r = (new ServerRequestFactory())->createServerRequest('GET', $path, ['REMOTE_ADDR' => '41.0.4.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        foreach ($headers as $k => $v) {
            $r = $r->withHeader($k, $v);
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? [], $res];
    }

    /** @return list<string> */
    private function names(string $query): array
    {
        return array_column($this->get("/public/pharmacies$query")[1]['items'], 'pharmacy_name');
    }

    public function testResultsAreSortedByDistanceFromTheVisitorAndUnknownPositionsComeLast(): void
    {
        $body = $this->get('/public/pharmacies?lat=9.3020&lng=13.3921')[1];
        $this->assertSame('position', $body['origin']);
        $this->assertSame(['Pharmacie du Marché', 'Pharmacie des Palmiers', 'Pharmacie du Mfoundi', 'Pharmacie Annexe'], array_column($body['items'], 'pharmacy_name'));
        $this->assertEqualsWithDelta(33, $body['items'][0]['distance_m'], 3);
        $this->assertEqualsWithDelta(2190, $body['items'][1]['distance_m'], 30);
        $this->assertNull($body['items'][3]['distance_m']);
        $this->assertNull($body['items'][3]['latitude']);
    }

    public function testWithoutAPositionResultsAreSortedByNameWithoutDistance(): void
    {
        $body = $this->get('/public/pharmacies')[1];
        $this->assertNull($body['origin']);
        $this->assertSame(['Pharmacie Annexe', 'Pharmacie des Palmiers', 'Pharmacie du Marché', 'Pharmacie du Mfoundi'], array_column($body['items'], 'pharmacy_name'));
        $this->assertNull($body['items'][1]['distance_m']);
    }

    public function testATypedDistrictBecomesTheReferencePoint(): void
    {
        $body = $this->get('/public/pharmacies?city=Garoua&near=' . urlencode('plateau'))[1];
        $this->assertSame('district', $body['origin']);
        $this->assertSame(['Pharmacie des Palmiers', 'Pharmacie du Marché'], array_column($body['items'], 'pharmacy_name'));
        $this->assertSame(0, $body['items'][0]['distance_m']);

        $unknown = $this->get('/public/pharmacies?near=Nulle%20part')[1];
        $this->assertNull($unknown['origin']);
        $this->assertCount(4, $unknown['items']);
    }

    public function testSearchFollowsTheGeographicHierarchy(): void
    {
        $this->assertCount(3, $this->names('?region=Nord'));
        $this->assertSame(['Pharmacie Annexe'], $this->names('?region=Nord&department=Mayo-Louti'));
        $this->assertSame(['Pharmacie des Palmiers'], $this->names('?city=Garoua&district=Plateau'));
        $this->assertCount(2, $this->names('?q=' . urlencode('bénoué')));
        $this->assertSame([], $this->names('?country=TD'));
        $this->assertCount(4, $this->names('?country=CM'));
    }

    public function testBoundingBoxKeepsOnlyTheVisibleArea(): void
    {
        $this->assertSame(['Pharmacie des Palmiers', 'Pharmacie du Marché'], $this->names('?bbox=13.0,9.0,14.0,10.0'));
        $this->assertSame(422, $this->get('/public/pharmacies?bbox=13.0,9.0')[0]);
        $this->assertSame(422, $this->get('/public/pharmacies?lat=200&lng=13')[0]);
        $this->assertSame(422, $this->get('/public/pharmacies?lat=9.3')[0]);
    }

    public function testOpeningStatusUsesTheCountryTimeAndCanFilter(): void
    {
        $items = array_column($this->get('/public/pharmacies')[1]['items'], null, 'pharmacy_name');
        $this->assertTrue($items['Pharmacie du Marché']['open']);
        $this->assertSame('21:00', $items['Pharmacie du Marché']['closes']);
        $this->assertFalse($items['Pharmacie des Palmiers']['open']);
        $this->assertSame(['weekday' => 5, 'opens' => '15:00', 'in_days' => 0], $items['Pharmacie des Palmiers']['next_opening']);
        $this->assertNull($items['Pharmacie Annexe']['open'], 'horaires non communiqués : ni ouverte ni fermée');

        $this->assertSame(['Pharmacie du Marché', 'Pharmacie du Mfoundi'], $this->names('?open=1'));
        $this->assertSame(2, $this->get('/public/pharmacies?open=1')[1]['total']);
        $this->clock->advance('+12 hours');
        $this->assertSame([], $this->names('?open=1'));
    }

    public function testPaginationCountsEveryMatch(): void
    {
        $body = $this->get('/public/pharmacies?limit=1&offset=1&lat=9.3017&lng=13.3921')[1];
        $this->assertSame(4, $body['total']);
        $this->assertSame(['Pharmacie des Palmiers'], array_column($body['items'], 'pharmacy_name'));
    }

    public function testAnUnchangedAnswerIsNotSentTwice(): void
    {
        [, , $first] = $this->get('/public/pharmacies');
        $etag = $first->getHeaderLine('ETag');
        $this->assertNotSame('', $etag);
        $this->assertStringContainsString('no-cache', $first->getHeaderLine('Cache-Control'));
        [$status, , $second] = $this->get('/public/pharmacies', ['If-None-Match' => $etag]);
        $this->assertSame(304, $status);
        $this->assertSame('', (string) $second->getBody());

        $this->db->execute('UPDATE public_pharmacies SET visible = 0 WHERE tenant_id = ?', ['T1']);
        $this->assertSame(200, $this->get('/public/pharmacies', ['If-None-Match' => $etag])[0]);
    }

    public function testTheGeographicReferenceListsOnlyPublishedPlaces(): void
    {
        $this->db->execute('UPDATE tenants SET public_visible = 0 WHERE id = ?', ['T4']);
        $this->s->publicProjection->refresh('T4');
        $places = $this->get('/public/geo')[1]['items'];
        $this->assertSame(
            [
                ['country_code' => 'CM', 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Marouaré', 'pharmacies' => 1],
                ['country_code' => 'CM', 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Plateau', 'pharmacies' => 1],
                ['country_code' => 'CM', 'region' => 'Nord', 'department' => 'Mayo-Louti', 'city' => 'Guider', 'district' => 'Centre', 'pharmacies' => 1],
            ],
            $places,
        );
    }
}
