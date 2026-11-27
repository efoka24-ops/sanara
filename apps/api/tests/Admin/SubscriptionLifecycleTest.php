<?php
declare(strict_types=1);

namespace Sanara\Tests\Admin;

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

/**
 * T251, T252, T254, T255 : essai de 30 jours à la validation, délai de grâce de 7 jours, puis lecture
 * seule (consulter et exporter, plus rien modifier), retrait du site public, relances par e-mail ;
 * un paiement saisi rouvre la pharmacie pour un mois.
 */
final class SubscriptionLifecycleTest extends TestCase
{
    private const PW = 'Motdepasse#2026';
    private App $app;
    private Db $db;
    private Clock $clock;
    private Services $s;
    private string $tenant;
    /** @var array<string, string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        Seeder::platformDefaults($this->db);
        $this->clock = Clock::fixed('2026-11-01T09:00:00Z');
        $users = new UserRepository($this->db, $this->clock);
        $hash = (new PasswordHasher())->hash(self::PW);
        $users->create(['email' => 'admin@s.cm', 'role_code' => 'super_admin', 'password_hash' => $hash]);
        $this->s = new Services($this->db, $this->clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!', 'CRON_KEY' => 'k']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);

        // Une pharmacie validée par le super admin, puis activée par son titulaire.
        $this->db->insert('pharmacy_applications', $this->application());
        $this->call('POST', '/admin/applications/A1/approve', ['plan' => 'standard', 'pos_count' => 2], 'admin@s.cm');
        $this->tenant = (string) $this->db->one('SELECT tenant_id FROM pharmacy_applications WHERE id = ?', ['A1'])['tenant_id'];
        $this->db->execute("UPDATE users SET status = 'active', password_hash = ? WHERE email = ?", [$hash, 'owner@neuve.cm']);
        $this->db->execute('UPDATE tenants SET public_visible = 1 WHERE id = ?', [$this->tenant]);
        $this->s->publicProjection->refresh($this->tenant);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
    private function call(string $method, string $path, ?array $json = null, ?string $user = null): array
    {
        $r = (new ServerRequestFactory())->createServerRequest($method, $path, ['REMOTE_ADDR' => '41.0.9.' . random_int(1, 250)]);
        parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
        $r = $r->withQueryParams($query);
        if ($json !== null) {
            $r = $r->withHeader('Content-Type', 'application/json')->withBody((new StreamFactory())->createStream(json_encode($json)));
        }
        if ($user !== null) {
            $this->tokens[$user] ??= (string) $this->call('POST', '/auth/login', ['email' => $user, 'password' => self::PW])[1]['access'];
            $r = $r->withHeader('Authorization', "Bearer {$this->tokens[$user]}");
        }
        $res = $this->app->handle($r);
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function days(int $n): void
    {
        $this->clock->advance("+$n days");
        $this->tokens = [];
        $this->call('GET', '/internal/run-jobs?key=k');
    }

    private function product(string $name = 'Paracétamol'): int
    {
        return $this->call('POST', '/app/products', ['name' => $name, 'regulatory_status' => 'free', 'sale_price' => 500, 'purchase_price' => 300, 'vat_bp' => 0], 'owner@neuve.cm')[0];
    }

    /** @return list<string> */
    private function mails(string $template): array
    {
        $out = [];
        foreach ($this->db->all('SELECT payload FROM jobs') as $j) {
            $p = json_decode((string) $j['payload'], true);
            if (($p['template'] ?? '') === $template) {
                $out[] = (string) $p['to'];
            }
        }
        return $out;
    }

    public function testApprovalStartsAThirtyDayTrialOnTheChosenPlan(): void
    {
        $sub = $this->call('GET', '/app/subscription', null, 'owner@neuve.cm')[1];
        $this->assertEquals(['state' => 'trial', 'plan' => 'Standard', 'stations' => 2, 'ends_at' => '2026-12-01 09:00:00.000', 'days_left' => 30], array_intersect_key($sub, array_flip(['state', 'plan', 'stations', 'ends_at', 'days_left'])), 'ordre des clés indifférent');
        $this->assertSame(201, $this->product());
    }

