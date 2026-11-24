<?php
declare(strict_types=1);

namespace Sanara\Tests\Sales;

use PHPUnit\Framework\TestCase;
use Sanara\Modules\Sales\SaleMath;

final class SaleMathTest extends TestCase
{
    public function testLineWithDiscountAndVatIncludedInPrice(): void
    {
        // 3 × 1 000 FCFA TTC, remise 10 %, TVA 19,25 % comprise
        $l = SaleMath::line(3, 1000, 1000, 1925);
        $this->assertSame(['gross' => 3000, 'discount' => 300, 'total' => 2700, 'vat' => 436], $l);
    }

    public function testRoundingIsHalfUpWithoutFloats(): void
    {
        $this->assertSame(1, SaleMath::ratio(5, 1, 10));
        $this->assertSame(0, SaleMath::ratio(4, 1, 10));
        $this->assertSame(3, SaleMath::ratio(25, 1, 10 - 2));
    }

    public function testMixedPaymentsGiveChangeFromCashOnly(): void
    {
        $r = SaleMath::settle(5000, [['method' => 'momo_mtn', 'amount' => 2000], ['method' => 'cash', 'amount' => 5000]]);
        $this->assertSame(2000, $r['change']);
        $this->assertSame([['method' => 'momo_mtn', 'amount' => 2000, 'tendered' => 2000], ['method' => 'cash', 'amount' => 3000, 'tendered' => 5000]], $r['applied']);
    }

    public function testInsufficientOrExcessNonCashPaymentsAreRefused(): void
    {
        $this->assertNull(SaleMath::settle(5000, [['method' => 'cash', 'amount' => 4999]]));
        $this->assertNull(SaleMath::settle(5000, [['method' => 'card', 'amount' => 6000]]));
        $this->assertNull(SaleMath::settle(5000, [['method' => 'cash', 'amount' => 0]]));
        $this->assertSame(0, SaleMath::settle(5000, [['method' => 'card', 'amount' => 5000]])['change']);
    }

    /** Mêmes cas que les tests TypeScript : packages/shared/fixtures/sales-cases.json. */
    public function testSharedFixturesGiveTheSameResultsAsTypeScript(): void
    {
        $cases = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/packages/shared/fixtures/sales-cases.json'), true);
        foreach ($cases['lines'] as $c) {
            $this->assertSame($c['expect'], SaleMath::line($c['qty'], $c['unitPrice'], $c['discountBp'], $c['vatBp']));
        }
        foreach ($cases['settle'] as $c) {
            $this->assertEquals($c['expect'], SaleMath::settle($c['total'], $c['payments']));
        }
    }
}
