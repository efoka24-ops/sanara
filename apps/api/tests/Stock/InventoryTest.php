<?php
declare(strict_types=1);

namespace Sanara\Tests\Stock;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Modules\Catalog\CatalogService;
use Sanara\Modules\Stock\InventoryService;
use Sanara\Modules\Stock\StockService;
use Sanara\Tests\Support\TestDb;

final class InventoryTest extends TestCase
{
    private Db $db;
    private Clock $clock;
    private StockService $stock;
    private InventoryService $inv;
    private TenantDb $tdb;
    private string $pid;
    private string $lot;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $ctx = new TenantContext();
        $ctx->set('T1');
        $this->tdb = new TenantDb($this->db, $ctx);
        $this->stock = new StockService($this->db, $this->tdb, $this->clock, $ctx);
        $this->inv = new InventoryService($this->db, $this->tdb, $this->clock);
        $this->pid = (new CatalogService($this->db, $this->tdb, $this->clock))->create(['name' => 'Amoxicilline', 'regulatory_status' => 'prescription_only', 'sale_price' => 2800, 'purchase_price' => 2000], 'U1');
        $this->stock->receive(['product_id' => $this->pid, 'lot_number' => 'L1', 'expiry' => '2027-05-01', 'quantity' => 10, 'unit_cost' => 2000], 'U1');
        $this->lot = (string) $this->tdb->select('lots')[0]['id'];
        $this->clock->advance('+1 hour');
    }

    public function testGapIsComputedAtCountTimeNotAtValidationTime(): void
    {
        $id = $this->inv->create('full', false, null, 'U1');
        $this->inv->count($id, 'poste1', [['lot_id' => $this->lot, 'qty' => 8]], 'U1');
        $this->clock->advance('+30 minutes');
        $this->stock->issue($this->pid, 2, 'sale', 'sale', 'S1', 'U1'); // vente pendant l'inventaire, après le comptage
        $this->inv->submit($id);
        $rep = $this->inv->validate($id, 'PH1', $this->stock);
        $this->assertSame(-2, $rep['lines'][0]['gap']);
        $this->assertSame(-4000, $rep['gap_value']);
        $this->assertSame(6, $this->stock->onHand($this->pid)['on_hand'], '10 - 2 vendus - 2 d’écart');
    }

    public function testCountsFromSeveralDevicesAddUpAndLatestPerDeviceWins(): void
    {
        $id = $this->inv->create('full', false, null, 'U1');
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 3, 'counted_at' => '2026-11-24T11:05:00Z']], 'U1');
        $this->inv->count($id, 'B', [['lot_id' => $this->lot, 'qty' => 5, 'counted_at' => '2026-11-24T11:06:00Z']], 'U1');
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 4, 'counted_at' => '2026-11-24T11:07:00Z']], 'U1');
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 99, 'counted_at' => '2026-11-24T11:00:00Z']], 'U1'); // rejoué en retard : ignoré
        $this->assertSame(9, $this->inv->lines($id)[0]['counted_qty']);
    }

    public function testBlindModeHidesExpectedQuantityUntilSubmitted(): void
    {
        $id = $this->inv->create('full', true, null, 'U1');
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 10]], 'U1');
        $this->assertNull($this->inv->lines($id)[0]['expected_qty']);
        $this->inv->submit($id);
        $this->assertSame(10, $this->inv->lines($id)[0]['expected_qty']);
    }

    public function testValidationAppliesGapsOnceAndOnlyFromPendingStatus(): void
    {
        $id = $this->inv->create('full', false, null, 'U1');
        try {
            $this->inv->validate($id, 'PH1', $this->stock);
            $this->fail('un inventaire ouvert ne se valide pas');
        } catch (ApiException $e) {
            $this->assertSame('conflict', $e->errorCode);
        }
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 12]], 'U1');
        $this->inv->submit($id);
        $this->inv->validate($id, 'PH1', $this->stock);
        $this->assertSame(12, $this->stock->onHand($this->pid)['on_hand']);
        $this->assertStringContainsString('Amoxicilline', $this->inv->reportCsv($id));
        $this->expectException(ApiException::class);
        $this->inv->validate($id, 'PH1', $this->stock);
    }

    public function testCycleInventoryNeedsAFamilyAndCountingIsClosedAfterSubmit(): void
    {
        try {
            $this->inv->create('cycle', false, null, 'U1');
            $this->fail('famille requise');
        } catch (ApiException $e) {
            $this->assertSame('validation_failed', $e->errorCode);
        }
        $id = $this->inv->create('full', false, null, 'U1');
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 10]], 'U1');
        $this->inv->submit($id);
        $this->expectException(ApiException::class);
        $this->inv->count($id, 'A', [['lot_id' => $this->lot, 'qty' => 1]], 'U1');
    }
}