    public function testAfterTheTrialComesGraceThenReadOnly(): void
    {
        $this->days(31);
        $this->assertSame('grace', $this->call('GET', '/app/subscription', null, 'owner@neuve.cm')[1]['state']);
        $this->assertSame(201, $this->product('Pendant la grâce'), 'tout fonctionne encore pendant la grâce');

        $this->days(7);
        $sub = $this->call('GET', '/app/subscription', null, 'owner@neuve.cm')[1];
        $this->assertSame('expired', $sub['state']);
        [$status, $body] = $this->call('POST', '/app/products', ['name' => 'X', 'regulatory_status' => 'free', 'sale_price' => 1, 'purchase_price' => 1, 'vat_bp' => 0], 'owner@neuve.cm');
        $this->assertSame([402, 'subscription_expired'], [$status, $body['error']['code']]);
        $this->assertSame(200, $this->call('GET', '/app/products', null, 'owner@neuve.cm')[0], 'consulter reste possible');
        $this->assertSame(200, $this->call('GET', '/app/products/export', null, 'owner@neuve.cm')[0], 'exporter aussi');
        $this->assertSame([], $this->call('GET', '/public/pharmacies')[1]['items'], 'retirée du site public');
    }

    public function testRemindersAreSentOnceBeforeAndAtExpiry(): void
    {
        $this->days(22);
        $this->assertSame([], $this->mails('subscription_reminder'));
        $this->days(1);
        $this->assertSame(['owner@neuve.cm'], $this->mails('subscription_reminder'), 'sept jours avant la fin');
        $this->days(1);
        $this->assertCount(1, $this->mails('subscription_reminder'), 'une seule fois');
        $this->days(14);
        $this->assertSame(['owner@neuve.cm'], $this->mails('subscription_expired'));
        $this->days(1);
        $this->assertCount(1, $this->mails('subscription_expired'));
    }

    public function testAPaidInvoiceReopensThePharmacyForAMonth(): void
    {
        $this->days(40);
        [, $invoice] = $this->call('POST', '/admin/invoices', ['pharmacy_id' => $this->tenant, 'amount' => 35000, 'due_days' => 7], 'admin@s.cm');
        $this->assertSame(204, $this->call('POST', "/admin/invoices/{$invoice['id']}/paid", ['method' => 'momo_mtn', 'reference' => 'MP-1'], 'admin@s.cm')[0]);
        $sub = $this->call('GET', '/app/subscription', null, 'owner@neuve.cm')[1];
        $this->assertSame(['active', '2027-01-10 09:00:00.000'], [$sub['state'], $sub['ends_at']]);
        $this->assertSame(201, $this->product('Après paiement'));
        $this->call('GET', '/internal/run-jobs?key=k');
        $this->assertCount(1, $this->call('GET', '/public/pharmacies')[1]['items'], 'de retour sur le site public');
    }

    public function testPharmaciesWithoutSubscriptionAreNotRestricted(): void
    {
        $this->db->execute('DELETE FROM subscriptions');
        $this->days(90);
        $this->assertSame('none', $this->call('GET', '/app/subscription', null, 'owner@neuve.cm')[1]['state']);
        $this->assertSame(201, $this->product('Pilote'));
    }

    /** @return array<string, mixed> */
    private function application(): array
    {
        $row = ['id' => 'A1', 'number' => 'PH-2026-0001', 'tracking_code' => 'x', 'country_code' => 'CM', 'status' => 'pending', 'pharmacy_name' => 'Pharmacie Neuve', 'license_number' => 'AG-9', 'license_date' => '2020-01-01', 'pharmacy_phone' => '+237600000009', 'pos_count' => 2, 'takes_duty' => 0, 'offers_delivery_drop' => 0, 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Plateau', 'owner_last_name' => 'Mballa', 'owner_first_name' => 'Alain', 'owner_order_number' => 'O-1', 'owner_phone' => '+237600000002', 'owner_email' => 'owner@neuve.cm', 'locale' => 'fr', 'allow_reapply' => 0];
        foreach ($this->db->all('PRAGMA table_info(pharmacy_applications)') as $c) {
            if ((int) $c['notnull'] === 1 && !isset($row[$c['name']])) {
                $row[$c['name']] = str_contains((string) $c['type'], 'INT') ? 0 : '2026-01-01 00:00:00.000';
            }
        }
        return $row;
    }
}
