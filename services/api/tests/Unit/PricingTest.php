<?php

namespace Tests\Unit;

use App\Domain\Pricing\PriceCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PricingTest extends TestCase
{
    public function test_purchase_discount_does_not_increase_the_customer_price(): void
    {
        $result = (new PriceCalculator)->calculate(5000, 300);
        $this->assertSame(4850, $result['purchase_unit']);
        $this->assertSame(5000, $result['selling_unit']);
        $this->assertSame(150, $result['gross_difference']);
    }

    public function test_kora_promotion_does_not_recalculate_the_supplier_price(): void
    {
        $result = (new PriceCalculator)->calculate(5000, 300, 4900);
        $this->assertSame(4850, $result['purchase_unit']);
        $this->assertSame(50, $result['gross_difference']);
    }

    public function test_rounding_is_applied_per_unit_and_the_difference_is_recorded(): void
    {
        $result = (new PriceCalculator)->calculate(5050, 100, null, 3);
        $this->assertSame(51, $result['discount_unit']);
        $this->assertSame(14997, $result['purchase_total']);
        $this->assertSame(5000, $result['rounding_numerator']);
    }

    public function test_negative_margin_is_reported_without_falsifying_cost(): void
    {
        $result = (new PriceCalculator)->calculate(5000, 300, 4800);
        $this->assertSame(-50, $result['gross_difference']);
    }

    public function test_large_valid_amounts_are_exact_integers(): void
    {
        $result = (new PriceCalculator)->calculate(1_000_000_000_000, 9999, null, 1_000_000);
        $this->assertSame(100_000_000_000_000, $result['purchase_total']);
    }

    public function test_invalid_rates_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PriceCalculator)->calculate(5000, 10001);
    }
}
