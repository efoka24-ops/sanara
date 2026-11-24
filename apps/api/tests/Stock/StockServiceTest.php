<?php
declare(strict_types=1);

namespace Sanara\Tests\Stock;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Catalog\CatalogService;
use Sanara\Modules\Stock\StockService;
use Sanara\Tests\Support\TestDb;

final class StockServiceTest extends TestCase
{
    private Db $db;
    private StockService $stock;
    private TenantDb $tdb;
    private string $pid;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        $clock = Clock::fixed('2026-11-24T10:00:00Z');
        $ctx = new TenantContext();
        $ctx->set('T1');
        $this->tdb = new TenantDb($this->db, $ctx);
        $this->stock = new StockService($this->db, $this->tdb, $clock, $ctx);
        $this->db->insert('tenants', ['id' => 'T1', 'country_code' => 'CM', 'name' => 'Pharmacie du Plateau', 'slug' => 't1', 'status' => 'active', 'public_visible' => 0, 'currency' => 'XAF', 'autonomy_level' => 1, 'created_at' => '2026-01-01 00:00:00.000', 'updated_at' => '2026-01-01 00:00:00.000']);
        $this->pid = (new CatalogService($this->db, $this->tdb, $clock))->create(['name' => 'Paracétamol 500', 'regulatory_status' => 'free', 'sale_price' => 500, 'purchase_price' => 300, 'min_stock' => 10], 'U1');
    }

    private function receive(string $lot, string $expiry, int $qty): void
    {
        $this->stock->receive(['product_id' => $this->pid, 'lot_number' => $lot, 'expiry' => $expiry, 'quantity' => $qty, 'unit_cost' => 300], 'U1');
    }

    /** @return array<string, string> lot_number => id */
    private function lotIds(): array
    {
        return array_column($this->tdb->select('lots'), 'id', 'lot_number');
    }

    public function testFefoTakesTheEarliestExpiryFirstAcrossLots(): void
    {
        $this->receive('B', '2027-06-01', 10);
        $this->receive('A', '2027-01-15', 5);
        $ids = $this->lotIds();
        $plan = $this->stock->plan($this->pid, 8);
        $this->assertSame([['lot_id' => $ids['A'], 'qty' => 5], ['lot_id' => $ids['B'], 'qty' => 3]], $plan);
    }

    public function testExpiredAndQuarantinedLotsAreNeverPlanned(): void
    {
        $this->receive('OLD', '2026-10-01', 50);
        $this->receive('Q', '2027-01-01', 50);
        $this->receive('OK', '2027-03-01', 4);
        $this->stock->quarantine($this->lotIds()['Q'], 'rappel fabricant', 'U1');
        $this->assertSame(4, $this->stock->availableQty($this->pid));
        $this->expectException(ApiException::class);
        $this->stock->plan($this->pid, 5);
    }

    public function testInsufficientStockIsRefusedUnlessNegativeStockIsAllowed(): void
    {
        $this->receive('A', '2027-01-01', 3);
        try {
            $this->stock->plan($this->pid, 5);
            $this->fail('stock insuffisant attendu');
        } catch (ApiException $e) {
            $this->assertSame('insufficient_stock', $e->errorCode);
        }
        $this->tdb->insert('tenant_settings', ['id' => Ulid::generate(), 'setting_key' => 'allow_negative_stock', 'setting_value' => 'true', 'updated_at' => '2026-11-24 10:00:00.000']);
        $plan = $this->stock->plan($this->pid, 5);
        $this->assertSame([3, 2], array_column($plan, 'qty'));
        $this->assertNull($plan[1]['lot_id']);
    }

    public function testIssueWritesNegativeMovementsAndStockIsTheSumOfMovements(): void
    {
        $this->receive('A', '2027-01-01', 10);
        $this->stock->issue($this->pid, 4, 'sale', 'sale', 'S1', 'U1');
        $this->assertSame(6, $this->stock->onHand($this->pid)['on_hand']);
        $this->assertSame(2, (int) $this->db->one("SELECT COUNT(*) n FROM stock_movements WHERE tenant_id='T1'")['n']);
    }

    public function testReversalIsAnInverseEntryAndCannotBeDoneTwice(): void
    {
        $this->receive('A', '2027-01-01', 10);
        $out = $this->stock->issue($this->pid, 4, 'sale', 'sale', 'S1', 'U1');
        $rev = $this->stock->reverse($out[0]['movement_id'], 'Vente annulée', 'U1');
        $this->assertSame(10, $this->stock->onHand($this->pid)['on_hand']);
        $orig = $this->tdb->select('stock_movements', ['id' => $out[0]['movement_id']])[0];
        $this->assertSame(-4, (int) $orig['qty'], 'le mouvement d’origine n’est pas modifié');
        $this->assertSame($out[0]['movement_id'], $this->tdb->select('stock_movements', ['id' => $rev])[0]['reversal_of']);
        $this->expectException(ApiException::class);
        $this->stock->reverse($out[0]['movement_id'], 'encore', 'U1');
    }

    public function testAdjustmentNeedsAReasonAndCannotDriveALotNegative(): void
    {
        $this->receive('A', '2027-01-01', 3);
        $lot = $this->lotIds()['A'];
        $this->stock->adjust($this->pid, $lot, -2, 'Casse', 'U1', 'breakage');
        $this->assertSame(1, $this->stock->onHand($this->pid)['on_hand']);
        foreach ([[-5, 'Perte'], [-1, '']] as [$d, $r]) {
            try {
                $this->stock->adjust($this->pid, $lot, $d, $r, 'U1');
                $this->fail('refus attendu');
            } catch (ApiException) {
                $this->assertTrue(true);
            }
        }
    }

    public function testDestroyRequiresQuarantineOrExpiryAndProducesAMinutes(): void
    {
        $this->receive('A', '2027-01-01', 6);
        $lot = $this->lotIds()['A'];
        try {
            $this->stock->destroy($lot, 6, 'Rappel', null, null, 'U1');
            $this->fail('un lot sain ne se détruit pas');
        } catch (ApiException $e) {
            $this->assertSame('conflict', $e->errorCode);
        }
        $this->stock->quarantine($lot, 'Rappel de lot', 'U1');
        $id = $this->stock->destroy($lot, 6, 'Rappel de lot', 'Incinération', 'M. Nkoulou', 'U1');
        $pv = $this->stock->destruction($id);
        $this->assertSame('D-2026-0001', $pv['number']);
        $this->assertSame(1800, $pv['value']);
        $this->assertSame('Pharmacie du Plateau', $pv['pharmacy']);
        $this->assertSame('destroyed', $this->tdb->select('lots', ['id' => $lot])[0]['status']);
        $this->assertSame(0, $this->stock->onHand($this->pid)['on_hand']);
    }

    public function testDailyChecksRaiseExpiryAndStockAlertsOnceAndBlockExpiredLots(): void
    {
        $this->receive('SOON', '2026-12-10', 20);
        $this->receive('LATE', '2027-02-01', 20);
        $this->receive('GONE', '2026-11-01', 5);
        $first = $this->stock->runDailyChecks();
        $this->assertSame(1, $first['expired_blocked']);
        $this->assertSame(['expired', 'expiry_30', 'expiry_90'], $this->kinds());
        $again = $this->stock->runDailyChecks();
        $this->assertSame(0, $again['expiry_alerts'] + $again['stock_alerts'] + $again['expired_blocked'], 'pas de doublon d’alerte');
        $this->assertSame('expired', $this->tdb->select('lots', ['id' => $this->lotIds()['GONE']])[0]['status']);
    }

    /** @return list<string> */
    private function kinds(): array
    {
        $k = array_column($this->stock->alerts(), 'kind');
        sort($k);
        return $k;
    }

    public function testOutOfStockAndLowStockAlertsAndOverview(): void
    {
        $this->receive('A', '2027-06-01', 8);
        $this->stock->runDailyChecks();
        $this->assertContains('low', $this->kinds());
        $o = $this->stock->overview()[0];
        $this->assertSame('low', $o['state']);
        $this->assertSame('2027-06-01', $o['next_expiry']);
        $this->assertTrue($this->stock->shortDated('2027-01-30'));
        $this->assertFalse($this->stock->shortDated('2027-06-01'));
    }
}
