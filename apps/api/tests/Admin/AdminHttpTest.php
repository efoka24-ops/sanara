<?php
declare(strict_types=1);

namespace Sanara\Tests\Admin;

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

final class AdminHttpTest extends TestCase
{
    private App $app;
    private Services $s;
    private const PW = 'Motdepasse#2026';

    protected function setUp(): void
    {
        $db = TestDb::create();
        Seeder::roles($db);
        Seeder::cameroon($db);
        Seeder::platformDefaults($db);
        $clock = new Clock();
        $users = new UserRepository($db, $clock);
        $h = (new PasswordHasher())->hash(self::PW);
        $users->create(['email' => 'admin@s.cm', 'role_code' => 'super_admin', 'password_hash' => $h]);
        $users->create(['email' => 'owner@t1.cm', 'role_code' => 'owner', 'tenant_id' => 'T1', 'password_hash' => $h]);
        $db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie du Plateau', 'slug' => 't1', 'status' => 'active', 'public_visible' => 1, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        $this->s = new Services($db, $clock, Config::fromEnv(['APP_ENV' => 'testing', 'JWT_SECRET' => 'test-secret-with-at-least-32-characters!!']), new EncryptedStorage(sys_get_temp_dir() . '/sa-' . bin2hex(random_bytes(3)), random_bytes(32), 'url-secret-0123456789-0123456789-xx'));
        $this->app = Bootstrap::createApp(sys_get_temp_dir(), $this->s);
    }

