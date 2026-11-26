<?php
declare(strict_types=1);

namespace Sanara\Tests\Sync;

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
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** T137 : envoi des opérations produites hors-ligne, idempotent, avec signalement des écarts. */
final class SyncPushHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    /** @var array<string, string> */
    private array $ids = [];
    /** @var array<string, string> */
    private array $tokens = [];
    private int $minute = 0;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        $clock = Clock::fixed('2026-11-26T18:00:00Z');
        $users = new UserRepository($this->db, $clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        foreach (['owner' => 'T1', 'cashier' => 'T1', 'pharmacist' => 'T1', 'storekeeper' => 'T1', 'other' => 'T2'] as $name => $tenant) {
            $this->ids[$name] = (string) $users->create(['email' => "$name@t.cm", 'role_code' => $name === 'other' ? 'owner' : $name, 'tenant_id' => $tenant, 'password_hash' => $hash]);
        }
        foreach (['T1', 'T2'] as $t) {
            $this->db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "Pharmacie $t", 'slug' => strtolower($t), 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => "AG-$t", 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        }
        $services = new Services($this->db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $services);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $token = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.1.' . random_int(1, 250)]);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($token !== null) {
            $r = $r->withHeader('Authorization', "Bearer $token");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function token(string $user): string
    {
        return $this->tokens[$user] ??= $this->call('POST', '/auth/login', ['email' => "$user@t.cm", 'password' => self::PW])[1]['access'];
    }

    private function product(string $name = 'Paracétamol', string $status = 'free', int $price = 1000, int $stock = 5, string $user = 'owner'): string
    {
        $id = $this->call('POST', '/app/products', ['name' => $name, 'regulatory_status' => $status, 'sale_price' => $price, 'purchase_price' => 600, 'vat_bp' => 0], $this->token($user))[1]['id'];
        if ($stock > 0) {
            $this->call('POST', '/app/stock/receipts', ['product_id' => $id, 'lot_number' => 'L-' . substr($id, -6), 'expiry' => '2030-01-01', 'quantity' => $stock, 'unit_cost' => 600], $this->token($user));
        }
        return $id;
    }

    private function onHand(string $product): int
    {
        return $this->call('GET', "/app/stock/products/$product/on-hand", null, $this->token('owner'))[1]['on_hand'];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function op(string $user, string $type, array $payload, ?string $at = null): array
    {
        $at ??= sprintf('2026-11-26T09:%02d:00.000Z', ++$this->minute);
        return ['id' => Ulid::generate(), 'deviceId' => 'poste-1', 'userId' => $this->ids[$user], 'type' => $type, 'schemaVersion' => 1, 'createdAt' => $at, 'payload' => $payload];
    }

    /** @return array<string, mixed> */
    private function openOp(string $user = 'cashier', string $station = 'C1', ?string &$session = null): array
    {
        $session = Ulid::generate();
        return $this->op($user, 'cash.open', ['sessionId' => $session, 'station' => $station, 'openingFloat' => 10000]);
    }

    /** @param array<string, mixed> $over @return array<string, mixed> */
    private function saleOp(string $session, string $product, int $qty = 1, int $n = 1, array $over = [], string $user = 'cashier'): array
    {
        return $this->op($user, 'sale.create', $over + [
            'saleId' => Ulid::generate(), 'sessionId' => $session, 'number' => sprintf('V-C1-2026-%05d', $n),
            'lines' => [['productId' => $product, 'qty' => $qty, 'unitPrice' => 1000, 'discountBp' => 0, 'vatBp' => 0]],
            'payments' => [['method' => 'cash', 'amount' => 1000 * $qty]],
        ]);
    }

    /** @param list<array<string, mixed>> $ops @return list<array<string, mixed>> */
    private function push(array $ops, string $user = 'cashier', string $sentAt = '2026-11-26T18:00:00.000Z'): array
    {
        [$status, $body] = $this->call('POST', '/sync/push', ['deviceId' => 'poste-1', 'sentAt' => $sentAt, 'operations' => $ops], $this->token($user));
        $this->assertSame(200, $status, json_encode($body));
        return $body['results'];
    }

    /** @param list<array<string, mixed>> $results @return list<string> */
    private function statuses(array $results): array
    {
        return array_column($results, 'status');
    }

    private function rows(string $table): int
    {
        return (int) $this->db->one("SELECT COUNT(*) AS n FROM $table")['n'];
    }

    public function testAnonymousAndRolesWithoutSalesRightAreRefused(): void
    {
        $this->assertSame(401, $this->call('POST', '/sync/push', ['operations' => []])[0]);
        $this->assertSame(403, $this->call('POST', '/sync/push', ['operations' => []], $this->token('storekeeper'))[0]);
    }

    public function testOfflineDayIsAppliedOnceWithClientIdentifiersAndNumbers(): void
    {
        $p = $this->product();
        $open = $this->openOp(session: $session);
        $sale = $this->saleOp($session, $p, 2, 7);
        $close = $this->op('cashier', 'cash.close', ['sessionId' => $session, 'counts' => [['denomination' => 1000, 'qty' => 12]], 'reason' => null]);

        // Envoyées dans le désordre : le serveur les rejoue dans l'ordre chronologique.
        $results = $this->push([$close, $sale, $open]);
        $this->assertSame(['applied', 'applied', 'applied'], $this->statuses($results));
        $this->assertSame([[], [], []], array_column($results, 'conflicts'));
        $this->assertSame(3, $this->onHand($p));

        $row = $this->db->one('SELECT * FROM sales WHERE id = ?', [$sale['payload']['saleId']]);
        $this->assertSame('V-C1-2026-00007', $row['number']);
        $this->assertSame('2026-11-26 09:02:00.000', $row['created_at'], 'heure du poste conservée');
        $closed = $this->db->one('SELECT * FROM cash_sessions WHERE id = ?', [$session]);
        $this->assertSame(['closed', '0'], [$closed['status'], (string) $closed['gap']]);

        // Reprise après coupure : le même lot ne change plus rien.
        $this->assertSame(['duplicate', 'duplicate', 'duplicate'], $this->statuses($this->push([$open, $sale, $close])));
        $this->assertSame(3, $this->onHand($p));
        $this->assertSame(1, $this->rows('sales'));
        $this->assertSame(1, $this->rows('payments'));
    }

    public function testOnlineSaleDoesNotReuseAnOfflineNumber(): void
    {
        $p = $this->product();
        $open = $this->openOp(session: $session);
        $this->push([$open, $this->saleOp($session, $p, 1, 7)]);
        // La session ouverte hors-ligne date du jour : la vente en ligne la réutilise.
        [$status, $sale] = $this->call('POST', '/app/sales', ['lines' => [['product_id' => $p, 'qty' => 1]], 'payments' => [['method' => 'cash', 'amount' => 1000]]], $this->token('cashier'));
        $this->assertSame(201, $status, json_encode($sale));
        $this->assertSame('V-C1-2026-00008', $sale['number']);
    }

    public function testTwoStationsSellingTheSameStockKeepTheExactSumAndFlagTheShortage(): void
    {
        $p = $this->product(stock: 5);
        $a = $this->openOp('cashier', 'C1', $first);
        $b = $this->openOp('pharmacist', 'C2', $second);
        $this->push([$a, $this->saleOp($first, $p, 4, 1)]);
        $results = $this->push([$b, $this->saleOp($second, $p, 3, 1, ['number' => 'V-C2-2026-00001'], 'pharmacist')], 'pharmacist');

        $this->assertSame(['applied', 'applied'], $this->statuses($results));
        $this->assertSame(['stock_shortage'], array_column($results[1]['conflicts'], 'kind'));
        $this->assertSame(2, $results[1]['conflicts'][0]['details']['missing']);
        $this->assertSame(-2, $this->onHand($p), 'le stock est la somme des mouvements');
        $this->assertSame(1, $this->rows('sync_conflicts'));
    }

    public function testAnOperationOfAnotherUserStaysOnTheDevice(): void
    {
        $open = $this->openOp('cashier', session: $session);
        $results = $this->push([$open], 'owner');
        $this->assertSame(['rejected'], $this->statuses($results));
        $this->assertSame('other_user', $results[0]['code']);
        $this->assertSame(0, $this->rows('cash_sessions'));
    }

    public function testRegulatedProductNeedsAPharmacistAccountAndAPrescription(): void
    {
        $p = $this->product('Amoxicilline', 'prescription_only');
        $prescription = ['ref' => 'ORD-1', 'prescriber' => 'Dr Mballa', 'patientName' => 'Aïcha Bello'];

        $open = $this->openOp(session: $session);
        $results = $this->push([$open, $this->saleOp($session, $p, 1, 1, ['prescription' => $prescription])]);
        $this->assertSame(['applied', 'rejected'], $this->statuses($results));
        $this->assertSame('prescription_required', $results[1]['code']);
        $this->assertSame(5, $this->onHand($p));

        $ph = $this->openOp('pharmacist', 'C2', $other);
        $missing = $this->saleOp($other, $p, 1, 1, ['number' => 'V-C2-2026-00001'], 'pharmacist');
        $ok = $this->saleOp($other, $p, 1, 2, ['number' => 'V-C2-2026-00002', 'prescription' => $prescription], 'pharmacist');
        $results = $this->push([$ph, $missing, $ok], 'pharmacist');
        $this->assertSame(['applied', 'rejected', 'applied'], $this->statuses($results));
        $this->assertSame($this->ids['pharmacist'], $this->db->one('SELECT pharmacist_id FROM sales')['pharmacist_id']);
    }

    public function testRejectedSaleLeavesNothingBehindAndCanBeSentAgain(): void
    {
        $p = $this->product();
        $open = $this->openOp(session: $session);
        $short = $this->saleOp($session, $p, 2, 1, ['payments' => [['method' => 'cash', 'amount' => 500]]]);
        $results = $this->push([$open, $short]);
        $this->assertSame('payment_insufficient', $results[1]['code']);
        $this->assertSame(0, $this->rows('sales'));
        $this->assertSame(5, $this->onHand($p));

        $short['payload']['payments'] = [['method' => 'cash', 'amount' => 2000]];
        $this->assertSame(['applied'], $this->statuses($this->push([$short])));
        $this->assertSame(3, $this->onHand($p));
    }

    public function testOfflineLimitsOnPaymentsAndDiscounts(): void
    {
        $p = $this->product(stock: 20);
        $open = $this->openOp(session: $session);
        $momo = $this->saleOp($session, $p, 1, 1, ['payments' => [['method' => 'momo_mtn', 'amount' => 1000, 'reference' => 'X1']]]);
        $line = ['productId' => $p, 'qty' => 1, 'unitPrice' => 1000, 'vatBp' => 0];
        $big = $this->saleOp($session, $p, 1, 2, ['lines' => [$line + ['discountBp' => 2000]]]);
        $small = $this->saleOp($session, $p, 1, 3, ['lines' => [$line + ['discountBp' => 500]]]);
        $results = $this->push([$open, $momo, $big, $small]);
        $this->assertSame(['applied', 'rejected', 'rejected', 'applied'], $this->statuses($results));
        $this->assertSame('payment_offline_forbidden', $results[1]['code']);
        $this->assertSame('discount_needs_approval', $results[2]['code']);
    }

    public function testPriceThatChangedSinceTheDeviceLastSyncedIsKeptAndFlagged(): void
    {
        $p = $this->product(price: 1200);
        $open = $this->openOp(session: $session);
        $results = $this->push([$open, $this->saleOp($session, $p)]);
        $this->assertSame(['price_mismatch'], array_column($results[1]['conflicts'], 'kind'));
        $this->assertSame('1000', (string) $this->db->one('SELECT total FROM sales')['total'], 'montant du ticket remis au client');
    }

    public function testUsedNumberForeignProductAndForeignSessionAreRejected(): void
    {
        $p = $this->product();
        $foreign = $this->product('Autre', user: 'other');
        $open = $this->openOp(session: $session);
        $results = $this->push([$open, $this->saleOp($session, $p, 1, 1), $this->saleOp($session, $p, 1, 1), $this->saleOp($session, $foreign, 1, 2), $this->saleOp(Ulid::generate(), $p, 1, 3), $this->saleOp($session, $p, 1, 4, ['number' => 'V-AUTRE-2026-00004'])]);
        $this->assertSame(['applied', 'applied', 'rejected', 'rejected', 'rejected', 'rejected'], $this->statuses($results));
        $this->assertSame(['number_taken', 'not_found', 'not_found', 'validation_failed'], array_column(array_slice($results, 2), 'code'));
    }

    public function testNewerSchemaAndMalformedOperationsAreRejectedOneByOne(): void
    {
        $open = $this->openOp(session: $session);
        $newer = ['schemaVersion' => 2] + $this->op('cashier', 'cash.open', []);
        $unknown = $this->op('cashier', 'stock.erase', []);
        $bad = ['id' => 'court'] + $this->op('cashier', 'cash.open', []);
        $results = $this->push([$newer, $unknown, $bad, $open]);
        $byId = array_column($results, null, 'id');
        $this->assertSame('schema_too_new', $byId[$newer['id']]['code']);
        $this->assertSame('unknown_operation', $byId[$unknown['id']]['code']);
        $this->assertSame('validation_failed', $byId['court']['code']);
        $this->assertSame('applied', $byId[$open['id']]['status']);
    }

    public function testDeviceClockFarAheadIsFlagged(): void
    {
        $open = $this->openOp(session: $session);
        $open['createdAt'] = '2026-11-27T09:00:00.000Z';
        $results = $this->push([$open]);
        $this->assertSame(['clock_skew'], array_column($results[0]['conflicts'], 'kind'));

        [, $body] = $this->call('POST', '/sync/push', ['deviceId' => 'poste-1', 'sentAt' => '2026-11-26T18:10:00.000Z', 'operations' => []], $this->token('cashier'));
        $this->assertSame(600, $body['clockSkewSeconds']);
        $this->assertSame('2026-11-26T18:00:00.000Z', $body['serverTime']);
    }

    public function testBatchIsBounded(): void
    {
        [$status] = $this->call('POST', '/sync/push', ['deviceId' => 'poste-1', 'operations' => array_fill(0, 201, [])], $this->token('cashier'));
        $this->assertSame(422, $status);
    }
}
