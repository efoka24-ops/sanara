<?php
declare(strict_types=1);

namespace Sanara\Tests\Tenants;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Modules\Tenants\TenantSetupService;
use Sanara\Tests\Support\TestDb;

final class TenantSetupTest extends TestCase
{
    private Db $db;
    private TenantContext $ctx;
    private TenantSetupService $svc;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        $clock = Clock::fixed('2026-11-24T10:00:00Z');
        foreach (['T1', 'T2'] as $i => $t) {
            $this->db->insert('tenants', ['id' => $t, 'country_code' => 'CM', 'name' => "P$i", 'slug' => "p$i", 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => $clock->stamp(), 'updated_at' => $clock->stamp()]);
        }
        $this->ctx = new TenantContext();
        $this->ctx->set('T1');
        $this->svc = new TenantSetupService($this->db, new TenantDb($this->db, $this->ctx), $this->ctx, $clock);
    }

    public function testSettingsRoundTripAndWhitelist(): void
    {
        $this->svc->setSettings(['ticket_footer' => 'Merci', 'vat_default_bp' => 1925, 'rounding' => 5]);
        $s = $this->svc->settings();
        $this->assertSame('Merci', $s['ticket_footer']);
        $this->assertSame(1925, $s['vat_default_bp']);
        $this->svc->setSettings(['ticket_footer' => 'Bonjour']);
        $this->assertSame('Bonjour', $this->svc->settings()['ticket_footer']);
        $this->expectException(ApiException::class);
        $this->svc->setSettings(['cle_inconnue' => 'x']);
    }

    public function testSettingsAreIsolatedPerTenant(): void
    {
        $this->svc->setSettings(['ticket_footer' => 'A']);
        $this->ctx->set('T2');
        $this->assertArrayNotHasKey('ticket_footer', $this->svc->settings());
    }

    public function testOpeningHoursValidation(): void
    {
        $days = [];
        for ($d = 1; $d <= 7; $d++) {
            $days[] = ['weekday' => $d, 'is_open' => $d < 7, 'opens' => '07:30', 'closes' => '21:00'];
        }
        $this->svc->setOpeningHours($days);
        $h = $this->svc->openingHours();
        $this->assertCount(7, $h);
        $this->assertFalse($h[6]['is_open']);
        $days[0]['closes'] = '06:00';
        $this->expectException(ApiException::class);
        $this->svc->setOpeningHours($days);
    }

    public function testOnboardingResumesAtFirstIncompleteStep(): void
    {
        $this->assertSame('hours', $this->svc->onboarding()['current']);
        $this->svc->completeStep('hours');
        $this->svc->completeStep('team');
        $o = $this->svc->onboarding();
        $this->assertSame('catalog', $o['current']);
        $this->assertSame(50, $o['percent']);
        $this->svc->skipStep('catalog');
        $this->svc->completeStep('visibility');
        $this->assertTrue($this->svc->onboarding()['finished']);
        $this->expectException(ApiException::class);
        $this->svc->completeStep('inconnue');
    }

    public function testVisibilityTogglesOnlyOwnTenant(): void
    {
        $this->svc->setVisibility(true);
        $this->assertSame(1, (int) $this->db->one("SELECT public_visible FROM tenants WHERE id = 'T1'")['public_visible']);
        $this->assertSame(0, (int) $this->db->one("SELECT public_visible FROM tenants WHERE id = 'T2'")['public_visible']);
    }
}
