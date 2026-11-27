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
use Sanara\Tests\Support\TestDb;
use Slim\App;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/**
 * US21, US22, US23, US26 (T172, T180) : dépôt d'ordonnance avec consentement, traitement verrouillé,
 * devis avec stock réservé, décision du patient, retrait au comptoir avec le code.
 */
final class PatientRequestHttpTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";
    private App $app;
    private Db $db;
    private Clock $clock;
    private Services $s;
    private string $dir;
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
        foreach (['owner' => 'T1', 'pharmacist' => 'T1', 'cashier' => 'T1', 'storekeeper' => 'T1', 'other' => 'T2'] as $name => $tenant) {
            $users->create(['email' => "$name@t.cm", 'role_code' => $name === 'other' ? 'owner' : $name, 'tenant_id' => $tenant, 'password_hash' => $hash]);
        }
        foreach (['T1', 'T2'] as $t) {
            $this->db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "Pharmacie $t", 'slug' => strtolower($t), 'status' => 'active', 'phone' => '+237600000001', 'public_visible' => 1, 'currency' => 'XAF', 'autonomy_level' => 1, 'license_number' => "AG-$t", 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        }
        $this->dir = sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(4));
        $this->s = new Services($this->db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage($this->dir, random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
        $this->s->publicProjection->refreshAll();
        $this->pharmacy = (string) $this->db->one('SELECT id FROM public_pharmacies WHERE tenant_id = ?', ['T1'])['id'];
    }

    /** @return array{0: int, 1: array<string, mixed>, 2: string} */
    private function call(string $method, string $path, ?array $json = null, ?string $user = null, string $ip = ''): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => $ip !== '' ? $ip : '41.0.6.' . random_int(1, 250)]);
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
        $body = (string) $res->getBody();
        return [$res->getStatusCode(), json_decode($body, true) ?? [], $body];
    }

    /** @return array<string, mixed> */
    private function form(array $changes = []): array
    {
        return $changes + [
            'consent' => true, 'first_name' => 'Aïssatou', 'phone' => '+237 690 11 22 33',
            'documents' => [['name' => 'ordonnance.pdf', 'base64' => base64_encode(self::PDF)]],
        ];
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function send(array $changes = [], string $ip = ''): array
    {
        $r = $this->call('POST', "/public/pharmacies/{$this->pharmacy}/requests", $this->form($changes), null, $ip);
        return [$r[0], $r[1]];
    }

    /** @return array<string, mixed> */
    private function follow(string $code): array
    {
        [$status, $body] = $this->call('GET', "/public/requests/$code");
        $this->assertSame(200, $status);
        return $body;
    }

    private function product(string $name = 'Paracétamol 500', int $price = 1000, int $stock = 10): string
    {
        $id = $this->call('POST', '/app/products', ['name' => $name, 'regulatory_status' => 'free', 'sale_price' => $price, 'purchase_price' => 600, 'vat_bp' => 0], 'owner')[1]['id'];
        if ($stock > 0) {
            $this->assertSame(201, $this->call('POST', '/app/stock/receipts', ['product_id' => $id, 'lot_number' => 'L1', 'expiry' => '2030-01-01', 'quantity' => $stock, 'unit_cost' => 600], 'owner')[0]);
        }
        return $id;
    }

    private function queued(): string
    {
        return (string) $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'][0]['id'];
    }

    /**
     * Demande prise en charge puis devis envoyé.
     *
     * @param list<array<string, mixed>> $lines
     * @return array{0: string, 1: string} code de suivi, identifiant
     */
    private function quoted(array $lines): array
    {
        $code = $this->send()[1]['code'];
        // La demande qui vient d'arriver, même si d'autres sont déjà en cours.
        $id = (string) array_values(array_filter($this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'], static fn ($i) => $i['status'] === 'received'))[0]['id'];
        $this->assertSame(204, $this->call('POST', "/app/requests/$id/take", [], 'pharmacist')[0]);
        [$status, $body] = $this->call('POST', "/app/requests/$id/quote", ['lines' => $lines], 'pharmacist');
        $this->assertSame(201, $status, json_encode($body));
        return [$code, $id];
    }

    public function testNothingIsStoredWithoutConsent(): void
    {
        [$status, $body] = $this->send(['consent' => false]);
        $this->assertSame(422, $status);
        $this->assertSame('consent_required', $body['error']['code']);
        $this->assertSame([], glob($this->dir . '/*') ?: []);
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) AS n FROM patient_requests')['n']);
    }

    public function testASubmissionIsQueuedEncryptedAndFollowedWithoutExposingDocuments(): void
    {
        [$status, $sent] = $this->send();
        $this->assertSame(201, $status);
        $this->assertSame('received', $sent['status']);
        $this->assertGreaterThanOrEqual(32, strlen($sent['code']));
        $this->assertNull($sent['advice']);

        $stored = glob($this->dir . '/*') ?: [];
        $this->assertCount(1, $stored);
        $this->assertStringNotContainsString('%PDF', (string) file_get_contents($stored[0]));

        [, $followed, $raw] = $this->call('GET', "/public/requests/{$sent['code']}");
        $this->assertSame($sent['number'], $followed['number']);
        $this->assertSame('Pharmacie T1', $followed['pharmacy']['name']);
        $this->assertSame(['received'], array_column($followed['events'], 'kind'));
        $this->assertStringNotContainsString('690', $raw, 'le numéro du patient n’est pas renvoyé');
        $this->assertStringNotContainsString('ordonnance.pdf', $raw);
        $this->assertSame(404, $this->call('GET', '/public/requests/' . str_repeat('A', 32))[0]);

        $item = $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'][0];
        $this->assertSame([$sent['number'], 'Aïssatou', 1], [$item['number'], $item['first_name'], $item['pages']]);
        $detail = $this->call('GET', "/app/requests/{$item['id']}", null, 'pharmacist')[1];
        $this->assertSame('+237 690 11 22 33', $detail['phone']);
        [$status, , $bytes] = $this->call('GET', "/app/requests/{$item['id']}/documents/{$detail['documents'][0]['id']}", null, 'pharmacist');
        $this->assertSame([200, self::PDF], [$status, $bytes]);
    }

    public function testRequestsStayInsideTheirPharmacyAndRole(): void
    {
        $this->send();
        $id = $this->queued();
        $this->assertSame(403, $this->call('GET', '/app/requests', null, 'storekeeper')[0]);
        $this->assertSame(403, $this->call('GET', "/app/requests/$id", null, 'cashier')[0]);
        $this->assertSame([], $this->call('GET', '/app/requests', null, 'other')[1]['items']);
        $this->assertSame(404, $this->call('GET', "/app/requests/$id", null, 'other')[0]);
        $this->assertSame(404, $this->call('POST', "/app/requests/$id/take", [], 'other')[0]);
        $this->assertSame(401, $this->call('GET', "/app/requests/$id")[0]);
    }

    public function testBadFilesAndHiddenPharmaciesAreRefused(): void
    {
        $this->assertSame('file_type_not_allowed', $this->send(['documents' => [['name' => 'a.pdf', 'base64' => base64_encode('<?php echo 1;')]]])[1]['error']['code']);
        $this->assertSame(422, $this->send(['documents' => []])[0]);
        $this->assertSame(422, $this->send(['documents' => array_fill(0, 6, ['name' => 'p.pdf', 'base64' => base64_encode(self::PDF)])])[0]);
        $this->assertSame(422, $this->send(['phone' => '12', 'first_name' => ' '])[0]);
        $this->assertSame([], glob($this->dir . '/*') ?: [], 'aucun fichier orphelin');

        $this->db->execute('UPDATE public_pharmacies SET visible = 0 WHERE tenant_id = ?', ['T1']);
        $this->assertSame(404, $this->send()[0]);
    }

    public function testRepeatedSubmissionsAreLimited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(201, $this->send([], '41.1.1.1')[0], "envoi $i");
        }
        $this->assertSame(429, $this->send([], '41.1.1.1')[0]);
    }

    public function testSevereSymptomsGetTheEmergencyMessageAndRaiseTheRequest(): void
    {
        $normal = $this->send(['message' => 'Je passerai ce soir.'])[1];
        $this->assertNull($normal['advice']);
        $grave = $this->send(['message' => 'Mon père a une douleur dans la poitrine et du mal à respirer'])[1];
        $this->assertSame('emergency', $grave['advice']);
        $this->assertSame('emergency', $this->follow($grave['code'])['advice']);
        $items = $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'];
        $this->assertSame($grave['number'], $items[0]['number'], 'les urgences passent devant');
        $this->assertTrue($items[0]['urgent']);
    }

    public function testOnlyOnePharmacistHandlesARequestAtATime(): void
    {
        $this->send();
        $id = $this->queued();
        $this->assertSame(204, $this->call('POST', "/app/requests/$id/take", [], 'pharmacist')[0]);
        $this->assertTrue($this->call('GET', "/app/requests/$id", null, 'pharmacist')[1]['mine']);
        $this->assertFalse($this->call('GET', "/app/requests/$id", null, 'owner')[1]['mine']);
        [$status, $body] = $this->call('POST', "/app/requests/$id/take", [], 'owner');
        $this->assertSame([409, 'request_locked'], [$status, $body['error']['code']]);
        $this->assertSame(409, $this->call('POST', "/app/requests/$id/quote", ['lines' => []], 'owner')[0]);
        $this->clock->advance('+16 minutes');
        $this->tokens = [];
        $this->assertSame(204, $this->call('POST', "/app/requests/$id/take", [], 'owner')[0], 'un verrou abandonné expire');
    }

    public function testTheQuoteUsesCataloguePricesReservesStockAndRecordsCorrections(): void
    {
        $p = $this->product('Paracétamol 500', 1000, 10);
        [$code, $id] = $this->quoted([
            ['product_id' => $p, 'quantity' => 4, 'read_text' => 'Paracétamol 1g', 'read_confidence' => 40],
            ['label' => 'Sirop introuvable', 'quantity' => 1],
        ]);
        $quote = $this->follow($code)['quote'];
        $this->assertSame(4000, $quote['total']);
        $this->assertSame(['Paracétamol 500', 'Sirop introuvable'], array_column($quote['lines'], 'label'));
        $this->assertSame([true, false], array_column($quote['lines'], 'available'));
        $detail = $this->call('GET', "/app/requests/$id", null, 'pharmacist')[1];
        $this->assertSame('Paracétamol 1g', $detail['quote']['lines'][0]['read_text']);
        $this->assertTrue($detail['quote']['lines'][0]['corrected']);

        // Le stock réservé n'est plus proposé à un second patient.
        $this->send();
        $other = (string) $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'][1]['id'];
        $this->call('POST', "/app/requests/$other/take", [], 'pharmacist');
        [$status, $body] = $this->call('POST', "/app/requests/$other/quote", ['lines' => [['product_id' => $p, 'quantity' => 7]]], 'pharmacist');
        $this->assertSame([422, 'insufficient_stock'], [$status, $body['error']['code']]);
        $this->assertSame(6, $body['error']['details']['available']);

        // Le devis expire après 24 h et libère la réservation.
        $this->clock->advance('+25 hours');
        $this->tokens = [];
        $this->assertSame('expired', $this->follow($code)['status']);
        $this->call('POST', "/app/requests/$other/take", [], 'pharmacist');
        $this->assertSame(201, $this->call('POST', "/app/requests/$other/quote", ['lines' => [['product_id' => $p, 'quantity' => 7]]], 'pharmacist')[0]);
    }

    public function testThePatientAcceptsPartOfTheQuoteAndCollectsWithACode(): void
    {
        $a = $this->product('Paracétamol 500', 1000, 10);
        $b = $this->product('Vitamine C', 2500, 5);
        [$code, $id] = $this->quoted([['product_id' => $a, 'quantity' => 2], ['product_id' => $b, 'quantity' => 1]]);
        $lines = $this->follow($code)['quote']['lines'];

        $this->assertSame(422, $this->call('POST', "/public/requests/$code/decision", ['accept' => []])[0]);
        [$status, $accepted] = $this->call('POST', "/public/requests/$code/decision", ['accept' => [$lines[0]['id']]]);
        $this->assertSame(200, $status);
        $this->assertSame('accepted', $accepted['status']);
        $this->assertSame(2000, $accepted['quote']['total']);
        $this->assertMatchesRegularExpression('/^[A-Z]\d{4}$/', $accepted['pickup_code']);
        $this->assertSame(409, $this->call('POST', "/public/requests/$code/decision", ['refuse' => true])[0], 'une seule décision');

        // Le prix du devis reste dû même si le tarif change ensuite.
        $this->call('PUT', "/app/products/$a", ['name' => 'Paracétamol 500', 'regulatory_status' => 'free', 'sale_price' => 1500, 'purchase_price' => 600, 'vat_bp' => 0], 'owner');
        $this->assertSame(204, $this->call('POST', "/app/requests/$id/ready", [], 'pharmacist')[0]);
        $this->assertSame('ready', $this->follow($code)['status']);

        $this->assertSame(404, $this->call('GET', "/app/pickups/{$accepted['pickup_code']}", null, 'other')[0]);
        [$status, $pickup] = $this->call('GET', '/app/pickups/' . strtolower($accepted['pickup_code']), null, 'cashier');
        $this->assertSame(200, $status);
        $this->assertSame([[$a, 2, 1000]], array_map(static fn ($l) => [$l['product_id'], $l['quantity'], $l['unit_price']], $pickup['lines']));

        $this->assertSame(201, $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], 'cashier')[0]);
        [$status, $sale] = $this->call('POST', '/app/sales', ['request_id' => $id, 'lines' => [['product_id' => $a, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 2000]]], 'cashier');
        $this->assertSame(201, $status, json_encode($sale));
        $this->assertSame(2000, $sale['total']);
        $done = $this->follow($code);
        $this->assertSame('picked_up', $done['status']);
        $this->assertNull($done['pickup_code']);
        $this->assertSame(404, $this->call('GET', "/app/pickups/{$accepted['pickup_code']}", null, 'cashier')[0], 'un code ne sert qu’une fois');
        $again = $this->call('POST', '/app/sales', ['request_id' => $id, 'lines' => [['product_id' => $a, 'qty' => 2]], 'payments' => [['method' => 'cash', 'amount' => 3000]]], 'cashier');
        $this->assertSame(409, $again[0]);
    }

    public function testASaleCannotBorrowQuotePricesForOtherProductsOrQuantities(): void
    {
        $a = $this->product('Paracétamol 500', 1000, 10);
        [$code, $id] = $this->quoted([['product_id' => $a, 'quantity' => 2]]);
        $line = $this->follow($code)['quote']['lines'][0]['id'];
        $this->call('POST', "/public/requests/$code/decision", ['accept' => [$line]]);
        $this->call('POST', '/app/cash/open', ['station' => 'C1', 'opening_float' => 0], 'cashier');
        [$status, $body] = $this->call('POST', '/app/sales', ['request_id' => $id, 'lines' => [['product_id' => $a, 'qty' => 3]], 'payments' => [['method' => 'cash', 'amount' => 3000]]], 'cashier');
        $this->assertSame([422, 'pickup_mismatch'], [$status, $body['error']['code']]);
    }

    public function testARefusalClosesTheRequestAndAnUncollectedOrderExpires(): void
    {
        $a = $this->product('Paracétamol 500', 1000, 2);
        [$code] = $this->quoted([['product_id' => $a, 'quantity' => 2]]);
        [$status, $refused] = $this->call('POST', "/public/requests/$code/decision", ['refuse' => true, 'reason' => 'Trop cher']);
        $this->assertSame([200, 'cancelled', null], [$status, $refused['status'], $refused['pickup_code']]);

        [$second] = $this->quoted([['product_id' => $a, 'quantity' => 2]]);
        $line = $this->follow($second)['quote']['lines'][0]['id'];
        $accepted = $this->call('POST', "/public/requests/$second/decision", ['accept' => [$line]])[1];
        $this->clock->advance('+25 hours');
        $this->tokens = [];
        $this->assertSame('expired', $this->follow($second)['status']);
        $this->assertSame(404, $this->call('GET', "/app/pickups/{$accepted['pickup_code']}", null, 'cashier')[0]);
    }

    public function testAnUnreadableDocumentCanBeReplacedByAddingAPage(): void
    {
        $code = $this->send()[1]['code'];
        $id = $this->queued();
        $this->call('POST', "/app/requests/$id/take", [], 'pharmacist');
        $this->assertSame(204, $this->call('POST', "/app/requests/$id/retake", [], 'pharmacist')[0]);
        $this->assertSame('photo_needed', $this->follow($code)['status']);
        [$status, $body] = $this->call('POST', "/public/requests/$code/pages", ['documents' => [['name' => 'p2.pdf', 'base64' => base64_encode(self::PDF)]]]);
        $this->assertSame([200, 'received'], [$status, $body['status']]);
        $this->assertSame(2, $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'][0]['pages']);
    }

    public function testTheSameDocumentSentTwiceIsSignalledToThePharmacist(): void
    {
        $first = $this->send()[1]['number'];
        $this->send();
        $items = $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items'];
        $detail = $this->call('GET', "/app/requests/{$items[1]['id']}", null, 'pharmacist')[1];
        $this->assertSame([['number' => $first, 'status' => 'received']], $detail['duplicates']);
    }

    public function testAFollowUpMessageOrUrgencyRaisesTheRequestOnce(): void
    {
        $code = $this->send()[1]['code'];
        $this->assertNull($this->call('POST', "/public/requests/$code/message", ['text' => 'Merci'])[1]['advice']);
        $this->assertSame('emergency', $this->call('POST', "/public/requests/$code/message", ['text' => 'Elle a perdu connaissance, perte de connaissance'])[1]['advice']);
        $this->assertSame(200, $this->call('POST', "/public/requests/$code/urgent", [])[0]);
        $this->assertCount(2, $this->db->all("SELECT id FROM jobs WHERE payload LIKE '%request_urgent%'"), 'une seule alerte par demande');
        $id = $this->queued();
        $this->assertSame(204, $this->call('POST', "/app/requests/$id/cancel", ['reason' => 'Doublon'], 'pharmacist')[0]);
        $this->assertSame(409, $this->call('POST', "/public/requests/$code/message", ['text' => 'Encore'])[0]);
        $this->assertSame([], $this->call('GET', '/app/requests', null, 'pharmacist')[1]['items']);
        $this->assertCount(1, $this->call('GET', '/app/requests?closed=1', null, 'pharmacist')[1]['items']);
    }

    public function testTheReaderLearnsFromThePharmacistsChoices(): void
    {
        $p = $this->product('Clamoxyl 500', 1800, 20);
        $this->quoted([['product_id' => $p, 'quantity' => 1, 'read_text' => 'Amoxicilline 500 mg', 'read_confidence' => 70]]);
        $this->quoted([
            ['product_id' => $p, 'quantity' => 1, 'read_text' => 'amoxicilline 500 MG'],
            ['product_id' => $p, 'quantity' => 1, 'read_text' => 'Clamoxyl 500'],
            ['label' => 'Introuvable', 'quantity' => 1, 'read_text' => 'Xyz'],
        ]);

        [$status, $body] = $this->call('GET', '/app/assistant/lexicon', null, 'pharmacist');
        $this->assertSame(200, $status);
        $links = array_column($body['links'], null, 'read');
        $this->assertSame(2, $links['amoxicilline 500 mg']['uses'], 'même texte, écrit autrement');
        $this->assertSame(['id' => $p, 'name' => 'Clamoxyl 500'], array_intersect_key($links['amoxicilline 500 mg']['product'], ['id' => 1, 'name' => 1]));
        $this->assertArrayNotHasKey('xyz', $links, 'rien à apprendre sans produit retenu');
        $this->assertSame(['lines' => 4, 'corrected' => 3, 'accepted_rate' => 25], $body['reliability']);

        $this->assertSame([], $this->call('GET', '/app/assistant/lexicon', null, 'other')[1]['links'], 'chaque pharmacie apprend pour elle');
        $this->assertSame(403, $this->call('GET', '/app/assistant/lexicon', null, 'cashier')[0]);
        $this->call('POST', "/app/products/$p/active", ['active' => false], 'owner');
        $this->assertSame([], $this->call('GET', '/app/assistant/lexicon', null, 'pharmacist')[1]['links'], 'produit retiré : lien oublié');
    }

    public function testThePharmacyIsWarnedByMailOfAnUrgentRequest(): void
    {
        $before = (int) $this->db->one('SELECT COUNT(*) AS n FROM jobs')['n'];
        $this->send();
        $this->assertSame($before, (int) $this->db->one('SELECT COUNT(*) AS n FROM jobs')['n']);
        $this->send(['urgent' => true]);
        $jobs = $this->db->all("SELECT payload FROM jobs WHERE payload LIKE '%request_urgent%'");
        $this->assertCount(2, $jobs, 'le titulaire et le pharmacien adjoint');
        $this->assertStringNotContainsString('690', (string) $jobs[0]['payload']);
        $this->assertNotSame('', Ulid::generate());
    }
}