    /** @return array{0: int, 1: array<string, mixed>} */
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
        return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
    }

    private function tok(string $email): string
    {
        return $this->call('POST', '/auth/login', ['email' => $email, 'password' => self::PW])[1]['access'];
    }

    public function testOnlyTheSuperAdminReachesTheConsole(): void
    {
        $owner = $this->tok('owner@t1.cm');
        foreach (['/admin/dashboard', '/admin/pharmacies', '/admin/plans', '/admin/invoices', '/admin/reports', '/admin/templates', '/admin/audit', '/admin/settings', '/admin/countries'] as $p) {
            $this->assertSame(403, $this->call('GET', $p, null, $owner)[0], $p);
            $this->assertSame(401, $this->call('GET', $p)[0], $p);
        }
        $admin = $this->tok('admin@s.cm');
        [$st, $d] = $this->call('GET', '/admin/dashboard', null, $admin);
        $this->assertSame(200, $st);
        $this->assertSame(1, $d['active_pharmacies']);
    }

    public function testSuspensionBlocksLoginHidesFromPublicAndCanBeReverted(): void
    {
        $admin = $this->tok('admin@s.cm');
        $owner = $this->tok('owner@t1.cm');
        $this->assertSame(422, $this->call('POST', '/admin/pharmacies/T1/suspend', ['reason' => ''], $admin)[0]);
        $this->assertSame(204, $this->call('POST', '/admin/pharmacies/T1/suspend', ['reason' => 'Impayés répétés'], $admin)[0]);
        $this->assertSame('tenant_suspended', $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => self::PW])[1]['error']['code']);
        $this->assertSame(0, (int) $this->s->db->one('SELECT public_visible AS v FROM tenants WHERE id = ?', ['T1'])['v']);
        $this->assertSame(409, $this->call('POST', '/admin/pharmacies/T1/suspend', ['reason' => 'encore'], $admin)[0]);
        $this->assertSame('suspended', $this->call('GET', '/admin/pharmacies/T1', null, $admin)[1]['status']);
        $this->assertSame(1, $this->call('GET', '/admin/dashboard', null, $admin)[1]['suspended_pharmacies']);
        $this->assertSame(204, $this->call('POST', '/admin/pharmacies/T1/reactivate', [], $admin)[0]);
        $this->assertSame(200, $this->call('POST', '/auth/login', ['email' => 'owner@t1.cm', 'password' => self::PW])[0]);
        $this->assertNotSame('', $owner);
    }

    public function testPlansSubscriptionsAndManualInvoicing(): void
    {
        $admin = $this->tok('admin@s.cm');
        $plans = $this->call('GET', '/admin/plans', null, $admin)[1]['items'];
        $this->assertCount(3, $plans);
        $std = array_values(array_filter($plans, fn ($p) => $p['code'] === 'standard'))[0];
        [$st, $new] = $this->call('POST', '/admin/plans', ['code' => 'reseau', 'name' => 'Réseau', 'price_month' => 150000, 'max_stations' => 30, 'features' => 'Multi-sites'], $admin);
        $this->assertSame(201, $st);
        $this->assertSame(409, $this->call('POST', '/admin/plans', ['code' => 'reseau', 'name' => 'Bis', 'price_month' => 1, 'max_stations' => 1], $admin)[0]);
        $this->assertSame(204, $this->call('PUT', "/admin/plans/{$new['id']}", ['name' => 'Réseau+', 'price_month' => 160000, 'max_stations' => 40, 'features' => 'x'], $admin)[0]);

        $this->assertSame(422, $this->call('PUT', '/admin/pharmacies/T1/subscription', ['plan_id' => $std['id'], 'stations' => 9, 'status' => 'trial'], $admin)[0], 'plus de postes que la formule');
        $this->assertSame(204, $this->call('PUT', '/admin/pharmacies/T1/subscription', ['plan_id' => $std['id'], 'stations' => 2, 'status' => 'active'], $admin)[0]);
        $this->assertSame(35000, $this->call('GET', '/admin/dashboard', null, $admin)[1]['mrr']);

        [$st, $inv] = $this->call('POST', '/admin/invoices', ['pharmacy_id' => 'T1', 'amount' => 35000, 'due_days' => 0], $admin);
        $this->assertSame(201, $st);
        $this->assertSame('issued', $this->call('GET', '/admin/invoices', null, $admin)[1]['items'][0]['status'] === 'overdue' ? 'issued' : 'x');
        $this->assertSame(422, $this->call('POST', "/admin/invoices/{$inv['id']}/paid", ['method' => 'momo_mtn', 'reference' => ''], $admin)[0]);
        $this->assertSame(204, $this->call('POST', "/admin/invoices/{$inv['id']}/paid", ['method' => 'momo_mtn', 'reference' => 'MP240001'], $admin)[0]);
        $this->assertSame(409, $this->call('POST', "/admin/invoices/{$inv['id']}/paid", ['method' => 'momo_mtn', 'reference' => 'MP240001'], $admin)[0]);
        $this->assertSame('paid', $this->call('GET', '/admin/invoices?status=paid', null, $admin)[1]['items'][0]['status']);
        $this->assertSame(1, count($this->call('GET', '/admin/pharmacies/T1', null, $admin)[1]['invoices']));
    }

    public function testReportsAreModeratedOnce(): void
    {
        $admin = $this->tok('admin@s.cm');
        [$st, $r] = $this->call('POST', '/admin/reports', ['kind' => 'false_urgency', 'pharmacy_id' => 'T1', 'subject' => 'Urgences répétées du 690 00 00 00', 'details' => '5 en une heure'], $admin);
        $this->assertSame(201, $st);
        $this->assertSame(1, $this->call('GET', '/admin/dashboard', null, $admin)[1]['open_reports']);
        $this->assertSame(409, $this->call('POST', "/admin/reports/{$r['id']}/handle", ['decision' => 'inconnu'], $admin)[0]);
        $this->assertSame(204, $this->call('POST', "/admin/reports/{$r['id']}/handle", ['decision' => 'dismissed', 'note' => 'Classé sans suite'], $admin)[0]);
        $this->assertSame(409, $this->call('POST', "/admin/reports/{$r['id']}/handle", ['decision' => 'actioned'], $admin)[0]);
        $this->assertSame('dismissed', $this->call('GET', '/admin/reports?status=dismissed', null, $admin)[1]['items'][0]['status']);
    }

    public function testEmailTemplateOverrideIsUsedByTheMailerAndOnlyKnownVariablesAreAllowed(): void
    {
        $admin = $this->tok('admin@s.cm');
        $list = $this->call('GET', '/admin/templates', null, $admin)[1]['items'];
        $this->assertCount(18, $list, '9 modèles, en français et en anglais');
        $this->assertSame(422, $this->call('PUT', '/admin/templates/access_granted/fr', ['subject' => 'x', 'body' => 'Bonjour {{inconnue}}'], $admin)[0]);
        $this->assertSame(204, $this->call('PUT', '/admin/templates/access_granted/fr', ['subject' => 'Bienvenue {{pharmacie}}', 'body' => "Bonjour {{responsable}}, activez : {{lien_activation}} ({{duree_lien}}) pour {{pharmacie}}"], $admin)[0]);
        $r = $this->s->templates->render('access_granted', 'fr', ['responsable' => 'Alain', 'pharmacie' => 'Plateau', 'duree_lien' => '48 h', 'lien_activation' => 'https://x']);
        $this->assertSame('Bienvenue Plateau', $r['subject']);
        $this->assertStringContainsString('activez : https://x', $r['text']);
        [, $pv] = $this->call('POST', '/admin/templates/access_granted/fr/preview', ['body' => 'Salut {{responsable}}'], $admin);
        $this->assertSame('Salut [responsable]', $pv['text']);
        $this->assertTrue(array_values(array_filter($this->call('GET', '/admin/templates', null, $admin)[1]['items'], fn ($t) => $t['code'] === 'access_granted' && $t['locale'] === 'fr'))[0]['customized']);
        $this->assertSame(204, $this->call('DELETE', '/admin/templates/access_granted/fr', null, $admin)[0]);
        $this->assertSame('Votre accès Sanara est prêt', $this->s->templates->render('access_granted', 'fr', ['responsable' => 'A', 'pharmacie' => 'P', 'duree_lien' => '1', 'lien_activation' => 'x'])['subject']);
    }

    public function testSettingsCountriesAndAuditTrail(): void
    {
        $admin = $this->tok('admin@s.cm');
        $this->assertSame('48', $this->call('GET', '/admin/settings', null, $admin)[1]['settings']['link_validity_hours']);
        $this->assertSame(422, $this->call('PUT', '/admin/settings', ['settings' => ['request_quota_per_day' => 0]], $admin)[0]);
        $this->assertSame(422, $this->call('PUT', '/admin/settings', ['settings' => ['inconnu' => 1]], $admin)[0]);
        $this->assertSame(204, $this->call('PUT', '/admin/settings', ['settings' => ['request_quota_per_day' => 8, 'maintenance' => true, 'desktop_version' => '1.2.0']], $admin)[0]);
        $st = $this->call('GET', '/admin/settings', null, $admin)[1]['settings'];
        $this->assertSame(['8', '1', '1.2.0'], [$st['request_quota_per_day'], $st['maintenance'], $st['desktop_version']]);

        $cm = $this->call('GET', '/admin/countries', null, $admin)[1]['items'][0];
        $this->assertSame('CM', $cm['code']);
        $this->assertSame(1, $cm['pharmacies']);
        $this->assertSame(10, $cm['retention_years']);
        $this->assertSame(204, $this->call('PUT', '/admin/countries/CM', ['status' => 'preparing', 'retention_years' => 12, 'required_documents' => ['Agrément', 'Diplôme']], $admin)[0]);
        $cm = $this->call('GET', '/admin/countries', null, $admin)[1]['items'][0];
        $this->assertSame(['preparing', 12, ['Agrément', 'Diplôme']], [$cm['status'], $cm['retention_years'], $cm['required_documents']]);
        $this->assertSame(422, $this->call('PUT', '/admin/countries/CM', ['status' => 'ouvert'], $admin)[0]);

        $items = $this->call('GET', '/admin/audit?action=country', null, $admin)[1]['items'];
        $this->assertSame('country.update', $items[0]['action']);
        $this->assertSame('admin@s.cm', $items[0]['user_email']);
        $this->assertSame([], $this->call('GET', '/admin/audit?action=zzz', null, $admin)[1]['items']);
    }
}
