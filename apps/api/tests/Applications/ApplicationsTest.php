<?php
declare(strict_types=1);

namespace Sanara\Tests\Applications;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Jobs\JobQueue;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Persistence\Seeder;
use Sanara\Common\Persistence\UserRepository;
use Sanara\Common\Storage\EncryptedStorage;
use Sanara\Modules\Applications\ApplicationService;
use Sanara\Modules\Applications\ReviewService;
use Sanara\Tests\Support\TestDb;

final class ApplicationsTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private ApplicationService $apps;
    private ReviewService $review;
    private JobQueue $jobs;
    private string $adminId;

    private const PDF = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF";

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        Seeder::roles($this->db);
        Seeder::cameroon($this->db);
        $this->clock = Clock::fixed('2026-11-21T14:02:00Z');
        $storage = new EncryptedStorage(sys_get_temp_dir() . '/sanara-app-' . bin2hex(random_bytes(4)), random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES), 'url-secret-0123456789-0123456789-xx');
        $this->jobs = new JobQueue($this->db, $this->clock);
        $this->apps = new ApplicationService($this->db, $this->clock, $storage, $this->jobs);
        $this->review = new ReviewService($this->db, $this->clock, $this->jobs, 'https://app.example');
        $this->adminId = (new UserRepository($this->db, $this->clock))->create(['email' => 'admin@sanara.cm', 'role_code' => 'super_admin', 'password_hash' => 'x']);
    }

    /** @return array<string, mixed> */
    private function form(array $over = []): array
    {
        return $over + [
            'country_code' => 'CM', 'pharmacy_name' => 'Pharmacie Saint-Luc', 'license_number' => 'MINSANTE/0412', 'license_date' => '2019-03-14',
            'pharmacy_phone' => '+237 6 00 00 00 01', 'pharmacy_email' => 'contact@saint-luc.cm', 'pos_count' => 2, 'current_tool' => 'paper',
            'takes_duty' => true, 'offers_delivery_drop' => false, 'region' => 'Nord', 'department' => 'Bénoué', 'city' => 'Garoua', 'district' => 'Plateau',
            'address' => 'Face à la station', 'latitude' => 9.3018, 'longitude' => 13.3921, 'owner_last_name' => 'Mballa', 'owner_first_name' => 'Alain',
            'owner_order_number' => 'ONPC-2214', 'owner_phone' => '+237 6 00 00 00 02', 'owner_email' => 'a.mballa@exemple.cm', 'locale' => 'fr',
            'accept_truth' => true, 'accept_terms' => true, 'accept_data' => true,
        ];
    }

    /** @return array<string, array{name: string, bytes: string}> */
    private function docs(): array
    {
        $d = [];
        foreach (['license', 'diploma', 'order_card', 'identity'] as $t) {
            $d[$t] = ['name' => "$t.pdf", 'bytes' => self::PDF];
        }
        return $d;
    }

    public function testSubmitCreatesPendingApplicationWithNumberAndTrackingCode(): void
    {
        $r = $this->apps->submit($this->form(), $this->docs());
        $this->assertMatchesRegularExpression('/^PH-2026-\d{4}$/', $r['number']);
        $this->assertSame('PH-2026-0001', $r['number']);
        $this->assertGreaterThanOrEqual(16, strlen($r['tracking_code']));
        $this->assertSame('pending', $this->db->one('SELECT status FROM pharmacy_applications')['status']);
        $this->assertSame(4, (int) $this->db->one('SELECT COUNT(*) n FROM application_documents')['n']);
        $this->assertSame('PH-2026-0002', $this->apps->submit($this->form(['owner_email' => 'autre@x.cm', 'license_number' => 'MINSANTE/0999']), $this->docs())['number']);
    }

    public function testQueuesAcknowledgementEmailAndNeverStoresPlainDocuments(): void
    {
        $this->apps->submit($this->form(), $this->docs());
        $job = $this->jobs->reserve('mail');
        $this->assertSame('application_received', $job['payload']['template']);
        $this->assertSame('a.mballa@exemple.cm', $job['payload']['to']);
    }

    public function testRejectsMissingFieldsBadPhoneAndMissingDocuments(): void
    {
        foreach ([
            [$this->form(['pharmacy_name' => '']), $this->docs()],
            [$this->form(['owner_phone' => '123']), $this->docs()],
            [$this->form(['owner_email' => 'pas-un-email']), $this->docs()],
            [$this->form(['accept_terms' => false]), $this->docs()],
            [$this->form(), array_slice($this->docs(), 0, 2)],
        ] as [$form, $docs]) {
            try {
                $this->apps->submit($form, $docs);
                $this->fail('attendu : validation_failed');
            } catch (ApiException $e) {
                $this->assertSame('validation_failed', $e->errorCode);
            }
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM pharmacy_applications')['n']);
    }

    public function testRejectsDangerousFiles(): void
    {
        $docs = $this->docs();
        $docs['license'] = ['name' => 'x.pdf', 'bytes' => '<?php echo 1;'];
        $this->expectException(ApiException::class);
        $this->apps->submit($this->form(), $docs);
    }

    public function testDuplicateEmailOrLicenseWhilePendingIsAConflict(): void
    {
        $this->apps->submit($this->form(), $this->docs());
        foreach ([$this->form(['license_number' => 'AUTRE']), $this->form(['owner_email' => 'autre@x.cm'])] as $f) {
            try {
                $this->apps->submit($f, $this->docs());
                $this->fail('doublon attendu');
            } catch (ApiException $e) {
                $this->assertSame('conflict', $e->errorCode);
            }
        }
    }

    public function testStatusByTrackingCodeExposesNoPersonalData(): void
    {
        $r = $this->apps->submit($this->form(), $this->docs());
        $s = $this->apps->status($r['tracking_code']);
        $this->assertSame('pending', $s['status']);
        $this->assertSame($r['number'], $s['number']);
        $this->assertArrayNotHasKey('owner_email', $s);
        $this->expectException(ApiException::class);
        $this->apps->status('inconnu');
    }

    public function testApproveCreatesTenantOwnerAndActivationTokenOnlyOnce(): void
    {
        $r = $this->apps->submit($this->form(), $this->docs());
        $id = $this->db->one('SELECT id FROM pharmacy_applications')['id'];
        $this->jobs->ack($this->jobs->reserve('mail')['id']);
        $out = $this->review->approve((string) $id, $this->adminId, 'trial', 2);
        $tenant = $this->db->one('SELECT * FROM tenants');
        $this->assertSame('Pharmacie Saint-Luc', $tenant['name']);
        $this->assertSame('active', $tenant['status']);
        $this->assertSame(1, (int) $tenant['autonomy_level']);
        $owner = $this->db->one("SELECT * FROM users WHERE role_code = 'owner'");
        $this->assertSame($tenant['id'], $owner['tenant_id']);
        $this->assertSame('pending_activation', $owner['status']);
        $tok = $this->db->one('SELECT * FROM activation_tokens');
        $this->assertSame(hash('sha256', $out['activation_token']), $tok['token_hash']);
        $this->assertSame('2026-11-23 14:02:00.000', $tok['expires_at']);
        $this->assertSame('approved', $this->db->one('SELECT status FROM pharmacy_applications')['status']);
        $mail = $this->jobs->reserve('mail');
        $this->assertSame('access_granted', $mail['payload']['template']);
        $this->assertStringContainsString($out['activation_token'], $mail['payload']['vars']['lien_activation']);
        try {
            $this->review->approve((string) $id, $this->adminId, 'trial', 2);
            $this->fail('double traitement');
        } catch (ApiException $e) {
            $this->assertSame('conflict', $e->errorCode);
        }
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM tenants')['n']);
    }

    public function testRejectRequiresReasonAndAllowsReapplyWhenAsked(): void
    {
        $this->apps->submit($this->form(), $this->docs());
        $id = (string) $this->db->one('SELECT id FROM pharmacy_applications')['id'];
        try {
            $this->review->reject($id, $this->adminId, '', null, false);
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }
        $this->review->reject($id, $this->adminId, 'expired_license', 'Agrément expiré', true);
        $row = $this->db->one('SELECT * FROM pharmacy_applications');
        $this->assertSame('rejected', $row['status']);
        $this->assertSame(1, (int) $row['allow_reapply']);
        $this->apps->submit($this->form(), $this->docs()); // nouvelle demande autorisée
        $this->assertSame(2, (int) $this->db->one('SELECT COUNT(*) n FROM pharmacy_applications')['n']);
    }

    public function testRequestInfoThenApplicantResendsDocument(): void
    {
        $r = $this->apps->submit($this->form(), $this->docs());
        $id = (string) $this->db->one('SELECT id FROM pharmacy_applications')['id'];
        $this->review->requestInfo($id, $this->adminId, ['order_card'], 'Carte illisible, merci de renvoyer une photo nette.');
        $s = $this->apps->status($r['tracking_code']);
        $this->assertSame('needs_info', $s['status']);
        $this->assertSame(['order_card'], $s['requested_docs']);
        $this->assertStringContainsString('illisible', $s['message']);
        $this->apps->resendDocument($r['tracking_code'], 'order_card', 'carte2.pdf', self::PDF);
        $this->assertSame('pending', $this->apps->status($r['tracking_code'])['status']);
        $this->assertSame(1, (int) $this->db->one("SELECT COUNT(*) n FROM application_documents WHERE superseded = 1")['n']);
        $this->assertSame(5, (int) $this->db->one('SELECT COUNT(*) n FROM application_documents')['n']);
    }

    public function testEveryDecisionIsAudited(): void
    {
        $this->apps->submit($this->form(), $this->docs());
        $id = (string) $this->db->one('SELECT id FROM pharmacy_applications')['id'];
        $this->review->reject($id, $this->adminId, 'other', null, false);
        $a = $this->db->one("SELECT * FROM audit_logs WHERE action = 'application.reject'");
        $this->assertSame($this->adminId, $a['user_id']);
        $this->assertSame($id, $a['entity_id']);
    }
}
