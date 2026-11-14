<?php

namespace App\Domain\Loyalty;

use App\Domain\Cart\Carts;
use App\Domain\Inventory\Inventory;
use App\Domain\Pricing\AgreementResolver;
use App\Domain\Procurement\Records;
use App\Domain\Promotions\DeliveryOffers;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class LoyaltyCheckoutQuotes
{
    public function signature(array $selected): string
    {
        $lines = collect($selected)
            ->sortBy('offer_id')
            ->map(
                fn ($l) => [
                    'id' => $l['id'],
                    'offer_id' => $l['offer_id'],
                    'quantity' => $l['quantity'],
                    'conditions' => $l['current_conditions'],
                ],
            )
            ->values()
            ->all();

        return app(CommandBus::class)->fingerprint($lines);
    }

    /** Exact largest-remainder allocation, stable ties; no floating point or amount overflow. */
    public function apportion(int $amount, array $weights): array
    {
        $total = array_sum($weights);
        if ($total < 1) {
            LoyaltyProgram::fail('points', 'REDEMPTION_LIMIT');
        }
        $parts = [];
        $remainders = [];
        foreach ($weights as $key => $weight) {
            [$part, $remainder] = BigInteger::of($amount)->multipliedBy($weight)->quotientAndRemainder($total);
            $parts[$key] = $part->toInt();
            $remainders[$key] = $remainder;
        }
        $keys = array_keys($weights);
        usort($keys, fn ($a, $b) => $remainders[$b]->compareTo($remainders[$a]) ?: strcmp((string) $a, (string) $b));
        for ($i = 0, $left = $amount - array_sum($parts); $i < $left; $i++) {
            $parts[$keys[$i]]++;
        }

        return $parts;
    }

    /** Cart and delivery quote are locked by the caller. A preview never reserves points or campaign money. */
    public function create(object $cart, object $delivery, int $points, User $actor): array
    {
        if (
            $delivery->cart_id !== $cart->id ||
            (int) $delivery->cart_version !== (int) $cart->version ||
            $delivery->status !== 'quoted' ||
            $delivery->slot_status !== 'held' ||
            ! CarbonImmutable::parse($delivery->expires_at, 'UTC')->isFuture() ||
            DB::table('orders')->where('quote_id', $delivery->id)->exists()
        ) {
            LoyaltyProgram::fail('quote', 'REDEMPTION_QUOTE_INVALID');
        }
        $view = app(Carts::class)->dto($cart);
        $selected = collect($view['lines'])->where('selected', true)->keyBy('id')->all();
        if ($view['needs_review'] || ! $selected) {
            LoyaltyProgram::fail('cart', 'CART_NEEDS_REVIEW');
        }
        // Same resource order as checkout: offers precede loyalty account and budget locks.
        $termsById = [];
        foreach (collect($selected)->sortBy('offer_id') as $id => $line) {
            $termsById[$id] = app(Inventory::class)->offerTerms($line['offer_id']);
        }
        $account = app(LoyaltyAccounts::class)->owned($actor, true);
        if (! $account || $account->status !== 'active' || ! app(LoyaltyProgram::class)->current()['enabled']) {
            LoyaltyProgram::fail('points', 'REDEMPTION_NOT_AVAILABLE');
        }
        app(LoyaltyRedemptions::class)->releaseExpired($account->id);
        app(LoyaltyLedger::class)->reconcile($account->id);
        $policy = Records::row('loyalty_redemption_policy', '1');
        if (! $policy->enabled || ! $policy->rules) {
            LoyaltyProgram::fail('points', 'REDEMPTION_NOT_AVAILABLE');
        }
        $rules = json_decode($policy->rules, true);
        if (
            $points < 1 ||
            $points % $rules['points_per_unit'] !== 0 ||
            app(LoyaltyAccounts::class)->balance($account->id) < $points
        ) {
            LoyaltyProgram::fail('points', 'POINTS_UNAVAILABLE');
        }
        $capacities = [];
        $eligible = 0;
        foreach (collect($selected)->sortBy('offer_id') as $id => $line) {
            $terms = $termsById[$id];
            if (! in_array($terms['product']->category_id, $rules['excluded_category_ids'], true)) {
                $gross = Money::multiply($line['current_conditions']['unit_price'], $line['quantity']);
                $eligible = Money::sum([$eligible, $gross]);
                // Every returned unit retains at least one XAF of cash value.
                $capacities[$id] = $gross - $line['quantity'];
            }
        }
        $discount = Money::multiply(
            Money::parse($rules['value_per_unit_xaf']),
            intdiv($points, $rules['points_per_unit']),
        );
        $cap = LoyaltyLedger::ratio($eligible, $rules['max_discount_basis_points'], 10000);
        if ($discount < 1 || $discount > $cap || $discount > array_sum($capacities)) {
            LoyaltyProgram::fail('points', 'REDEMPTION_LIMIT');
        }
        $discounts = $this->apportion($discount, array_filter($capacities, fn ($v) => $v > 0));
        $linePoints = $this->apportion($points, $discounts);
        $lines = [];
        foreach ($selected as $id => $line) {
            $lines[$id] = ['discount' => $discounts[$id] ?? 0, 'points' => $linePoints[$id] ?? 0];
            if (($discounts[$id] ?? 0) > 0) {
                $terms = $termsById[$id];
                $cost = app(AgreementResolver::class)->quote(
                    $terms['shop']->supplier_id,
                    $terms['product']->id,
                    $terms['product']->category_id,
                    (int) $terms['offer']->reference_price,
                    $line['current_conditions']['unit_price'],
                    $line['quantity'],
                );
                if (
                    (int) $cost['calculation']['gross_difference'] - $discounts[$id] < 0 &&
                    ! $rules['allow_negative_margin']
                ) {
                    LoyaltyProgram::fail('points', 'REDEMPTION_LOSS_NOT_APPROVED');
                }
            }
        }
        $promotion = $rules['combine_delivery_promotions']
            ? app(DeliveryOffers::class)->evaluate(
                $actor->id,
                $delivery->zone_id,
                $this->promotionPrices($selected, $lines),
                json_decode($delivery->breakdown, true),
                (int) $delivery->total,
            )
            : null;
        $items = Money::parse($view['selected_subtotal']) - $discount;
        $deliveryTotal = (int) $delivery->total - (int) ($promotion['discount'] ?? 0);
        $total = Money::sum([$items, $deliveryTotal]);
        if ($total < Money::parse($rules['minimum_payable_xaf'])) {
            LoyaltyProgram::fail('points', 'MINIMUM_PAYABLE');
        }
        app(LoyaltyRedemptionPolicy::class)->assertBudget($policy, $discount);
        $id = Records::id();
        $snapshot = [
            'rules' => $rules,
            'lines' => $lines,
            'signature' => $this->signature($selected),
            'promotion' => $promotion,
            'items_total' => (string) $items,
            'delivery_total' => (string) $deliveryTotal,
            'total' => (string) $total,
        ];
        DB::table('loyalty_checkout_quotes')->insert([
            'id' => $id,
            'account_id' => $account->id,
            'cart_id' => $cart->id,
            'delivery_quote_id' => $delivery->id,
            'cart_version' => $cart->version,
            'delivery_version' => $delivery->version,
            'policy_version' => $policy->version,
            'points' => $points,
            'discount' => $discount,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'expires_at' => min(
                CarbonImmutable::parse($delivery->expires_at, 'UTC'),
                CarbonImmutable::now()->addMinutes(10),
            ),
            'created_at' => now(),
        ]);

        return $this->dto(Records::row('loyalty_checkout_quotes', $id, false));
    }

    public function promotionPrices(array $selected, array $allocations): array
    {
        return collect($selected)
            ->map(
                fn ($l) => [
                    'unit_price' => $l['current_conditions']['unit_price'] -
                        intdiv((int) ($allocations[$l['id']]['discount'] ?? 0), $l['quantity']),
                ],
            )
            ->values()
            ->all();
    }

    public function context(string $id, object $cart, object $delivery, array $selected, User $actor): object
    {
        $account = app(LoyaltyAccounts::class)->owned($actor);
        abort_unless($account, 404);
        $quote = DB::table('loyalty_checkout_quotes')
            ->where('id', $id)
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();
        abort_unless($quote, 404);
        if (
            $quote->cart_id !== $cart->id ||
            $quote->delivery_quote_id !== $delivery->id ||
            (int) $quote->cart_version !== (int) $cart->version ||
            (int) $quote->delivery_version !== (int) $delivery->version ||
            ! CarbonImmutable::parse($quote->expires_at, 'UTC')->isFuture()
        ) {
            LoyaltyProgram::fail('quote', 'REDEMPTION_QUOTE_INVALID');
        }
        $snapshot = json_decode($quote->snapshot, true);
        if (! hash_equals($snapshot['signature'], $this->signature($selected))) {
            LoyaltyProgram::fail('cart', 'REDEMPTION_QUOTE_INVALID');
        }

        return $quote;
    }

    public function dto(object $quote): array
    {
        $snapshot = json_decode($quote->snapshot, true);
        $rules = $snapshot['rules'];
        unset($rules['budget_xaf'], $rules['loss_reason']);

        return [
            'id' => $quote->id,
            'policy_version' => (int) $quote->policy_version,
            'points' => (string) $quote->points,
            'discount' => (string) $quote->discount,
            'items_total' => $snapshot['items_total'],
            'delivery_total' => $snapshot['delivery_total'],
            'total' => $snapshot['total'],
            'rules' => $rules,
            'expires_at' => Records::iso($quote->expires_at),
            'lines' => collect($snapshot['lines'])
                ->map(
                    fn ($l, $id) => [
                        'cart_line_id' => $id,
                        'discount' => (string) $l['discount'],
                        'points' => (string) $l['points'],
                    ],
                )
                ->values()
                ->all(),
            'delivery_promotion' => $snapshot['promotion']
                ? ['name' => $snapshot['promotion']['name'], 'discount' => (string) $snapshot['promotion']['discount']]
                : null,
            'points_reserved' => false,
        ];
    }
}
