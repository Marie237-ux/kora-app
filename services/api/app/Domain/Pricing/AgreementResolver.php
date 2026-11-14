<?php

namespace App\Domain\Pricing;

use App\Domain\Catalogue\CategoryAttributes;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AgreementResolver
{
    public function quote(
        string $supplierId,
        string $productId,
        string $categoryId,
        int $reference,
        int $selling,
        int $quantity,
        ?CarbonImmutable $at = null,
    ): array {
        $at ??= CarbonImmutable::now('UTC');
        $categories = array_column(app(CategoryAttributes::class)->ancestry($categoryId), 'id');
        $rules = DB::table('purchase_agreements')
            ->where('supplier_id', $supplierId)
            ->whereNull('withdrawn_at')
            ->where('starts_at', '<=', $at)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $at))
            ->where('minimum_quantity', '<=', $quantity)
            ->get()
            ->filter(
                fn ($rule) => $rule->product_id === $productId ||
                    (! $rule->product_id &&
                        ($rule->category_id === null || in_array($rule->category_id, $categories, true))),
            );
        $rank = fn ($rule) => [
            $rule->product_id ? 3 : ($rule->category_id ? 2 : 1),
            $rule->category_id ? count($categories) - array_search($rule->category_id, $categories, true) : 0,
            (int) $rule->minimum_quantity,
            (int) $rule->priority,
        ];
        $rules = $rules->sort(fn ($a, $b) => $rank($b) <=> $rank($a))->values();
        if ($rules->isEmpty()) {
            throw ValidationException::withMessages(['agreement' => __('catalogue.AGREEMENT_MISSING')]);
        }
        if ($rules->count() > 1 && $rank($rules[0]) === $rank($rules[1])) {
            throw ValidationException::withMessages(['agreement' => __('catalogue.AGREEMENT_AMBIGUOUS')]);
        }
        $rule = $rules[0];
        $calculation = app(PriceCalculator::class)->calculate(
            $reference,
            (int) $rule->discount_basis_points,
            $selling,
            $quantity,
            $rule->mode === 'fixed' ? (int) $rule->fixed_cost : null,
        );
        $negative = $calculation['gross_difference'] < 0;
        // JSON numbers lose integer precision in JavaScript above 2^53 - 1.
        foreach (['purchase_total', 'selling_total', 'gross_difference'] as $field) {
            $calculation[$field] = (string) $calculation[$field];
        }

        return [
            'agreement' => [
                'id' => $rule->id,
                'mode' => $rule->mode,
                'contract_reference' => $rule->contract_reference,
                'discount_basis_points' => (int) $rule->discount_basis_points,
                'fixed_cost' => $rule->fixed_cost === null ? null : (int) $rule->fixed_cost,
                'minimum_quantity' => (int) $rule->minimum_quantity,
                'starts_at' => CarbonImmutable::parse($rule->starts_at, 'UTC')->toIso8601String(),
                'ends_at' => $rule->ends_at ? CarbonImmutable::parse($rule->ends_at, 'UTC')->toIso8601String() : null,
            ],
            'calculation' => $calculation,
            'negative_margin' => $negative,
            'as_of' => $at->toIso8601String(),
        ];
    }
}
