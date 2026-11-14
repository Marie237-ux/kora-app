<?php

namespace App\Domain\Pricing;

use InvalidArgumentException;

final class PriceCalculator
{
    /** Taux en points de base, arrondi demi-supérieur par unité avant multiplication. */
    public function calculate(
        int $reference,
        int $basisPoints,
        ?int $selling = null,
        int $quantity = 1,
        ?int $fixedPurchase = null,
    ): array {
        $selling ??= $reference;
        if (
            $reference < 0 ||
            $reference > 1_000_000_000_000 ||
            $selling < 0 ||
            $selling > 1_000_000_000_000 ||
            $basisPoints < 0 ||
            $basisPoints > 10000 ||
            $quantity < 1 ||
            $quantity > 1_000_000 ||
            ($fixedPurchase !== null && ($fixedPurchase < 0 || $fixedPurchase > 1_000_000_000_000))
        ) {
            throw new InvalidArgumentException('Invalid price, rate or quantity');
        }
        $discount = intdiv($reference * $basisPoints + 5000, 10000);
        $cost = $fixedPurchase ?? $reference - $discount;

        return [
            'currency' => 'XAF',
            'reference_unit' => $reference,
            'selling_unit' => $selling,
            'purchase_unit' => $cost,
            'discount_unit' => $reference - $cost,
            'rounding_numerator' => $fixedPurchase === null ? $discount * 10000 - $reference * $basisPoints : 0,
            'quantity' => $quantity,
            'purchase_total' => $cost * $quantity,
            'selling_total' => $selling * $quantity,
            'gross_difference' => ($selling - $cost) * $quantity,
        ];
    }
}
