<?php

namespace App\Domain\Promotions;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivery offers such as "first three deliveries offered". Eligibility is shown with the quote, then re-checked
 * and reserved under the offer lock when the order is created, consumed when payment is confirmed and released if
 * the order expires. Reserved advantages count against the budget and the customer's uses until they expire, so
 * two simultaneous orders can neither exceed the budget nor use the last advantage twice.
 */
final class DeliveryOffers
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('promotions.'.$code)]);
    }

    /**
     * Best applicable offer for these selected lines, or null. Lines carry their current unit price.
     *
     * @param  array<int, array<string, mixed>>  $breakdown  delivery quote breakdown
     */
    public function evaluate(
        int $userId,
        string $zoneId,
        array $lines,
        array $breakdown,
        int $deliveryTotal,
        bool $lock = false,
    ): ?array {
        $now = now();
        $q = DB::table('delivery_offers')
            ->where('status', 'active')
            ->where('starts_at', '<=', $now)
            ->where(fn ($w) => $w->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->orderBy('id');
        if ($lock) {
            $q->lockForUpdate();
        }
        $best = null;
        $maxUnitPrice = max(array_map(fn ($line) => (int) $line['unit_price'], $lines) ?: [0]);
        foreach ($q->get() as $offer) {
            if (
                ! in_array($zoneId, json_decode($offer->zone_ids, true), true) ||
                $maxUnitPrice < $offer->min_unit_price
            ) {
                continue;
            }
            $used = $this->live($offer->id)->where('user_id', $userId)->count();
            if ($used >= $offer->uses_per_customer) {
                continue;
            }
            $base = (int) (collect($breakdown)->firstWhere('code', 'base')['amount'] ?? 0);
            $discount = $offer->coverage === 'full' ? $deliveryTotal : min($base, $deliveryTotal);
            $spent = (int) $this->live($offer->id)->sum('amount');
            if ($discount < 1 || $discount > $offer->budget - $spent) {
                continue;
            }
            if (! $best || $discount > $best['discount']) {
                $best = [
                    'offer_id' => $offer->id,
                    'offer_version' => (int) $offer->version,
                    'name' => json_decode($offer->name, true),
                    'min_unit_price' => (string) $offer->min_unit_price,
                    'threshold_basis' => 'paid_unit_price',
                    'coverage' => $offer->coverage,
                    'discount' => $discount,
                    'remaining_delivery' => $deliveryTotal - $discount,
                    'uses_left_after' => $offer->uses_per_customer - $used - 1,
                ];
            }
        }

        return $best;
    }

    /** Consumed advantages plus reservations still within their payment window. */
    private function live(string $offerId)
    {
        return DB::table('delivery_offer_usages')
            ->where('offer_id', $offerId)
            ->where(
                fn ($q) => $q
                    ->where('status', 'consumed')
                    ->orWhere(fn ($r) => $r->where('status', 'reserved')->where('expires_at', '>', now())),
            );
    }

    /** Called under the offer lock taken by evaluate(..., lock: true). */
    public function reserve(string $orderId, int $userId, array $promotion, CarbonImmutable|string $expiresAt): void
    {
        DB::table('delivery_offer_usages')->insert([
            'id' => Records::id(),
            'offer_id' => $promotion['offer_id'],
            'user_id' => $userId,
            'order_id' => $orderId,
            'amount' => $promotion['discount'],
            'status' => 'reserved',
            'expires_at' => $expiresAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Payment confirmed: the advantage is used. A payment that arrives after the window still keeps the price the
     * customer accepted; if the budget was taken meanwhile, an incident makes the overrun visible.
     */
    public function consume(object $order): void
    {
        $usage = DB::table('delivery_offer_usages')->where('order_id', $order->id)->lockForUpdate()->first();
        if (! $usage || $usage->status === 'consumed') {
            return;
        }
        $offer = DB::table('delivery_offers')->where('id', $usage->offer_id)->lockForUpdate()->first();
        $late = $usage->status === 'released' || CarbonImmutable::parse($usage->expires_at, 'UTC')->isPast();
        DB::table('delivery_offer_usages')
            ->where('id', $usage->id)
            ->update(['status' => 'consumed', 'updated_at' => now()]);
        if ($late && (int) $this->live($offer->id)->sum('amount') > $offer->budget) {
            DB::table('payment_incidents')->insertOrIgnore([
                'id' => Records::id(),
                'order_id' => $order->id,
                'kind' => 'promotion_budget_exceeded',
                'details' => Records::json(['offer_id' => $offer->id, 'amount' => (int) $usage->amount]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Expired or cancelled order: the advantage and its budget become available again. */
    public function release(string $orderId): void
    {
        DB::table('delivery_offer_usages')
            ->where('order_id', $orderId)
            ->where('status', 'reserved')
            ->update(['status' => 'released', 'updated_at' => now()]);
    }

    public function save(?object $offer, array $data, User $actor): array
    {
        if ($offer && ! in_array($offer->status, ['draft', 'paused'], true)) {
            self::fail('offer', 'OFFER_NOT_EDITABLE');
        }
        $zones = DB::table('delivery_zones')->whereIn('id', $data['zone_ids'])->count();
        if ($zones !== count($data['zone_ids'])) {
            self::fail('zone_ids', 'ZONE_INVALID');
        }
        $budget = Money::parse($data['budget']);
        if ($offer && $budget < (int) $this->live($offer->id)->sum('amount')) {
            self::fail('budget', 'BUDGET_BELOW_COMMITTED');
        }
        $values = [
            'name' => Records::json($data['name']),
            // Stored in UTC so that comparisons with "now" behave the same on every database.
            'starts_at' => CarbonImmutable::parse($data['starts_at'])->utc(),
            'ends_at' => empty($data['ends_at']) ? null : CarbonImmutable::parse($data['ends_at'])->utc(),
            'zone_ids' => Records::json(array_values($data['zone_ids'])),
            'min_unit_price' => Money::parse($data['min_unit_price']),
            'uses_per_customer' => $data['uses_per_customer'],
            'coverage' => $data['coverage'],
            'budget' => $budget,
            'funded_by' => $data['funded_by'],
            'updated_at' => now(),
        ];
        if ($offer) {
            // A lower budget never cancels advantages already reserved or consumed.
            DB::table('delivery_offers')
                ->where('id', $offer->id)
                ->update([...$values, 'version' => $offer->version + 1]);
            $id = $offer->id;
        } else {
            $id = Records::id();
            DB::table('delivery_offers')->insert([
                ...$values,
                'id' => $id,
                'status' => 'draft',
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }
        Records::audit($actor, 'promotion.delivery_offer_saved', $id);

        return $this->dto(Records::row('delivery_offers', $id, false));
    }

    public function transition(object $offer, string $action, User $actor): array
    {
        $to = ['activate' => 'active', 'pause' => 'paused', 'end' => 'ended'][$action];
        $allowed = [
            'activate' => ['draft', 'paused'],
            'pause' => ['active'],
            'end' => ['draft', 'active', 'paused'],
        ][$action];
        if (! in_array($offer->status, $allowed, true)) {
            self::fail('offer', 'OFFER_TRANSITION_INVALID');
        }
        DB::table('delivery_offers')
            ->where('id', $offer->id)
            ->update(['status' => $to, 'version' => $offer->version + 1, 'updated_at' => now()]);
        Records::audit($actor, 'promotion.delivery_offer_'.$action, $offer->id);

        return $this->dto(Records::row('delivery_offers', $offer->id, false));
    }

    /** Includes the real cumulative cost so the team sees the commitment before and during the campaign. */
    public function dto(object $offer): array
    {
        $sum = fn (string $status) => (int) DB::table('delivery_offer_usages')
            ->where('offer_id', $offer->id)
            ->where('status', $status)
            ->when($status === 'reserved', fn ($q) => $q->where('expires_at', '>', now()))
            ->sum('amount');
        $consumed = $sum('consumed');
        $reserved = $sum('reserved');

        return [
            'id' => $offer->id,
            'name' => json_decode($offer->name, true),
            'status' => $offer->status,
            'starts_at' => Records::iso($offer->starts_at),
            'ends_at' => Records::iso($offer->ends_at),
            'zone_ids' => json_decode($offer->zone_ids, true),
            'min_unit_price' => (string) $offer->min_unit_price,
            'threshold_basis' => 'paid_unit_price',
            'uses_per_customer' => (int) $offer->uses_per_customer,
            'coverage' => $offer->coverage,
            'budget' => (string) $offer->budget,
            'consumed' => (string) $consumed,
            'reserved' => (string) $reserved,
            'available' => (string) max(0, $offer->budget - $consumed - $reserved),
            'funded_by' => $offer->funded_by,
            'version' => (int) $offer->version,
        ];
    }
}
