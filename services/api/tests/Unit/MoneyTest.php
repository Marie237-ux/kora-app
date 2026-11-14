<?php

namespace Tests\Unit;

use App\Domain\Shared\Money;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class MoneyTest extends TestCase
{
    public function test_installments_preserve_every_xaf_for_tiny_and_very_large_amounts(): void
    {
        $this->assertSame([1, 0, 1], Money::apportion(2, [3333, 3333, 3334]));
        $this->assertSame([100000000000000, 999900000000000000], Money::apportion(Money::MAX, [1, 9999]));
        $this->assertSame(Money::MAX, Money::parse('1000000000000000000'));
    }

    public function test_money_does_not_accept_scientific_notation_or_overflow(): void
    {
        foreach (['1e3', '1.00', '-1', '1000000000000000001'] as $input) {
            try {
                Money::parse($input);
                $this->fail('Invalid amount accepted');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(ValidationException::class);
        Money::sum([Money::MAX, 1]);
    }

    public function test_multiplication_rejects_overflow_before_using_a_float(): void
    {
        $this->assertSame(Money::MAX, Money::multiply(1000000000000, 1000000));
        $this->expectException(ValidationException::class);
        Money::multiply(1000000000000, 1000000000);
    }
}
