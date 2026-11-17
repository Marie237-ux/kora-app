<?php

namespace App\Domain\Orders;

use App\Domain\Cart\CartConditions;
use App\Domain\Cart\Carts;
use App\Domain\Content\Cms;
use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Delivery\DeliveryZones;
use App\Domain\Inventory\Inventory;
use App\Domain\Loyalty\LoyaltyCheckoutQuotes;
use App\Domain\Loyalty\LoyaltyProgram;
use App\Domain\Loyalty\LoyaltyRedemptions;
use App\Domain\Pricing\AgreementResolver;
use App\Domain\Procurement\Records;
use App\Domain\Professional\BusinessProfiles;
use App\Domain\Professional\ProfessionalCart;
use App\Domain\Promotions\DeliveryOffers;
use App\Domain\Shared\DocumentNumbers;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An order is created from a valid delivery quote and the exact total the customer accepted. Its lines are
 * frozen snapshots: later catalogue changes never rewrite a sale. KORA-held quantities are reserved for the
 * payment window; quantities to collect from a supplier wait for the supplier's confirmation.
 */
final class Orders
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('orders.'.$code)]);
    }

    public function create(
        object $cart,
        object $quote,
        string $acceptedTotal,
        User $actor,
        ?object $professionalRevision = null,
        ?string $loyaltyQuoteId = null,
    ): array {
        if ($quote->cart_id !== $cart->id || (int) $quote->cart_version !== (int) $cart->version) {
            DeliveryZones::fail('quote', 'QUOTE_STALE');
        }
        if ($quote->status !== 'quoted' || CarbonImmutable::parse($quote->expires_at, 'UTC')->isPast()) {
            DeliveryZones::fail('quote', 'QUOTE_NOT_BOOKABLE');
        }
        // A slot is needed only for what KORA couriers deliver in the customer's city (shipped parcels have none).
        $needsSlot = collect(json_decode($quote->plan, true)['groups'] ?? [['mode' => 'local']])->contains('mode', 'local');
        if ($needsSlot && $quote->slot_status !== 'held') {
            self::fail('slot', 'SLOT_REQUIRED');
        }
        abort_if(DB::table('orders')->where('quote_id', $quote->id)->exists(), 409);

        $view = app(Carts::class)->dto($cart);
        $selected = collect($view['lines'])->where('selected', true)->keyBy('id');
        if ($view['needs_review'] || $view['selected_subtotal'] === null || $selected->isEmpty()) {
            DeliveryZones::fail('cart', 'CART_NEEDS_REVIEW');
        }
        $professional = null;
        if ($professionalRevision) {
            $businessQuote = Records::row('professional_quotes', $professionalRevision->quote_id);
            app(BusinessProfiles::class)->authorized($businessQuote->profile_id, $actor, true);
            abort_unless(
                $businessQuote->status === 'accepted' &&
                    $businessQuote->current_revision_id === $professionalRevision->id &&
                    ! $businessQuote->order_id &&
                    CarbonImmutable::parse($professionalRevision->valid_until, 'UTC')->isFuture(),
                409,
            );
            $professional = json_decode($professionalRevision->snapshot, true);
            $selected = collect(app(ProfessionalCart::class)->selected($selected->all(), $professional['lines']));
        }
        $itemsTotal = $professional
            ? Money::sum(
                $selected
                    ->map(fn ($line) => Money::multiply($line['current_conditions']['unit_price'], $line['quantity']))
                    ->all(),
            )
            : Money::parse($view['selected_subtotal']);
        abort_if($professional && $loyaltyQuoteId, 409);
        $redemptionQuote = $loyaltyQuoteId
            ? app(LoyaltyCheckoutQuotes::class)->context($loyaltyQuoteId, $cart, $quote, $selected->all(), $actor)
            : null;
        $redemption = $redemptionQuote ? json_decode($redemptionQuote->snapshot, true) : null;
        if ($redemption) {
            $selected = $selected->map(function ($line) use ($redemption) {
                $line['loyalty_allocation'] = $redemption['lines'][$line['id']];
                $line['loyalty_policy_rules'] = $redemption['rules'];

                return $line;
            });
        }
        // Re-checked under the offer lock: the budget or the customer's uses may have been taken since the quote.
        $promotion =
            $professional || ! $quote->zone_id || ($redemption && ! $redemption['rules']['combine_delivery_promotions'])
                ? null
                : app(DeliveryOffers::class)->evaluate(
                    $actor->id,
                    $quote->zone_id,
                    $redemption
                        ? app(LoyaltyCheckoutQuotes::class)->promotionPrices($selected->all(), $redemption['lines'])
                        : $selected
                            ->map(fn ($line) => ['unit_price' => $line['current_conditions']['unit_price']])
                            ->values()
                            ->all(),
                    json_decode($quote->breakdown, true),
                    (int) $quote->total,
                    true,
                );
        $quotedPromotion = $redemption
            ? $redemption['promotion']
            : ($quote->promotion
                ? json_decode($quote->promotion, true)
                : null);
        // Equal totals alone do not authorise spending a different campaign or accepting new conditions.
        foreach (['offer_id', 'offer_version', 'coverage', 'discount'] as $condition) {
            if (($quotedPromotion[$condition] ?? null) !== ($promotion[$condition] ?? null)) {
                DeliveryOffers::fail('quote', 'OFFER_CHANGED');
            }
        }
        $deliveryTotal = $professional
            ? Money::parse($professional['delivery']['total'])
            : (int) $quote->total - (int) ($promotion['discount'] ?? 0);
        $itemsTotal -= (int) ($redemptionQuote->discount ?? 0);
        $total = Money::sum([$itemsTotal, $deliveryTotal]);
        // The customer accepts one exact amount; any difference means the conditions moved under them.
        if (! hash_equals((string) $total, $acceptedTotal)) {
            self::fail('accepted_total', 'TOTAL_CHANGED');
        }

        $expires = now()->addSeconds($this->paymentWindow());
        $id = Records::id();
        DB::table('orders')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('order', 'CMD-', 7),
            'user_id' => $actor->id,
            'cart_id' => $cart->id,
            'quote_id' => $quote->id,
            'status' => 'awaiting_payment',
            'items_total' => $itemsTotal,
            'loyalty_checkout_quote_id' => $redemptionQuote?->id,
            'loyalty_discount' => (int) ($redemptionQuote->discount ?? 0),
            'delivery_total' => $deliveryTotal,
            'total' => $total,
            'currency' => 'XAF',
            'locale' => app()->getLocale(),
            // Terms of sale in force at this moment, kept with the order.
            'terms_version_id' => app(Cms::class)->currentLegal('terms_of_sale')?->id,
            'professional_quote_revision_id' => $professionalRevision?->id,
            'professional_billing_snapshot' => $professional ? Records::json($professional['buyer']) : null,
            'loyalty_snapshot' => ($loyalty = app(LoyaltyProgram::class)->capture($actor, $professional !== null))
                ? Records::json($loyalty)
                : null,
            'address_snapshot' => $quote->address_snapshot,
            'delivery_snapshot' => Records::json([
                'quote_id' => $quote->id,
                'grid_id' => $quote->grid_id,
                'zone_id' => $quote->zone_id,
                'breakdown' => $professional
                    ? $professional['delivery']['breakdown']
                    : json_decode($quote->breakdown, true),
                'gross_total' => $professional ? $deliveryTotal : (int) $quote->total,
                'recalculated_delivery_total' => (int) $quote->total,
                'promotion' => $promotion,
                'demo_amounts' => (bool) $quote->demo_amounts,
                'slot' => ['rule_id' => $quote->slot_rule_id, 'date' => $quote->slot_date],
            ]),
            'payment_expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        if ($promotion) {
            app(DeliveryOffers::class)->reserve($id, $actor->id, $promotion, $expires);
        }
        $plan = collect(json_decode($quote->plan, true)['lines']);
        // Stable offer order keeps the offer and stock locks in the same order as other writers.
        foreach ($plan->sortBy('offer_id') as $planned) {
            $line = $selected->get($planned['cart_line_id']);
            abort_unless($line && $line['quantity'] === $planned['quantity'], 409);
            $this->addLine($id, $line, $planned, $actor);
        }
        if ($redemptionQuote) {
            app(LoyaltyRedemptions::class)->reserve(Records::row('orders', $id, false), $redemptionQuote);
        }
        // The slot stays held for exactly as long as the payment window.
        DB::table('delivery_quotes')
            ->where('id', $quote->id)
            ->update(['expires_at' => $expires, 'version' => $quote->version + 1, 'updated_at' => now()]);
        $this->event($id, 'created', $actor, ['total' => (string) $total]);

        return $this->dto(Records::row('orders', $id, false));
    }

    private function addLine(string $orderId, array $line, array $planned, User $actor): void
    {
        $conditions = app(CartConditions::class)->current($line['offer_id'], true);
        if (isset($line['professional_snapshot'])) {
            abort_unless($conditions, 409);
            app(ProfessionalCart::class)->assertConforming($line['professional_snapshot']['conditions'], $conditions);
            $conditions['unit_price'] = $line['current_conditions']['unit_price'];
        } else {
            abort_unless($conditions && $conditions['unit_price'] === $line['current_conditions']['unit_price'], 409);
        }
        $terms = app(Inventory::class)->offerTerms($line['offer_id']);
        $lineId = Records::id();
        $discount = (int) ($line['loyalty_allocation']['discount'] ?? 0);
        $internal = $this->internal($terms, $conditions['unit_price'], $line['quantity']);
        if ($discount > 0) {
            if (! $internal['agreement']) {
                LoyaltyProgram::fail('points', 'REDEMPTION_COST_UNAVAILABLE');
            }
            $calculation = &$internal['agreement']['calculation'];
            $calculation['selling_total_before_loyalty'] = $calculation['selling_total'];
            $calculation['selling_total'] = (string) ((int) $calculation['selling_total'] - $discount);
            $calculation['gross_difference'] =
                (string) ((int) $calculation['selling_total'] - (int) $calculation['purchase_total']);
            $internal['agreement']['negative_margin'] = (int) $calculation['gross_difference'] < 0;
            $internal['loyalty_discount'] = (string) $discount;
            if ($internal['agreement']['negative_margin'] && ! $line['loyalty_policy_rules']['allow_negative_margin']) {
                LoyaltyProgram::fail('points', 'REDEMPTION_LOSS_NOT_APPROVED');
            }
            unset($calculation);
        }
        $reservation = null;
        if ($planned['from_warehouse'] > 0) {
            $reservation = app(Inventory::class)->reserve(
                [
                    'offer_id' => $line['offer_id'],
                    'purpose_id' => $lineId,
                    'quantity' => $planned['from_warehouse'],
                ],
                $actor,
            )['id'];
        }
        DB::table('order_lines')->insert([
            'id' => $lineId,
            'order_id' => $orderId,
            'cart_line_id' => $line['id'],
            'offer_id' => $line['offer_id'],
            'quantity' => $line['quantity'],
            'unit_price' => $conditions['unit_price'],
            'line_total' => Money::multiply($conditions['unit_price'], $line['quantity']) - $discount,
            'loyalty_discount' => $discount,
            'loyalty_points_used' => (int) ($line['loyalty_allocation']['points'] ?? 0),
            'snapshot' => Records::json([
                ...$conditions,
                'category_id' => $terms['product']->category_id,
                'name' => $line['professional_snapshot']['name'] ?? json_decode($terms['product']->name, true),
                'sku' => $line['professional_snapshot']['sku'] ?? $terms['variant']->sku,
                'shop' => $line['professional_snapshot']['shop'] ?? [
                    'id' => $terms['shop']->id,
                    'name' => $terms['shop']->name,
                    'slug' => $terms['shop']->slug,
                ],
            ]),
            'internal_snapshot' => Records::json($internal),
            'from_warehouse' => $planned['from_warehouse'],
            'from_supplier' => $planned['from_supplier'],
            'reservation_id' => $reservation,
            'supply_status' => $planned['from_supplier'] > 0 ? 'to_confirm' : 'reserved',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Reference price and the supplier rule in force at the time of sale, kept for internal margin views. */
    private function internal(array $terms, int $selling, int $quantity): array
    {
        try {
            $agreement = app(AgreementResolver::class)->quote(
                $terms['shop']->supplier_id,
                $terms['product']->id,
                $terms['product']->category_id,
                (int) $terms['offer']->reference_price,
                $selling,
                $quantity,
            );
        } catch (ValidationException) {
            $agreement = null;
        }

        return [
            'supplier_id' => $terms['shop']->supplier_id,
            'reference_price' => (int) $terms['offer']->reference_price,
            'agreement' => $agreement,
        ];
    }

    private function paymentWindow(): int
    {
        $setting = DB::table('business_settings')->where('key', 'inventory')->first();
        $ttl = $setting ? json_decode($setting->value, true)['reservation_ttl_seconds'] ?? null : null;
        if (! is_int($ttl) || $ttl < 30 || $ttl > 3600) {
            Money::fail('configuration', 'RESERVATION_POLICY_REQUIRED');
        }

        return $ttl;
    }

    public function owned(string $id, User $actor, bool $lock = false): object
    {
        $q = DB::table('orders')->where('id', $id)->where('user_id', $actor->id);
        $initial = $q->first();
        abort_unless($initial, 404);
        if ($initial->professional_quote_revision_id) {
            $profileId = DB::table('professional_quote_revisions AS r')
                ->join('professional_quotes AS q', 'q.id', '=', 'r.quote_id')
                ->where('r.id', $initial->professional_quote_revision_id)
                ->value('q.profile_id');
            // Company access is checked even through the normal order/payment routes. Profile lock comes first.
            app(BusinessProfiles::class)->authorized($profileId, $actor, $lock, false, $lock);
        }
        if ($lock) {
            $q->lockForUpdate();
        } else {
            return $initial;
        }
        $order = $q->first();
        abort_unless($order, 404);

        return $order;
    }

    public function event(string $orderId, string $action, ?User $actor, array $data = []): void
    {
        DB::table('order_events')->insert([
            'order_id' => $orderId,
            'action' => $action,
            'actor_id' => $actor?->id,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
    }

    /**
     * Unpaid orders past their window: reservations and slot are given back. A payment that arrives later is
     * still recorded and handled as a paid-after-expiry case, never ignored.
     */
    public function expire(object $order): void
    {
        if (
            $order->status !== 'awaiting_payment' ||
            CarbonImmutable::parse($order->payment_expires_at, 'UTC')->isFuture()
        ) {
            return;
        }
        $this->releaseHolds($order);
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['status' => 'expired', 'version' => $order->version + 1, 'updated_at' => now()]);
        $this->event($order->id, 'expired', null);
    }

    /** Stock reservations, delivery slot, delivery offer and loyalty points held by an unpaid order go back. */
    private function releaseHolds(object $order): void
    {
        foreach (
            DB::table('order_lines')
                ->where('order_id', $order->id)
                ->whereNotNull('reservation_id')
                ->orderBy('offer_id')
                ->get() as $line
        ) {
            app(Inventory::class)->release($line->reservation_id, null, true);
        }
        DB::table('delivery_quotes')
            ->where('id', $order->quote_id)
            ->where('slot_status', 'held')
            ->update(['slot_status' => 'released', 'updated_at' => now()]);
        app(DeliveryOffers::class)->release($order->id);
        app(LoyaltyRedemptions::class)->releaseOrder($order);
    }

    /**
     * "Change my order" for an order not paid yet: it is cancelled (everything it held goes back) and its items
     * return to the member's cart, so they can adjust it and order again. Refused while a payment is under way
     * (an attempt started in the last 30 minutes) or once anything was paid; a late payment on the cancelled order
     * is still recorded by the payment checks, never lost. Returns the items that could not go back to the cart.
     */
    public function reopen(object $order, object $cart): array
    {
        if ($order->status !== 'awaiting_payment') {
            Money::fail('status', 'ORDER_NOT_EDITABLE');
        }
        $running = DB::table('payment_attempts')
            ->where('order_id', $order->id)
            ->where(fn ($q) => $q
                ->whereIn('status', ['confirmed', 'to_verify'])
                ->orWhere(fn ($q) => $q->whereIn('status', ['starting', 'pending'])->where('created_at', '>', now()->subMinutes(30))))
            ->exists();
        if ($running) {
            Money::fail('payment', 'ORDER_PAYMENT_IN_PROGRESS');
        }
        $this->releaseHolds($order);
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['status' => 'cancelled', 'version' => $order->version + 1, 'updated_at' => now()]);
        $this->event($order->id, 'cancelled_by_customer_to_edit', null);
        $carts = app(Carts::class);
        $skipped = [];
        foreach (DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->get(['id', 'offer_id', 'quantity']) as $line) {
            try {
                $carts->add($cart, $line->offer_id, (int) $line->quantity);
            } catch (ValidationException) {
                $skipped[] = $line->id;
            }
        }
        $carts->bump($cart);

        return ['order_id' => $order->id, 'status' => 'cancelled', 'skipped_line_ids' => $skipped];
    }

    /** Customer view: what was bought and its payment state, without costs, agreements or stock locations. */
    private function lineImage(?string $offerId): ?string
    {
        $path = $offerId ? DB::table('offers')->where('id', $offerId)->value('image_path') : null;

        return app(\App\Domain\Media\MediaLibrary::class)->approvedPath($path) ? $path : null;
    }

    public function dto(object $order): array
    {
        $locale = app()->getLocale();
        $attempt = DB::table('payment_attempts')
            ->where('order_id', $order->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
        $address = json_decode($order->address_snapshot, true);
        $delivery = json_decode($order->delivery_snapshot, true);
        $slotRule =
            $delivery['slot']['rule_id'] ?? null
                ? DB::table('delivery_slot_rules')->where('id', $delivery['slot']['rule_id'])->first()
                : null;
        $payable =
            $order->status === 'awaiting_payment' &&
            CarbonImmutable::parse($order->payment_expires_at, 'UTC')->isFuture();

        return [
            'id' => $order->id,
            'number' => $order->number,
            'status' => $order->status,
            'currency' => $order->currency,
            'items_total' => (string) $order->items_total,
            'loyalty_discount' => (string) $order->loyalty_discount,
            'loyalty' => app(LoyaltyRedemptions::class)->view($order->id),
            'delivery_total' => (string) $order->delivery_total,
            'total' => (string) $order->total,
            'payment_expires_at' => Records::iso($order->payment_expires_at),
            'paid_at' => Records::iso($order->paid_at),
            'professional_quote_revision_id' => $order->professional_quote_revision_id,
            'closed_at' => Records::iso($order->closed_at),
            // Internal anomalies stay private: the customer only sees that preparation is under way.
            'preparation_status' => match ($order->preparation_status) {
                'not_started' => 'not_started',
                'ready' => 'ready',
                default => 'in_progress',
            },
            'payable' => $payable,
            'lines' => DB::table('order_lines')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(function ($line) use ($locale) {
                    $snapshot = json_decode($line->snapshot, true);

                    return [
                        'id' => $line->id,
                        'offer_id' => $line->offer_id,
                        'name' => $snapshot['name'][$locale] ?? $snapshot['name']['fr'],
                        'sku' => $snapshot['sku'],
                        'shop' => ['name' => $snapshot['shop']['name'], 'slug' => $snapshot['shop']['slug']],
                        // Current approved photo of the offer (for the order list), never a private file.
                        'image' => $this->lineImage($line->offer_id),
                        'warranty' => $snapshot['warranty'][$locale] ?? $snapshot['warranty']['fr'],
                        'quantity' => (int) $line->quantity,
                        'unit_price' => (string) $line->unit_price,
                        'line_total' => (string) $line->line_total,
                        'loyalty_discount' => (string) $line->loyalty_discount,
                        'loyalty_points_used' => (string) $line->loyalty_points_used,
                        ...app(OrderSupply::class)->customerState($line),
                        'cancelled_quantity' => (int) $line->cancelled_quantity,
                        'procurement_received_quantity' => app(OrderPurchases::class)->receivedQuantity($line->id),
                        'prepared_quantity' => app(OrderPreparation::class)->picked($line->id),
                    ];
                }),
            'supply_cases' => DB::table('order_supply_cases')
                ->where('order_id', $order->id)
                ->orderBy('order_line_id')
                ->orderBy('sequence')
                ->get()
                ->map(fn ($case) => app(OrderSupply::class)->caseDto($case)),
            'delivery' => [
                'breakdown' => array_map(
                    fn ($item) => [...$item, 'amount' => (string) $item['amount']],
                    $delivery['breakdown'],
                ),
                'promotion' => empty($delivery['promotion'])
                    ? null
                    : [
                        'name' => $delivery['promotion']['name'][$locale] ?? $delivery['promotion']['name']['fr'],
                        'coverage' => $delivery['promotion']['coverage'],
                        'discount' => (string) $delivery['promotion']['discount'],
                        'min_unit_price' => $delivery['promotion']['min_unit_price'],
                        'threshold_basis' => $delivery['promotion']['threshold_basis'],
                    ],
                'demo_amounts' => $delivery['demo_amounts'],
                'address' => [
                    'label' => $address['label'],
                    'district' => $address['district'],
                    'landmark' => $address['landmark'],
                ],
                'slot' => $slotRule
                    ? [
                        'date' => $delivery['slot']['date'],
                        'starts_at' => $slotRule->starts_at,
                        'ends_at' => $slotRule->ends_at,
                    ]
                    : null,
                // Mission reference, courier's public identity and the customer's own one-time handover code.
                'mission' => app(DeliveryMissions::class)->customerView($order),
            ],
            'payment' => $attempt
                ? [
                    'attempt_id' => $attempt->id,
                    'status' => $attempt->status,
                    // The payment page is only offered while the attempt can still be used.
                    'payment_url' => $attempt->status === 'pending' && $payable ? $attempt->payment_url : null,
                ]
                : null,
            // A requested refund is announced as in progress; only a verified transfer is shown as paid.
            'refunds' => DB::table('customer_refunds')
                ->where('order_id', $order->id)
                ->whereIn('status', ['requested', 'paid'])
                ->orderBy('created_at')
                ->get()
                ->map(
                    fn ($r) => [
                        'amount' => (string) $r->amount,
                        'status' => $r->status,
                        'paid_at' => Records::iso($r->paid_at),
                    ],
                ),
            'created_at' => Records::iso($order->created_at),
        ];
    }
}
