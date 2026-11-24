<?php
declare(strict_types=1);

namespace Sanara\Tests\Stock;

use PHPUnit\Framework\TestCase;
use Sanara\Bootstrap;
use Sanara\Common\Auth\PasswordHasher;
use Sanara\Common\Clock;
use Sanara\Common\Config;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Http\Services;
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** Parcours HTTP : réception, perte, quarantaine, destruction, inventaire, droits par rôle et isolation entre pharmacies. */
final class StockWorkflowHttpTest extends TestCase
{
    private App $app;
    private const PW = 'Motdepasse#2026';

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        $clock = new Clock();
        $users = new UserRepository($db, $clock);
        foreach ([['owner', 'T1'], ['storekeeper', 'T1'], ['pharmacist', 'T1'], ['owner', 'T2']] as $i => [$role, $t]) {
            $users->create(['email' => "$role$i@t.cm", 'role_code' => $role, 'tenant_id' => $t, 'password_hash' => (new PasswordHasher())->hash(self::PW)]);
        }
        $s = new Services($db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $s);
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function call(string $m, string $p, ?array $j = null, ?string $tok = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($m, $p, ['REMOTE_ADDR' => '41.0.0.' . random_int(1, 250)]);
        if ($j !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($j)));
        }
        if ($tok) {
            $r = $r->withHeader('Authorization', "Bearer $tok");
        }
        $res = $this->app->handle($r);
        $raw = (string) $res->getBody();
        return [$res->getStatusCode(), json_decode($raw, true) ?? [], $raw];
    }

    private function tok(string $email): string
    {
        return $this->call('POST', '/auth/login', ['email' => $email, 'password' => self::PW])[1]['access'];
    }

    public function testFullWorkflowWithRoles(): void
    {
        $owner = $this->tok('owner0@t.cm');
        $keeper = $this->tok('storekeeper1@t.cm');
        $pharma = $this->tok('pharmacist2@t.cm');
        [, $p] = $this->call('POST', '/app/products', ['name' => 'Amoxicilline 500', 'regulatory_status' => 'prescription_only', 'sale_price' => 2800, 'purchase_price' => 2000, 'min_stock' => 5], $owner);
        $pid = $p['id'];

        [$st, $rec] = $this->call('POST', '/app/stock/receipts', ['product_id' => $pid, 'lot_number' => 'L1', 'expiry' => '2030-01-01', 'quantity' => 10, 'unit_cost' => 2000], $keeper);
        $this->assertSame(201, $st);
        $this->assertFalse($rec['short_dated']);
        $this->assertTrue($this->call('POST', '/app/stock/receipts', ['product_id' => $pid, 'lot_number' => 'L2', 'expiry' => '2026-12-31', 'quantity' => 1, 'unit_cost' => 2000], $keeper)[1]['short_dated']);
        $lots = $this->call('GET', "/app/stock/products/$pid/lots", null, $keeper)[1]['items'];
        $l1 = array_values(array_filter($lots, fn ($l) => $l['lot_number'] === 'L1'))[0]['id'];

        // perte motivée par le magasinier
        $this->assertSame(422, $this->call('POST', '/app/stock/adjustments', ['product_id' => $pid, 'lot_id' => $l1, 'delta' => -2, 'reason' => ''], $keeper)[0]);
        [$st, $adj] = $this->call('POST', '/app/stock/adjustments', ['product_id' => $pid, 'lot_id' => $l1, 'delta' => -2, 'reason' => 'Boîtes écrasées', 'kind' => 'breakage'], $keeper);
        $this->assertSame(201, $st);
        $this->assertSame(201, $this->call('POST', "/app/stock/movements/{$adj['movement_id']}/reverse", ['reason' => 'Erreur de saisie'], $keeper)[0]);
        $this->assertSame(409, $this->call('POST', "/app/stock/movements/{$adj['movement_id']}/reverse", ['reason' => 'Encore'], $keeper)[0]);

        // quarantaine : magasinier oui ; destruction : réservée aux pharmaciens
        $this->assertSame(204, $this->call('POST', "/app/stock/lots/$l1/quarantine", ['reason' => 'Rappel fabricant'], $keeper)[0]);
        $this->assertSame(403, $this->call('POST', "/app/stock/lots/$l1/destroy", ['quantity' => 10, 'reason' => 'Rappel'], $keeper)[0]);
        [$st, $d] = $this->call('POST', "/app/stock/lots/$l1/destroy", ['quantity' => 10, 'reason' => 'Rappel fabricant', 'method' => 'Incinération', 'witness' => 'M. Nkoulou'], $pharma);
        $this->assertSame(201, $st);
        [$st, , $html] = $this->call('GET', "/app/stock/destructions/{$d['id']}/document", null, $keeper);
        $this->assertSame(200, $st);
        $this->assertStringContainsString('Procès-verbal de destruction', $html);
        $this->assertStringContainsString('Amoxicilline 500', $html);

        // l'autre pharmacie ne voit rien de tout cela
        $other = $this->tok('owner3@t.cm');
        $this->assertSame(404, $this->call('GET', "/app/stock/destructions/{$d['id']}/document", null, $other)[0]);
        $this->assertSame([], $this->call('GET', '/app/stock/overview', null, $other)[1]['items']);

        // inventaire : comptage par le magasinier, validation par le pharmacien seulement
        $this->call('POST', '/app/stock/receipts', ['product_id' => $pid, 'lot_number' => 'L3', 'expiry' => '2030-06-01', 'quantity' => 6, 'unit_cost' => 2000], $keeper);
        [, $inv] = $this->call('POST', '/app/inventories', ['kind' => 'full', 'blind' => true], $keeper);
        [, $detail] = $this->call('GET', "/app/inventories/{$inv['id']}", null, $keeper);
        $this->assertNull($detail['lines'][0]['expected_qty']);
        $l3 = array_values(array_filter($detail['lines'], fn ($l) => $l['lot_number'] === 'L3'))[0]['lot_id'];
        $this->assertSame(1, $this->call('POST', "/app/inventories/{$inv['id']}/counts", ['device_id' => 'poste1', 'counts' => [['lot_id' => $l3, 'qty' => 5]]], $keeper)[1]['recorded']);
        $this->assertSame(204, $this->call('POST', "/app/inventories/{$inv['id']}/submit", [], $keeper)[0]);
        $this->assertSame(403, $this->call('POST', "/app/inventories/{$inv['id']}/validate", [], $keeper)[0]);
        [$st, $rep] = $this->call('POST', "/app/inventories/{$inv['id']}/validate", [], $pharma);
        $this->assertSame(200, $st);
        $this->assertSame(-2000, $rep['gap_value']);
        $this->assertStringContainsString('Amoxicilline 500', $this->call('GET', "/app/inventories/{$inv['id']}/report.csv", null, $keeper)[2]);
        $this->assertSame(409, $this->call('POST', "/app/inventories/{$inv['id']}/validate", [], $owner)[0]);
    }
}
