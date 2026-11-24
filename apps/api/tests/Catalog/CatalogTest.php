<?php
declare(strict_types=1);

namespace Sanara\Tests\Catalog;

use PHPUnit\Framework\TestCase;
use Sanara\Common\Clock;
use Sanara\Common\Errors\ApiException;
use Sanara\Common\Persistence\Db;
use Sanara\Common\Tenancy\TenantContext;
use Sanara\Common\Tenancy\TenantDb;
use Sanara\Common\Ulid;
use Sanara\Modules\Catalog\CatalogService;
use Sanara\Modules\Catalog\CatalogTransfer;
use Sanara\Modules\Catalog\ProductPolicy;
use Sanara\Modules\Catalog\ReferenceCatalog;
use Sanara\Tests\Support\TestDb;

final class CatalogTest extends TestCase
{
    private Db $db;
    private TenantContext $ctx;
    private CatalogService $cat;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->db = TestDb::create();
        $this->clock = Clock::fixed('2026-11-24T10:00:00Z');
        $this->ctx = new TenantContext();
        $this->ctx->set('T1');
        $this->cat = new CatalogService($this->db, new TenantDb($this->db, $this->ctx), $this->clock);
    }

    /** @return array<string, mixed> */
    private function p(array $o = []): array
    {
        return $o + ['name' => 'Amoxicilline 500 mg', 'dci' => 'Amoxicilline', 'form' => 'Gélule', 'strength' => '500 mg', 'pack' => 'B/12', 'regulatory_status' => 'prescription_only',
            'vat_bp' => 0, 'purchase_price' => 1950, 'sale_price' => 2800, 'min_stock' => 20, 'barcodes' => ['6000000000012']];
    }

    public function testCreateRequiresRegulatoryStatusAndValidPrices(): void
    {
        foreach ([['regulatory_status' => ''], ['regulatory_status' => 'inconnu'], ['name' => ''], ['sale_price' => -1], ['sale_price' => 12.5], ['vat_bp' => -5]] as $bad) {
            try {
                $this->cat->create($this->p($bad), 'U1');
                $this->fail('validation attendue pour ' . json_encode($bad));
            } catch (ApiException $e) {
                $this->assertSame('validation_failed', $e->errorCode);
            }
        }
        $this->assertSame(0, (int) $this->db->one('SELECT COUNT(*) n FROM products')['n']);
    }

    public function testCreateStoresProductBarcodesAndFirstPriceHistory(): void
    {
        $id = $this->cat->create($this->p(['barcodes' => ['111', '222']]), 'U1');
        $prod = $this->cat->get($id);
        $this->assertSame(['111', '222'], $prod['barcodes']);
        $this->assertSame(2800, $prod['sale_price']);
        $this->assertCount(2, $prod['price_history']); // achat et vente initiaux
    }

    public function testPriceChangeIsRecordedWithOldAndNew(): void
    {
        $id = $this->cat->create($this->p(), 'U1');
        $this->cat->update($id, ['sale_price' => 3000, 'price_reason' => 'Hausse fournisseur'], 'U1');
        $h = array_values(array_filter($this->cat->get($id)['price_history'], static fn ($x) => $x['kind'] === 'sale' && $x['old_price'] !== null));
        $this->assertSame([2800, 3000, 'Hausse fournisseur'], [$h[0]['old_price'], $h[0]['new_price'], $h[0]['reason']]);
        $this->cat->update($id, ['min_stock' => 10], 'U1');
        $this->assertCount(3, $this->cat->get($id)['price_history'], 'pas d\'entrée sans changement de prix');
    }

    public function testBarcodeIsUniquePerTenantButReusableAcrossTenants(): void
    {
        $this->cat->create($this->p(['barcodes' => ['999']]), 'U1');
        try {
            $this->cat->create($this->p(['name' => 'Autre', 'barcodes' => ['999']]), 'U1');
            $this->fail();
        } catch (ApiException $e) {
            $this->assertSame('conflict', $e->errorCode);
        }
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM products')['n'], 'création annulée en entier');
        $this->ctx->set('T2');
        $this->assertNotEmpty($this->cat->create($this->p(['barcodes' => ['999']]), 'U2'));
    }

    public function testDeactivateNeverDeletesAndReactivates(): void
    {
        $id = $this->cat->create($this->p(), 'U1');
        $this->cat->setActive($id, false, 'U1');
        $this->assertSame(1, (int) $this->db->one('SELECT COUNT(*) n FROM products')['n']);
        $this->assertSame([], $this->cat->search('Amox')['items'], 'les produits inactifs sont masqués par défaut');
        $this->assertCount(1, $this->cat->search('Amox', ['active' => 'all'])['items']);
        $this->cat->setActive($id, true, 'U1');
        $this->assertCount(1, $this->cat->search('Amox')['items']);
        $this->assertFalse(method_exists($this->cat, 'delete'));
    }

    public function testSearchByNameDciAndBarcodeAndPrefixFirst(): void
    {
        $a = $this->cat->create($this->p(['barcodes' => ['6000000000012']]), 'U1');
        $this->cat->create($this->p(['name' => 'Paracétamol 1 g', 'dci' => 'Paracétamol', 'barcodes' => ['6000000000029'], 'regulatory_status' => 'free']), 'U1');
        $this->cat->create($this->p(['name' => 'Clamoxyl', 'dci' => 'Amoxicilline', 'barcodes' => []]), 'U1');
        $this->assertSame($a, $this->cat->search('6000000000012')['items'][0]['id']);
        $names = array_column($this->cat->search('amox')['items'], 'name');
        $this->assertSame('Amoxicilline 500 mg', $names[0], 'préfixe du nom avant correspondance sur la DCI');
        $this->assertCount(2, $names);
        $this->assertCount(1, $this->cat->search('parac')['items']);
        $this->assertSame([], $this->cat->search('zzz')['items']);
    }

    public function testSearchEscapesWildcards(): void
    {
        $this->cat->create($this->p(['name' => 'Produit 100% naturel', 'barcodes' => []]), 'U1');
        $this->cat->create($this->p(['name' => 'Autre produit', 'barcodes' => []]), 'U1');
        $this->assertCount(1, $this->cat->search('100%')['items']);
        $this->assertCount(1, $this->cat->search('%')['items'], 'un % saisi est littéral, pas un joker');
    }

    public function testSearchStaysUnder200msOn20000Products(): void
    {
        $now = $this->clock->stamp();
        $this->db->transaction(function () use ($now): void {
            for ($i = 0; $i < 20000; $i++) {
                $this->db->insert('products', ['id' => Ulid::generate(), 'tenant_id' => 'T1', 'name' => 'Produit ' . $i . ' ' . dechex($i * 7919), 'dci' => 'DCI' . ($i % 500), 'is_generic' => 0, 'regulatory_status' => 'free',
                    'vat_bp' => 0, 'purchase_price' => 100, 'sale_price' => 150, 'min_stock' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
        });
        foreach (['Produit 1999', 'dci42', 'zzzznotfound'] as $q) {
            $t = microtime(true);
            $this->cat->search($q);
            $this->assertLessThan(0.2, microtime(true) - $t, "recherche « $q »");
        }
    }

    public function testEquivalentsShareAGenericGroup(): void
    {
        $g = $this->cat->createGroup('Amoxicilline 500 mg gélule');
        $a = $this->cat->create($this->p(['generic_group_id' => $g, 'barcodes' => []]), 'U1');
        $b = $this->cat->create($this->p(['name' => 'Clamoxyl 500', 'generic_group_id' => $g, 'barcodes' => []]), 'U1');
        $this->assertSame([$b], array_column($this->cat->get($a)['equivalents'], 'id'));
    }

    public function testFamiliesAreUniquePerTenant(): void
    {
        $this->cat->createFamily('Antibiotiques');
        $this->expectException(ApiException::class);
        $this->cat->createFamily('Antibiotiques');
    }

    public function testNarcoticsAndListedProductsAreNeverAutoQuotable(): void
    {
        foreach (['narcotic', 'list_i', 'list_ii'] as $s) {
            $this->assertFalse(ProductPolicy::autoQuoteAllowed($s), $s);
        }
        foreach (['free', 'prescription_only'] as $s) {
            $this->assertTrue(ProductPolicy::autoQuoteAllowed($s), $s);
        }
    }

    public function testCsvImportReportsCreatedUpdatedRejectedPerLine(): void
    {
        $this->cat->create($this->p(['barcodes' => ['6000000000012']]), 'U1');
        $csv = "name;dci;form;strength;pack;lab;family;status;vat;purchase;sale;min;barcode\n"
            . "Amoxicilline 500 mg;Amoxicilline;Gélule;500 mg;B/12;;Antibiotiques;prescription_only;0;2000;3000;20;6000000000012\n"   // maj par code-barres
            . "Doliprane 1 g;Paracétamol;Comprimé;1 g;B/8;Sanofi;Antalgiques;free;0;500;800;60;3400000000001\n"                       // création
            . ";;;;;;;free;0;1;2;0;\n"                                                                                                  // sans nom
            . "Morphine 10 mg;Morphine;Ampoule;10 mg;B/10;;Stupéfiants;narcotic;0;900;1500;5;3400000000002\n"
            . "Mauvais prix;X;;;;;;free;0;abc;100;0;3400000000003\n";
        $r = (new CatalogTransfer($this->cat))->import($csv, 'U1');
        $this->assertSame(1, $r['updated'], json_encode($r));
        $this->assertCount(2, $r['rejected']);
        $this->assertSame(2, $r['created']);
        $this->assertSame([4, 6], array_column($r['rejected'], 'line'));
        $this->assertSame(3000, $this->cat->search('6000000000012')['items'][0]['sale_price']);
    }

    public function testCsvExportRoundTrips(): void
    {
        $this->cat->create($this->p(), 'U1');
        $csv = (new CatalogTransfer($this->cat))->export();
        $this->assertStringContainsString('Amoxicilline 500 mg', $csv);
        $this->ctx->set('T2');
        $r = (new CatalogTransfer(new CatalogService($this->db, new TenantDb($this->db, $this->ctx), $this->clock)))->import($csv, 'U2');
        $this->assertSame(1, $r['created']);
    }

    public function testReferenceCatalogueIsImportedWithoutPricesAndOnlyOnce(): void
    {
        $ref = new ReferenceCatalog($this->db, $this->cat);
        $ref->seed('CM');
        $n = $ref->importInto('CM', 'U1');
        $this->assertGreaterThanOrEqual(30, $n);
        $first = $this->cat->search('')['items'][0];
        $this->assertSame(0, $first['sale_price']);
        $this->assertSame(0, $ref->importInto('CM', 'U1'), 'second import : rien à ajouter');
        $this->assertSame(0, (int) $this->db->one("SELECT COUNT(*) n FROM products WHERE regulatory_status NOT IN ('free','prescription_only','list_i','list_ii','narcotic')")['n']);
    }
}
