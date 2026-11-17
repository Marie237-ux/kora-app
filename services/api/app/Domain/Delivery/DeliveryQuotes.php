<?php

namespace App\Domain\Delivery;

use App\Domain\Cart\Carts;
use App\Domain\Inventory\Inventory;
use App\Domain\Procurement\Records;
use App\Domain\Promotions\DeliveryOffers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Server-side delivery quote for the selected cart lines. The physical sources come from the inventory plan
 * (KORA depot first, then the partner shop), never from the number of shops shown in the cart. When something
 * needed for a reliable price is missing, the quote says so instead of showing an amount.
 *
 * Status: quoted (exact total), confirmation_required (the team must confirm the price) or unserviceable.
 */
final class DeliveryQuotes
{
    public function create(object $cart, object $address, User $actor, bool $applyPromotions = true): array
    {
        $carts = app(Carts::class);
        $view = $carts->dto($cart);
        $lines = array_values(array_filter($view['lines'], fn ($line) => $line['selected']));
        if (! $lines) {
            DeliveryZones::fail('cart', 'CART_NOTHING_SELECTED');
        }
        if ($view['needs_review']) {
            DeliveryZones::fail('cart', 'CART_NEEDS_REVIEW');
        }
        // Lines from a shop in the address's city are delivered by KORA couriers (zone, grid, slot); lines from a shop
        // in another city are shipped, one parcel per origin city, at the route's flat fee and delay.
        $city = $address->city ?? 'Douala';
        $origins = DB::table('offers')
            ->join('shops', 'shops.id', '=', 'offers.shop_id')
            ->whereIn('offers.id', array_column($lines, 'offer_id'))
            ->pluck('shops.city', 'offers.id');
        $originOf = fn ($line) => $origins[$line['offer_id']] ?? 'Douala';
        $local = array_values(array_filter($lines, fn ($line) => $originOf($line) === $city));
        $shipped = array_values(array_filter($lines, fn ($line) => $originOf($line) !== $city));
        $zone = $local ? app(DeliveryZones::class)->resolve($address->district, $city) : null;
        $grid = app(DeliveryGrids::class)->current();
        $rules = $grid ? json_decode($grid->rules, true) : null;
        $reasons = [];
        [$plan, $lineReasons, $readyAfter] = $this->plan($lines, $actor, $rules);
        array_push($reasons, ...$lineReasons);
        $localPlan = $shipped && $local ? $this->plan($local, $actor, $rules)[0] : $plan;

        $status = 'quoted';
        $breakdown = [];
        $total = null;
        $localTotal = '0';
        if ($local) {
            if (! $zone) {
                $status = 'unserviceable';
                $reasons[] = 'zone_not_served';
            } elseif (in_array('transport_unavailable', $reasons, true)) {
                $status = 'unserviceable';
            } elseif (! $grid) {
                $status = 'confirmation_required';
                $reasons[] = 'grid_missing';
            } elseif ($reasons) {
                $status = 'confirmation_required';
            } else {
                $points = collect($localPlan['points']);
                $price = app(DeliveryGrids::class)->price(
                    $grid,
                    $zone->id,
                    $points->pluck('zone_id')->all(),
                    $localPlan['mass_grams'],
                    $localPlan['bulky'],
                    $localPlan['fragile'],
                );
                if (isset($price['reason'])) {
                    $status = $price['reason'] === 'zone_not_in_grid' ? 'unserviceable' : 'confirmation_required';
                    $reasons[] = $price['reason'];
                } else {
                    $breakdown = $price['breakdown'];
                    $localTotal = (string) $price['total'];
                }
            }
        } elseif (in_array('transport_unavailable', $reasons, true)) {
            $status = 'unserviceable';
        } elseif ($reasons) {
            $status = 'confirmation_required';
        }
        $groups = [];
        if ($local) {
            $groups[] = ['mode' => 'local', 'city' => $city, 'line_ids' => array_column($local, 'id'), 'fee' => $status === 'quoted' ? $localTotal : null];
        }
        $shippingTotal = 0;
        foreach (collect($shipped)->groupBy($originOf) as $from => $group) {
            $route = DB::table('shipping_routes')
                ->where('active', true)
                ->where('origin_city', $from)
                ->whereIn('destination_city', [$city, '*'])
                ->orderByRaw("CASE WHEN destination_city = '*' THEN 1 ELSE 0 END")
                ->first();
            if (! $route) {
                $reasons[] = 'shipping_route_missing';
                if ($status === 'quoted') {
                    $status = 'confirmation_required';
                }
            }
            $groups[] = [
                'mode' => 'shipping',
                'from' => $from,
                'city' => $city,
                'line_ids' => $group->pluck('id')->values()->all(),
                'fee' => $route ? (string) $route->fee : null,
                'days_min' => $route ? (int) $route->days_min : null,
                'days_max' => $route ? (int) $route->days_max : null,
                'to_door' => $route ? (bool) $route->to_door : null,
            ];
            if ($route) {
                $shippingTotal += (int) $route->fee;
                $breakdown[] = ['code' => 'shipping', 'quantity' => 1, 'amount' => (int) $route->fee, 'from' => $from];
            }
        }
        $plan['groups'] = $groups;
        if ($status === 'quoted') {
            $total = (string) ((int) $localTotal + $shippingTotal);
        }
        // Shown with the quote only; re-checked and reserved under lock when the order is created.
        $promotion =
            $total === null || ! $applyPromotions || ! $zone
                ? null
                : app(DeliveryOffers::class)->evaluate(
                    $actor->id,
                    $zone->id,
                    array_map(fn ($line) => ['unit_price' => $line['current_conditions']['unit_price']], $lines),
                    $breakdown,
                    $total,
                );
        $id = Records::id();
        DB::table('delivery_quotes')->insert([
            'id' => $id,
            'user_id' => $actor->id,
            'cart_id' => $cart->id,
            'cart_version' => $cart->version,
            'address_id' => $address->id,
            'address_snapshot' => Records::json(app(CustomerAddresses::class)->snapshot($address)),
            'zone_id' => $zone?->id,
            'grid_id' => $grid?->id,
            'status' => $status,
            'reasons' => Records::json(array_values(array_unique($reasons))),
            'plan' => Records::json($plan),
            'breakdown' => Records::json($breakdown),
            'total' => $total,
            'promotion' => $promotion ? Records::json($promotion) : null,
            'demo_amounts' => (bool) ($rules['demo'] ?? false),
            'ready_after_minutes' => $readyAfter,
            'expires_at' => now()->addMinutes($rules['validity_minutes'] ?? 30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->dto(Records::row('delivery_quotes', $id, false), $cart);
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<int, string>, 2: ?int}
     */
    private function plan(array $lines, User $actor, ?array $rules): array
    {
        $inventory = app(Inventory::class);
        $points = [];
        $reasons = [];
        $planLines = [];
        $grams = 0;
        $bulky = false;
        $fragile = false;
        $readyAfter = 0;
        // Sorted offer order keeps the row locks taken by the inventory plan in a stable order.
        usort($lines, fn ($a, $b) => strcmp($a['offer_id'], $b['offer_id']));
        foreach ($lines as $line) {
            $issues = [];
            $offer = $inventory->offerTerms($line['offer_id']);
            $profile = DB::table('inventory_profiles')->where('variant_id', $offer['variant']->id)->first();
            $logistics = $profile ? json_decode($profile->logistics, true) : null;
            $class = $logistics['transport_class'] ?? null;
            if (in_array($class, ['unavailable', 'temperature_controlled'], true)) {
                $issues[] = 'transport_unavailable';
            }
            if (empty($logistics['mass_grams'])) {
                $issues[] = 'mass_unknown';
            } else {
                $grams += $logistics['mass_grams'] * $line['quantity'];
            }
            $bulky = $bulky || $class === 'bulky';
            $fragile = $fragile || $class === 'fragile';

            $stock = $inventory->plan($line['offer_id'], $line['quantity'], $actor);
            $fromWarehouse = 0;
            foreach ($stock['warehouse_sources'] as $source) {
                $point = $this->warehousePoint($source['location_id']);
                if (! $point) {
                    $issues[] = 'collection_point_missing';

                    continue;
                }
                $points[$point->id] = ['id' => $point->id, 'zone_id' => $point->zone_id, 'kind' => 'warehouse'];
                $fromWarehouse += $source['quantity'];
            }
            $fromSupplier = $stock['supplier_quantity_needed'];
            if ($fromSupplier > 0) {
                if ($stock['preparation_minutes'] === null) {
                    $issues[] = 'supplier_unconfirmed';
                } elseif (
                    $stock['supplier_quantity_reported'] !== null &&
                    $stock['supplier_quantity_reported'] < $fromSupplier
                ) {
                    $issues[] = 'supplier_quantity_short';
                } else {
                    $readyAfter = max($readyAfter, $stock['preparation_minutes']);
                }
                $point = $this->shopPoint($offer['shop']->id);
                if ($point) {
                    $points[$point->id] = ['id' => $point->id, 'zone_id' => $point->zone_id, 'kind' => 'shop'];
                } else {
                    $issues[] = 'collection_point_missing';
                }
            }
            if ($fromWarehouse > 0) {
                $readyAfter = max($readyAfter, (int) ($rules['warehouse_preparation_minutes'] ?? 0));
            }
            $planLines[] = [
                'cart_line_id' => $line['id'],
                'offer_id' => $line['offer_id'],
                'quantity' => $line['quantity'],
                'from_warehouse' => $fromWarehouse,
                'from_supplier' => $fromSupplier,
                'warehouse_sources' => $stock['warehouse_sources'],
                'issues' => array_values(array_unique($issues)),
            ];
            array_push($reasons, ...$issues);
        }

        return [
            [
                'lines' => $planLines,
                'points' => array_values($points),
                'mass_grams' => $grams,
                'bulky' => $bulky,
                'fragile' => $fragile,
            ],
            array_values(array_unique($reasons)),
            $reasons ? null : $readyAfter,
        ];
    }

    private function warehousePoint(string $locationId): ?object
    {
        $warehouseId = DB::table('stock_locations')->where('id', $locationId)->value('warehouse_id');

        return DB::table('collection_points')
            ->where('warehouse_id', $warehouseId)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    private function shopPoint(string $shopId): ?object
    {
        return DB::table('collection_points')
            ->where('shop_id', $shopId)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    public function owned(string $id, User $actor, bool $lock = false): object
    {
        $q = DB::table('delivery_quotes')->where('id', $id)->where('user_id', $actor->id);
        if ($lock) {
            $q->lockForUpdate();
        }
        $quote = $q->first();
        abort_unless($quote, 404);

        return $quote;
    }

    public function holdSlot(object $quote, object $cart, string $ruleId, string $date): array
    {
        if ($quote->status !== 'quoted' || CarbonImmutable::parse($quote->expires_at, 'UTC')->isPast()) {
            DeliveryZones::fail('quote', 'QUOTE_NOT_BOOKABLE');
        }
        if ((int) $quote->cart_version !== (int) $cart->version || $quote->cart_id !== $cart->id) {
            DeliveryZones::fail('quote', 'QUOTE_STALE');
        }
        app(DeliverySlots::class)->hold($quote, $ruleId, $date);

        return $this->dto(Records::row('delivery_quotes', $quote->id, false), $cart);
    }

    /** Customer view: amounts and counts only, never supplier identities, stock locations or costs. */
    public function dto(object $quote, object $cart): array
    {
        $plan = json_decode($quote->plan, true);
        $promotion = $quote->promotion ? json_decode($quote->promotion, true) : null;
        $expired = CarbonImmutable::parse($quote->expires_at, 'UTC')->isPast();
        $stale = $quote->cart_id !== $cart->id || (int) $quote->cart_version !== (int) $cart->version;
        $zone = $quote->zone_id ? Records::row('delivery_zones', $quote->zone_id, false) : null;
        $address = json_decode($quote->address_snapshot, true);
        $slot = null;
        if ($quote->slot_rule_id) {
            $rule = Records::row('delivery_slot_rules', $quote->slot_rule_id, false);
            $slot = [
                'rule_id' => $rule->id,
                'date' => $quote->slot_date,
                'starts_at' => $rule->starts_at,
                'ends_at' => $rule->ends_at,
                'status' => $quote->slot_status === 'held' && $expired ? 'expired' : $quote->slot_status,
            ];
        }
        $bookable = $quote->status === 'quoted' && ! $expired && ! $stale;

        return [
            'id' => $quote->id,
            'status' => $quote->status,
            'reasons' => json_decode($quote->reasons, true),
            'version' => (int) $quote->version,
            'cart_version' => (int) $quote->cart_version,
            'stale' => $stale,
            'expired' => $expired,
            'expires_at' => Records::iso($quote->expires_at),
            'currency' => 'XAF',
            'total' => $quote->total === null ? null : (string) $quote->total,
            'breakdown' => array_map(
                fn ($item) => [...$item, 'amount' => (string) $item['amount']],
                json_decode($quote->breakdown, true),
            ),
            'demo_amounts' => (bool) $quote->demo_amounts,
            'zone' => $zone ? app(DeliveryZones::class)->dto($zone, true) : null,
            'address' => ['id' => $quote->address_id, 'label' => $address['label'], 'city' => $address['city'] ?? 'Douala', 'district' => $address['district']],
            // Delivered in the address's city, or shipped from another city (one group per origin city).
            'groups' => $plan['groups'] ?? [],
            'needs_slot' => collect($plan['groups'] ?? [['mode' => 'local']])->contains('mode', 'local'),
            'collection_count' => count($plan['points']),
            'ready_after_minutes' => $quote->ready_after_minutes === null ? null : (int) $quote->ready_after_minutes,
            'lines' => array_map(
                fn ($line) => [
                    'cart_line_id' => $line['cart_line_id'],
                    'offer_id' => $line['offer_id'],
                    'quantity' => $line['quantity'],
                    'from_warehouse' => $line['from_warehouse'],
                    'from_supplier' => $line['from_supplier'],
                    'issues' => $line['issues'],
                ],
                $plan['lines'],
            ),
            'slot' => $slot,
            'slots' => $bookable && $quote->zone_id
                ? app(DeliverySlots::class)->available($quote->zone_id, (int) $quote->ready_after_minutes, $quote->id)
                : [],
            // "full" may be shown as free delivery; "base" must show the base fee offered and the supplements still due.
            'promotion' => $promotion
                ? [
                    'offer_id' => $promotion['offer_id'],
                    'name' => $promotion['name'][app()->getLocale()] ?? $promotion['name']['fr'],
                    'coverage' => $promotion['coverage'],
                    'discount' => (string) $promotion['discount'],
                    'min_unit_price' => $promotion['min_unit_price'],
                    'threshold_basis' => $promotion['threshold_basis'],
                    'remaining_delivery' => (string) $promotion['remaining_delivery'],
                    'uses_left_after' => $promotion['uses_left_after'],
                ]
                : null,
            'payable_delivery' => $quote->total === null ? null : (string) ((int) $quote->total - (int) ($promotion['discount'] ?? 0)),
            'promotion_applied' => $promotion !== null,
            'stock_reserved' => false,
            'created_at' => Records::iso($quote->created_at),
        ];
    }
}
