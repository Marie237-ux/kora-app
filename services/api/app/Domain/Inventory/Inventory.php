<?php

namespace App\Domain\Inventory;

use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Inventory
{
    public function offerTerms(string $id, bool $published = true): array
    {
        $offer = Records::row('offers', $id);
        $shop = Records::row('shops', $offer->shop_id, false);
        $variant = Records::row('variants', $offer->variant_id, false);
        $product = Records::row('products', $variant->product_id, false);
        if (
            $published &&
            ($offer->status !== 'published' ||
                ! $shop->published ||
                ! $variant->active ||
                $product->status !== 'published')
        ) {
            Money::fail('offer_id', 'OFFER_UNAVAILABLE');
        }
        $terms = [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'supplier_id' => $shop->supplier_id,
            'variant_id' => $variant->id,
            'sku' => $variant->sku,
            'attributes' => json_decode($variant->attributes, true),
            'warranty' => json_decode($offer->warranty, true),
        ];

        return [
            'offer' => $offer,
            'shop' => $shop,
            'variant' => $variant,
            'product' => $product,
            'terms' => $terms,
            'hash' => app(CommandBus::class)->fingerprint($terms),
        ];
    }

    public function location(string $id): object
    {
        $location = Records::row('stock_locations', $id, false);
        $warehouse = Records::row('warehouses', $location->warehouse_id, false);
        if (! $location->active || ! $warehouse->active) {
            Money::fail('location_id', 'LOCATION_INACTIVE');
        }

        return $location;
    }

    public function receive(
        object $line,
        string $receiptLineId,
        string $locationId,
        array $input,
        string $mode,
        User $actor,
    ): string {
        $snapshot = json_decode($line->commercial_snapshot, true);
        $tracking = $line->tracking_mode;
        $identifiers = $input['identifiers'] ?? [];
        if (
            ($tracking === 'lot' && empty($input['lot_number'])) ||
            ($line->requires_expiry && empty($input['expires_on']))
        ) {
            Money::fail('lot', 'TRACKING_REQUIRED');
        }
        if (in_array($tracking, ['serial', 'imei'], true)) {
            if (
                count($identifiers) !== $input['quantity'] ||
                count(array_unique($identifiers)) !== count($identifiers)
            ) {
                Money::fail('identifiers', 'TRACKING_REQUIRED');
            }
            foreach ($identifiers as $identifier) {
                if ($tracking === 'imei' && ! preg_match('/^[0-9]{15}$/', $identifier)) {
                    Money::fail('identifiers', 'TRACKING_REQUIRED');
                }
            }
        } elseif ($identifiers) {
            Money::fail('identifiers', 'TRACKING_REQUIRED');
        }
        if (
            ! empty($input['expires_on']) &&
            $input['expires_on'] <= now('Africa/Douala')->toDateString() &&
            $input['quality'] === 'available'
        ) {
            Money::fail('expires_on', 'EXPIRED_LOT');
        }
        $id = Records::id();
        DB::table('stock_lots')->insert([
            'id' => $id,
            'receipt_line_id' => $receiptLineId,
            'purchase_line_id' => $line->id,
            'offer_id' => $line->offer_id,
            'supplier_id' => $snapshot['terms']['supplier_id'],
            'variant_id' => $line->variant_id,
            'ownership' => $mode === 'consignment' ? 'supplier' : 'kora',
            'lot_number' => $input['lot_number'] ?? null,
            'expires_on' => $input['expires_on'] ?? null,
            'unit_cost' => $input['unit_cost'],
            'terms_hash' => $line->terms_hash,
            'terms_snapshot' => Records::json($snapshot),
            'created_at' => now(),
        ]);
        $balance = $this->balance($id, $locationId, $input['quality']);
        DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->update(['on_hand' => $input['quantity'], 'updated_at' => now()]);
        $units = [];
        foreach ($identifiers as $identifier) {
            $unitId = Records::id();
            $units[] = $unitId;
            DB::table('inventory_units')->insert([
                'id' => $unitId,
                'lot_id' => $id,
                'variant_id' => $line->variant_id,
                'balance_id' => $balance->id,
                'identifier_type' => $tracking,
                'identifier' => $identifier,
                'status' => 'on_hand',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->movement(
            $balance->id,
            $input['quantity'],
            'receipt',
            'receipt_line',
            $receiptLineId,
            $input['variance_reason'] ?? 'receipt',
            $units,
            $actor,
            $input['document_id'],
        );

        return $id;
    }

    public function balance(string $lotId, string $locationId, string $condition): object
    {
        DB::table('inventory_balances')->insertOrIgnore([
            'id' => Records::id(),
            'lot_id' => $lotId,
            'location_id' => $locationId,
            'condition' => $condition,
            'on_hand' => 0,
            'reserved' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('inventory_balances')
            ->where('lot_id', $lotId)
            ->where('location_id', $locationId)
            ->where('condition', $condition)
            ->lockForUpdate()
            ->first();
    }

    public function reserve(array $data, User $actor): array
    {
        $context = $this->offerTerms($data['offer_id']);
        $setting = DB::table('business_settings')->where('key', 'inventory')->first();
        $ttl = $setting ? json_decode($setting->value, true)['reservation_ttl_seconds'] ?? null : null;
        if (! is_int($ttl) || $ttl < 30 || $ttl > 3600) {
            Money::fail('configuration', 'RESERVATION_POLICY_REQUIRED');
        }
        if ($data['quantity'] < $context['offer']->minimum_quantity) {
            Money::fail('quantity', 'QUANTITY_INVALID');
        }
        $this->expireOffer($data['offer_id'], $actor);
        if (
            DB::table('inventory_reservations')
                ->where('offer_id', $data['offer_id'])
                ->where('purpose_id', $data['purpose_id'])
                ->exists()
        ) {
            Money::fail('purpose_id', 'PURPOSE_ALREADY_RESERVED');
        }

        return $this->reserveBalances($data, $actor, $this->eligible($data['offer_id'], $context['hash']), $ttl);
    }

    /** Internal receipt allocation: the order service has checked the frozen commercial terms. */
    public function reserveReceipt(
        string $offerId,
        string $purposeId,
        int $quantity,
        string $hash,
        string $receiptLineId,
        string $orderLineId,
        User $actor,
    ): array {
        Records::row('offers', $offerId);
        $balances = $this->eligible($offerId, $hash)->where('stock_lots.receipt_line_id', $receiptLineId);

        return $this->reserveBalances(
            ['offer_id' => $offerId, 'purpose_id' => $purposeId, 'quantity' => $quantity],
            $actor,
            $balances,
            600,
            $orderLineId,
        );
    }

    private function reserveBalances(array $data, User $actor, $query, int $ttl, ?string $orderLineId = null): array
    {
        $balances = $query->lockForUpdate()->get();
        $available = $balances->sum(fn ($b) => $b->on_hand - $b->reserved);
        if ($available < $data['quantity']) {
            Money::fail('quantity', 'STOCK_INSUFFICIENT');
        }
        $id = Records::id();
        $remaining = $data['quantity'];
        $expires = CarbonImmutable::now('UTC')->addSeconds($ttl);
        DB::table('inventory_reservations')->insert([
            'id' => $id,
            'offer_id' => $data['offer_id'],
            'purpose_id' => $data['purpose_id'],
            'quantity' => $data['quantity'],
            'status' => 'active',
            'expires_at' => $expires,
            'order_line_id' => $orderLineId,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($balances as $balance) {
            $quantity = min($remaining, $balance->on_hand - $balance->reserved);
            if ($quantity < 1) {
                continue;
            }
            DB::table('inventory_balances')
                ->where('id', $balance->id)
                ->update(['reserved' => $balance->reserved + $quantity, 'updated_at' => now()]);
            DB::table('inventory_reservation_lines')->insert([
                'id' => Records::id(),
                'reservation_id' => $id,
                'balance_id' => $balance->id,
                'quantity' => $quantity,
            ]);
            if ($this->isSerialized($balance->lot_id)) {
                $units = DB::table('inventory_units')
                    ->where('balance_id', $balance->id)
                    ->where('status', 'on_hand')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->limit($quantity)
                    ->get();
                if ($units->count() !== $quantity) {
                    Money::fail('units', 'STOCK_INCONSISTENT');
                }
                DB::table('inventory_units')
                    ->whereIn('id', $units->pluck('id'))
                    ->update(['status' => 'reserved', 'reservation_id' => $id, 'updated_at' => now()]);
            }
            $remaining -= $quantity;
            if ($remaining === 0) {
                break;
            }
        }
        Records::audit($actor, 'inventory.reserved', $id, ['quantity' => $data['quantity']]);
        Records::event('InventoryReserved', $id, ['id' => $id]);

        return $this->reservationDto(Records::row('inventory_reservations', $id));
    }

    public function release(string $id, ?User $actor, bool $expired = false, bool $orderWorkflow = false): array
    {
        $row = Records::row('inventory_reservations', $id);
        Records::row('offers', $row->offer_id);
        if ($row->status !== 'active') {
            return $this->reservationDto($row);
        }
        if ($expired && ($row->order_line_id || CarbonImmutable::parse($row->expires_at, 'UTC')->isFuture())) {
            return $this->reservationDto($row);
        }
        if ($row->order_line_id && ! $orderWorkflow) {
            Money::fail('reservation', 'ORDER_RESERVATION_PROTECTED');
        }
        foreach (
            DB::table('inventory_reservation_lines')->where('reservation_id', $id)->orderBy('balance_id')->get() as $line
        ) {
            $balance = Records::row('inventory_balances', $line->balance_id);
            if ($balance->reserved < $line->quantity) {
                Money::fail('stock', 'STOCK_INCONSISTENT');
            }
            DB::table('inventory_balances')
                ->where('id', $balance->id)
                ->update(['reserved' => $balance->reserved - $line->quantity, 'updated_at' => now()]);
        }
        DB::table('inventory_units')
            ->where('reservation_id', $id)
            ->where('status', 'reserved')
            ->update(['status' => 'on_hand', 'reservation_id' => null, 'updated_at' => now()]);
        DB::table('inventory_reservations')
            ->where('id', $id)
            ->update(['status' => $expired ? 'expired' : 'released', 'updated_at' => now()]);
        Records::audit($actor, $expired ? 'inventory.expired' : 'inventory.released', $id);

        return $this->reservationDto(Records::row('inventory_reservations', $id));
    }

    public function consume(string $id, string $saleReference, User $actor, ?string $documentId = null): array
    {
        $row = Records::row('inventory_reservations', $id);
        Records::row('offers', $row->offer_id);
        if (
            $row->status !== 'active' ||
            (! $row->order_line_id && CarbonImmutable::parse($row->expires_at, 'UTC')->lessThanOrEqualTo(now()))
        ) {
            Money::fail('reservation', 'RESERVATION_EXPIRED');
        }
        foreach (
            DB::table('inventory_reservation_lines')->where('reservation_id', $id)->orderBy('balance_id')->get() as $line
        ) {
            $balance = Records::row('inventory_balances', $line->balance_id);
            $lot = Records::row('stock_lots', $balance->lot_id, false);
            if (
                $balance->reserved < $line->quantity ||
                $balance->on_hand < $line->quantity ||
                ($lot->expires_on && $lot->expires_on <= now('Africa/Douala')->toDateString())
            ) {
                Money::fail('stock', 'STOCK_INCONSISTENT');
            }
            DB::table('inventory_balances')
                ->where('id', $balance->id)
                ->update([
                    'reserved' => $balance->reserved - $line->quantity,
                    'on_hand' => $balance->on_hand - $line->quantity,
                    'updated_at' => now(),
                ]);
            $units = DB::table('inventory_units')
                ->where('reservation_id', $id)
                ->where('balance_id', $balance->id)
                ->pluck('id')
                ->all();
            DB::table('inventory_units')
                ->whereIn('id', $units)
                ->update(['status' => 'consumed', 'balance_id' => null, 'updated_at' => now()]);
            $this->movement(
                $balance->id,
                -$line->quantity,
                'sale_issue',
                'reservation',
                $id,
                $saleReference,
                $units,
                $actor,
                $documentId,
            );
            if ($lot->ownership === 'supplier') {
                // A lot may have balances in several locations; one sale row aggregates this reservation.
                $sale = DB::table('consignment_sales')
                    ->where('lot_id', $lot->id)
                    ->where('reservation_id', $id)
                    ->first();
                if ($sale) {
                    DB::table('consignment_sales')->where('id', $sale->id)->increment('quantity', $line->quantity);
                } else {
                    DB::table('consignment_sales')->insert([
                        'id' => Records::id(),
                        'lot_id' => $lot->id,
                        'reservation_id' => $id,
                        'quantity' => $line->quantity,
                        'unit_cost' => $lot->unit_cost,
                        'sold_at' => now(),
                    ]);
                }
            }
        }
        DB::table('inventory_reservations')
            ->where('id', $id)
            ->update(['status' => 'consumed', 'updated_at' => now()]);
        Records::audit($actor, 'inventory.consumed', $id, ['sale_reference' => $saleReference]);
        Records::event('InventoryConsumed', $id, ['id' => $id]);

        return $this->reservationDto(Records::row('inventory_reservations', $id));
    }

    public function move(array $data, string $originId, User $actor): array
    {
        $source = Records::row('inventory_balances', $data['balance_id']);
        $lot = Records::row('stock_lots', $source->lot_id, false);
        Records::row('offers', $lot->offer_id);
        $this->location($data['location_id']);
        if ($source->location_id === $data['location_id'] && $source->condition === $data['condition']) {
            Money::fail('location_id', 'MOVEMENT_INVALID');
        }
        if (
            $data['condition'] === 'available' &&
            $lot->expires_on &&
            $lot->expires_on <= now('Africa/Douala')->toDateString()
        ) {
            Money::fail('expires_on', 'EXPIRED_LOT');
        }
        $receiptLine = Records::row('receipt_lines', $lot->receipt_line_id, false);
        if (
            $data['condition'] === 'available' &&
            $receiptLine->excess_quantity > 0 &&
            ! DB::table('receipt_overage_approvals')->where('receipt_line_id', $receiptLine->id)->exists()
        ) {
            Money::fail('condition', 'OVERAGE_APPROVAL_REQUIRED');
        }
        $units = $this->takeUnits($source, $data['quantity'], $data['unit_ids'] ?? []);
        if ($source->on_hand - $source->reserved < $data['quantity']) {
            Money::fail('quantity', 'STOCK_INSUFFICIENT');
        }
        $target = $this->balance($lot->id, $data['location_id'], $data['condition']);
        if ($target->on_hand + $data['quantity'] > 1000000000) {
            Money::fail('quantity', 'QUANTITY_INVALID');
        }
        DB::table('inventory_balances')
            ->where('id', $source->id)
            ->update(['on_hand' => $source->on_hand - $data['quantity'], 'updated_at' => now()]);
        DB::table('inventory_balances')
            ->where('id', $target->id)
            ->update(['on_hand' => $target->on_hand + $data['quantity'], 'updated_at' => now()]);
        DB::table('inventory_units')
            ->whereIn('id', $units)
            ->update(['balance_id' => $target->id, 'updated_at' => now()]);
        $this->movement(
            $source->id,
            -$data['quantity'],
            'transfer_out',
            'command',
            $originId,
            $data['reason'],
            $units,
            $actor,
            $data['document_id'],
        );
        $this->movement(
            $target->id,
            $data['quantity'],
            'transfer_in',
            'command',
            $originId,
            $data['reason'],
            $units,
            $actor,
            $data['document_id'],
        );
        Records::audit($actor, 'inventory.transferred', $originId);

        return [
            'source' => $this->balanceDto(Records::row('inventory_balances', $source->id)),
            'target' => $this->balanceDto(Records::row('inventory_balances', $target->id)),
        ];
    }

    public function adjust(array $data, string $originId, User $actor): array
    {
        $balance = Records::row('inventory_balances', $data['balance_id']);
        if ($data['delta'] === 0 || $this->isSerialized($balance->lot_id)) {
            Money::fail('delta', 'MOVEMENT_INVALID');
        }
        $next = $balance->on_hand + $data['delta'];
        if ($next < $balance->reserved || $next > 1000000000) {
            Money::fail('delta', 'STOCK_INSUFFICIENT');
        }
        DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->update(['on_hand' => $next, 'updated_at' => now()]);
        $this->movement(
            $balance->id,
            $data['delta'],
            'adjustment',
            'command',
            $originId,
            $data['reason'],
            [],
            $actor,
            $data['document_id'],
        );
        Records::audit($actor, 'inventory.adjusted', $originId, [
            'balance_id' => $balance->id,
            'delta' => $data['delta'],
        ]);

        return $this->balanceDto(Records::row('inventory_balances', $balance->id));
    }

    public function returnToSupplier(array $data, User $actor): array
    {
        $balance = Records::row('inventory_balances', $data['balance_id']);
        $lot = Records::row('stock_lots', $balance->lot_id, false);
        $units = $this->takeUnits($balance, $data['quantity'], $data['unit_ids'] ?? []);
        if ($balance->on_hand - $balance->reserved < $data['quantity']) {
            Money::fail('quantity', 'STOCK_INSUFFICIENT');
        }
        $id = Records::id();
        DB::table('supplier_returns')->insert([
            'id' => $id,
            'lot_id' => $lot->id,
            'balance_id' => $balance->id,
            'quantity' => $data['quantity'],
            'document_id' => $data['document_id'],
            'reason' => $data['reason'],
            'supplier_reference' => $data['supplier_reference'],
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->update(['on_hand' => $balance->on_hand - $data['quantity'], 'updated_at' => now()]);
        DB::table('inventory_units')
            ->whereIn('id', $units)
            ->update(['status' => 'returned_to_supplier', 'balance_id' => null, 'updated_at' => now()]);
        $this->movement(
            $balance->id,
            -$data['quantity'],
            'supplier_return',
            'supplier_return',
            $id,
            $data['reason'],
            $units,
            $actor,
            $data['document_id'],
        );
        Records::audit($actor, 'inventory.supplier_return', $id);

        return ['id' => $id, 'lot_id' => $lot->id, 'quantity' => $data['quantity']];
    }

    /**
     * The customer's own unit leaves stock again after its repair: issued from the balance it waited in,
     * by exact serials when tracked. It was never KORA's to sell, so no reservation or sale is involved.
     */
    public function returnRepaired(
        string $balanceId,
        int $quantity,
        array $identifiers,
        string $originId,
        string $reason,
        User $actor,
    ): void {
        $balance = Records::row('inventory_balances', $balanceId);
        $units = $identifiers
            ? DB::table('inventory_units')
                ->where('balance_id', $balance->id)
                ->whereIn('identifier', $identifiers)
                ->where('status', 'on_hand')
                ->lockForUpdate()
                ->pluck('id')
                ->all()
            : [];
        $this->takeUnits($balance, $quantity, $units);
        if ($balance->on_hand - $balance->reserved < $quantity) {
            Money::fail('quantity', 'STOCK_INSUFFICIENT');
        }
        DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->update(['on_hand' => $balance->on_hand - $quantity, 'updated_at' => now()]);
        DB::table('inventory_units')
            ->whereIn('id', $units)
            ->update(['status' => 'consumed', 'balance_id' => null, 'updated_at' => now()]);
        $this->movement($balance->id, -$quantity, 'repair_return', 'after_sales', $originId, $reason, $units, $actor);
        Records::audit($actor, 'inventory.repair_return', $originId, [
            'balance_id' => $balance->id,
            'quantity' => $quantity,
        ]);
    }

    public function count(array $data, string $originId, User $actor): array
    {
        $balance = Records::row('inventory_balances', $data['balance_id']);
        if ($data['counted_quantity'] < $balance->reserved) {
            Money::fail('counted_quantity', 'STOCK_INSUFFICIENT');
        }
        $missing = [];
        $recovered = [];
        $observed = $data['observed_unit_ids'];
        if ($this->isSerialized($balance->lot_id)) {
            if (count($observed) !== $data['counted_quantity'] || count(array_unique($observed)) !== count($observed)) {
                Money::fail('observed_unit_ids', 'TRACKING_REQUIRED');
            }
            $current = DB::table('inventory_units')
                ->where('balance_id', $balance->id)
                ->whereIn('status', ['on_hand', 'reserved'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($current as $unit) {
                if (in_array($unit->id, $observed, true)) {
                    continue;
                }
                if ($unit->status === 'reserved') {
                    Money::fail('observed_unit_ids', 'STOCK_INSUFFICIENT');
                }
                $missing[] = $unit->id;
            }
            foreach ($observed as $id) {
                if ($current->has($id)) {
                    continue;
                }
                $unit = Records::row('inventory_units', $id);
                if ($unit->lot_id !== $balance->lot_id || $unit->status !== 'missing' || $unit->balance_id !== null) {
                    Money::fail('observed_unit_ids', 'TRACKING_REQUIRED');
                }
                $recovered[] = $id;
            }
            DB::table('inventory_units')
                ->whereIn('id', $missing)
                ->update(['status' => 'missing', 'balance_id' => null, 'updated_at' => now()]);
            DB::table('inventory_units')
                ->whereIn('id', $recovered)
                ->update([
                    'status' => 'on_hand',
                    'balance_id' => $balance->id,
                    'reservation_id' => null,
                    'updated_at' => now(),
                ]);
        } elseif ($observed) {
            Money::fail('observed_unit_ids', 'TRACKING_REQUIRED');
        }
        $delta = $data['counted_quantity'] - $balance->on_hand;
        DB::table('inventory_balances')
            ->where('id', $balance->id)
            ->update(['on_hand' => $data['counted_quantity'], 'updated_at' => now()]);
        $this->movement(
            $balance->id,
            $delta,
            'inventory_count',
            'command',
            $originId,
            $data['reason'],
            array_merge($missing, $recovered),
            $actor,
            $data['document_id'],
        );
        Records::audit($actor, 'inventory.counted', $originId, [
            'balance_id' => $balance->id,
            'counted' => $data['counted_quantity'],
            'missing_units' => $missing,
            'recovered_units' => $recovered,
            'document_id' => $data['document_id'],
        ]);

        return $this->balanceDto(Records::row('inventory_balances', $balance->id));
    }

    public function plan(string $offerId, int $quantity, User $actor): array
    {
        $context = $this->offerTerms($offerId);
        $balances = $this->eligible($offerId, $context['hash'])->get();
        $remaining = $quantity;
        $sources = [];
        foreach ($balances as $balance) {
            $take = min($remaining, $balance->on_hand - $balance->reserved);
            if ($take > 0) {
                $sources[] = [
                    'kind' => 'warehouse',
                    'balance_id' => $balance->id,
                    'location_id' => $balance->location_id,
                    'quantity' => $take,
                ];
                $remaining -= $take;
            }
            if ($remaining === 0) {
                break;
            }
        }
        $supplier = DB::table('supplier_availability')->where('offer_id', $offerId)->first();
        $fresh = $supplier && CarbonImmutable::parse($supplier->valid_until, 'UTC')->isFuture();
        $held = DB::table('supplier_reservations')
            ->where('offer_id', $offerId)
            ->whereIn('status', ['requested', 'confirmed', 'partly_confirmed', 'release_requested'])
            ->where('expires_at', '>', now())
            ->selectRaw(
                "COALESCE(SUM(CASE WHEN status = 'partly_confirmed' THEN confirmed_quantity ELSE quantity END), 0) AS held",
            )
            ->value('held');

        return [
            'offer_id' => $offerId,
            'requested' => $quantity,
            'warehouse_sources' => $sources,
            'supplier_quantity_needed' => $remaining,
            'supplier_quantity_reported' => $fresh && $supplier->quantity !== null ? max(0, $supplier->quantity - $held) : null,
            'supplier_last_verified_at' => $supplier ? Records::iso($supplier->verified_at) : null,
            'supplier_confirmation_required' => $remaining > 0,
            'preparation_minutes' => $fresh ? (int) $supplier->preparation_minutes : null,
        ];
    }

    public function expireOffer(string $offerId, User $actor): void
    {
        foreach (
            DB::table('inventory_reservations')
                ->where('offer_id', $offerId)
                ->where('status', 'active')
                ->whereNull('order_line_id')
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->pluck('id') as $id
        ) {
            $this->release($id, $actor, true);
        }
    }

    private function eligible(string $offerId, string $hash)
    {
        return DB::table('inventory_balances')
            ->join('stock_lots', 'stock_lots.id', '=', 'inventory_balances.lot_id')
            ->join('stock_locations', 'stock_locations.id', '=', 'inventory_balances.location_id')
            ->join('warehouses', 'warehouses.id', '=', 'stock_locations.warehouse_id')
            ->where('stock_lots.offer_id', $offerId)
            ->where('stock_lots.terms_hash', $hash)
            ->where('inventory_balances.condition', 'available')
            ->where('stock_locations.active', true)
            ->where('warehouses.active', true)
            ->where(
                fn ($q) => $q
                    ->whereNull('stock_lots.expires_on')
                    ->orWhere('stock_lots.expires_on', '>', now('Africa/Douala')->toDateString()),
            )
            ->select('inventory_balances.*')
            ->orderByRaw('CASE WHEN stock_lots.expires_on IS NULL THEN 1 ELSE 0 END')
            ->orderBy('stock_lots.expires_on')
            ->orderBy('stock_lots.created_at')
            ->orderBy('inventory_balances.id');
    }

    private function isSerialized(string $lotId): bool
    {
        $lot = Records::row('stock_lots', $lotId, false);

        return in_array(
            Records::row('purchase_lines', $lot->purchase_line_id, false)->tracking_mode,
            ['serial', 'imei'],
            true,
        );
    }

    /**
     * Units a customer brings back under an after-sales case: they re-enter stock in the "returned" condition at
     * the chosen location, in the lot they were sold from, and cannot be sold before a control moves them to
     * available.
     */
    public function customerReturn(
        array $lotQuantities,
        array $unitIds,
        string $locationId,
        string $originId,
        string $reason,
        User $actor,
    ): array {
        return $this->returnIssued(
            $lotQuantities,
            $unitIds,
            $locationId,
            'returned',
            'customer_return',
            'after_sales',
            $originId,
            $reason,
            $actor,
        );
    }

    /**
     * Issued units coming back into stock, set aside in a non-sellable condition, in the lot they left from.
     * Serialised units are taken by their exact identifiers. Supplier-owned (consignment) lots are refused:
     * their supplier statement would have to be corrected first.
     */
    public function returnIssued(
        array $lotQuantities,
        array $unitIds,
        string $locationId,
        string $condition,
        string $kind,
        string $originType,
        string $originId,
        string $reason,
        User $actor,
    ): array {
        $this->location($locationId);
        $balances = [];
        ksort($lotQuantities);
        foreach ($lotQuantities as $lotId => $quantity) {
            $lot = Records::row('stock_lots', $lotId, false);
            if ($lot->ownership === 'supplier') {
                Money::fail('lines', 'CONSIGNMENT_RETURN');
            }
            Records::row('offers', $lot->offer_id);
            $balance = $this->balance($lot->id, $locationId, $condition);
            $units = DB::table('inventory_units')
                ->whereIn('id', $unitIds)
                ->where('lot_id', $lot->id)
                ->where('status', 'consumed')
                ->lockForUpdate()
                ->pluck('id')
                ->all();
            if ($this->isSerialized($lot->id) && count($units) !== $quantity) {
                Money::fail('identifiers', 'TRACKING_REQUIRED');
            }
            DB::table('inventory_balances')
                ->where('id', $balance->id)
                ->update(['on_hand' => $balance->on_hand + $quantity, 'updated_at' => now()]);
            DB::table('inventory_units')
                ->whereIn('id', $units)
                ->update([
                    'status' => 'on_hand',
                    'balance_id' => $balance->id,
                    'reservation_id' => null,
                    'updated_at' => now(),
                ]);
            $this->movement($balance->id, $quantity, $kind, $originType, $originId, $reason, $units, $actor);
            $balances[] = ['balance_id' => $balance->id, 'quantity' => $quantity, 'unit_ids' => $units];
        }
        Records::audit($actor, 'inventory.'.$kind, $originId, ['balances' => $balances]);

        return $balances;
    }

    /**
     * Holds a replacement for a paid line, from available stock sold under the same terms as the unit it
     * replaces. No cart window: the reservation belongs to the paid line until it is issued.
     */
    public function reserveReplacement(
        string $offerId,
        string $termsHash,
        int $quantity,
        string $purposeId,
        ?string $orderLineId,
        User $actor,
    ): array {
        Records::row('offers', $offerId);
        $reservation = $this->reserveBalances(
            ['offer_id' => $offerId, 'purpose_id' => $purposeId, 'quantity' => $quantity],
            $actor,
            $this->eligible($offerId, $termsHash),
            3600,
            $orderLineId,
        );

        return $reservation;
    }

    private function takeUnits(object $balance, int $quantity, array $ids): array
    {
        if (! $this->isSerialized($balance->lot_id)) {
            if ($ids) {
                Money::fail('unit_ids', 'TRACKING_REQUIRED');
            }

            return [];
        }
        $units = DB::table('inventory_units')
            ->whereIn('id', $ids)
            ->where('balance_id', $balance->id)
            ->where('status', 'on_hand')
            ->lockForUpdate()
            ->get();
        if (count($ids) !== $quantity || count(array_unique($ids)) !== $quantity || $units->count() !== $quantity) {
            Money::fail('unit_ids', 'TRACKING_REQUIRED');
        }

        return $units->pluck('id')->all();
    }

    private function movement(
        string $balanceId,
        int $quantity,
        string $kind,
        string $origin,
        string $originId,
        string $reason,
        array $units,
        User $actor,
        ?string $documentId = null,
    ): void {
        DB::table('inventory_movements')->insert([
            'id' => Records::id(),
            'balance_id' => $balanceId,
            'quantity' => $quantity,
            'kind' => $kind,
            'origin_type' => $origin,
            'origin_id' => $originId,
            'reason' => $reason,
            'units' => Records::json($units),
            'document_id' => $documentId,
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }

    public function balanceDto(object $row): array
    {
        $lot = Records::row('stock_lots', $row->lot_id, false);
        $expired = $lot->expires_on && $lot->expires_on <= now('Africa/Douala')->toDateString();

        return [
            'id' => $row->id,
            'lot_id' => $row->lot_id,
            'location_id' => $row->location_id,
            'condition' => $row->condition,
            'offer_id' => $lot->offer_id,
            'ownership' => $lot->ownership,
            'expires_on' => $lot->expires_on,
            'on_hand' => (int) $row->on_hand,
            'reserved' => (int) $row->reserved,
            'available' => $row->condition === 'available' && ! $expired ? $row->on_hand - $row->reserved : 0,
        ];
    }

    public function reservationDto(object $row): array
    {
        return [
            'id' => $row->id,
            'offer_id' => $row->offer_id,
            'purpose_id' => $row->purpose_id,
            'quantity' => (int) $row->quantity,
            'status' => $row->status,
            'expires_at' => $row->order_line_id ? null : Records::iso($row->expires_at),
            'order_line_id' => $row->order_line_id,
        ];
    }

    /** Called only by payment confirmation, with the order locked and its payment verified. */
    public function retainForPaidLine(object $line): bool
    {
        if (! $line->reservation_id) {
            return false;
        }
        $reservation = Records::row('inventory_reservations', $line->reservation_id);
        if (
            $reservation->status !== 'active' ||
            (! $reservation->order_line_id &&
                CarbonImmutable::parse($reservation->expires_at, 'UTC')->lessThanOrEqualTo(now()))
        ) {
            return false;
        }
        abort_unless(
            $reservation->offer_id === $line->offer_id &&
                (int) $reservation->quantity === (int) $line->from_warehouse &&
                (! $reservation->order_line_id || $reservation->order_line_id === $line->id),
            409,
        );
        if ($reservation->order_line_id === $line->id) {
            return true;
        }
        DB::table('inventory_reservations')
            ->where('id', $reservation->id)
            ->update(['order_line_id' => $line->id, 'updated_at' => now()]);
        Records::audit(null, 'inventory.retained_for_paid_order', $reservation->id, ['order_line_id' => $line->id]);

        return true;
    }
}
