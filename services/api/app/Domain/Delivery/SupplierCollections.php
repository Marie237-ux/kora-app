<?php

namespace App\Domain\Delivery;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Orders\OrderPreparation;
use App\Domain\Orders\OrderPurchases;
use App\Domain\Procurement\Purchasing;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A courier collects confirmed purchase units at a supplier shop and drops them at a KORA depot. Quantities are
 * counted at the shop; the depot's stock team then receives them with evidence through the usual receipt, which
 * this collection only links to. A collection never creates stock, debt or payment by itself.
 */
final class SupplierCollections
{
    public const INCIDENTS = ['shop_closed', 'goods_not_ready', 'goods_mismatch', 'vehicle_unavailable', 'other'];

    private const LIVE = ['unassigned', 'assigned', 'accepted', 'collected', 'incident', 'dropped'];

    /** Lock order: supplier → purchase order → collection, as the purchase receipt writers. */
    public function create(string $purchaseId, array $data, User $actor): array
    {
        $initial = Records::row('purchase_orders', $purchaseId, false);
        Records::supplier($initial->supplier_id);
        $purchase = Records::row('purchase_orders', $purchaseId);
        if ($purchase->status !== 'confirmed') {
            DeliveryMissions::fail('purchase', 'PURCHASE_NOT_CONFIRMED');
        }
        $warehouse = DB::table('warehouses')->where('id', $data['warehouse_id'])->where('active', true)->first();
        if (! $warehouse) {
            DeliveryMissions::fail('warehouse_id', 'WAREHOUSE_INACTIVE');
        }
        $point = DB::table('collection_points')
            ->where('shop_id', $data['shop_id'])
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
        if (! $point) {
            DeliveryMissions::fail('shop_id', 'POINT_MISSING');
        }
        // Only the purchase lines are locked: offers belong to the order writers' lock order.
        $ids = DB::table('purchase_lines AS l')
            ->join('offers AS o', 'o.id', '=', 'l.offer_id')
            ->where('l.purchase_order_id', $purchase->id)
            ->where('o.shop_id', $data['shop_id'])
            ->pluck('l.id');
        $lines = DB::table('purchase_lines')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $requested = isset($data['lines']) ? collect($data['lines'])->keyBy('purchase_line_id') : null;
        if ($requested && $requested->keys()->diff($lines->pluck('id'))->isNotEmpty()) {
            DeliveryMissions::fail('lines', 'LINE_NOT_IN_SHOP');
        }
        $planned = [];
        foreach ($lines as $line) {
            $open = $this->openQuantity($line);
            $wanted = $requested ? (int) ($requested[$line->id]['quantity'] ?? 0) : $open;
            if ($wanted > $open) {
                DeliveryMissions::fail('lines', 'QUANTITY_EXCEEDS_OPEN');
            }
            if ($wanted > 0) {
                $planned[$line->id] = $wanted;
            }
        }
        if (! $planned) {
            DeliveryMissions::fail('lines', 'NOTHING_TO_COLLECT');
        }
        $id = Records::id();
        DB::table('supplier_collections')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('supplier_collection', 'COL-', 7),
            'purchase_order_id' => $purchase->id,
            'shop_id' => $data['shop_id'],
            'point_id' => $point->id,
            'warehouse_id' => $warehouse->id,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($planned as $lineId => $quantity) {
            DB::table('supplier_collection_lines')->insert([
                'id' => Records::id(),
                'collection_id' => $id,
                'purchase_line_id' => $lineId,
                'expected_quantity' => $quantity,
            ]);
        }
        $this->record($id, 'collection_created', $actor, ['lines' => $planned]);

        return $this->dispatchView(Records::row('supplier_collections', $id, false));
    }

    /** Units still to receive on the purchase line and not already promised to another live collection. */
    private function openQuantity(object $line): int
    {
        $promised = (int) DB::table('supplier_collection_lines AS cl')
            ->join('supplier_collections AS c', 'c.id', '=', 'cl.collection_id')
            ->where('cl.purchase_line_id', $line->id)
            ->whereIn('c.status', self::LIVE)
            ->sum(DB::raw('COALESCE(cl.collected_quantity, cl.expected_quantity)'));

        return max(0, $line->quantity - $line->received_quantity - $promised);
    }

    private function lock(string $id): object
    {
        return Records::row('supplier_collections', $id);
    }

    /** Courier and depot steps apply to depot collections; a direct one is only received or cancelled. */
    private function expect(object $c, int $version, array $statuses, bool $depotOnly = true): void
    {
        abort_unless((int) $c->version === $version, 409);
        if ($depotOnly && $c->destination !== 'warehouse') {
            DeliveryMissions::fail('collection', 'MISSION_STATE');
        }
        if (! in_array($c->status, $statuses, true)) {
            DeliveryMissions::fail('collection', 'MISSION_STATE');
        }
    }

    private function owned(object $c, User $courier): void
    {
        abort_unless((int) $c->courier_id === $courier->id, 404);
    }

    public function assign(string $id, array $data, User $actor): array
    {
        $c = $this->lock($id);
        $this->expect($c, $data['version'], ['unassigned', 'assigned', 'accepted', 'collected', 'incident']);
        $courier = (int) $data['courier_id'];
        if ($c->courier_id !== null && (int) $c->courier_id === $courier) {
            DeliveryMissions::fail('courier_id', 'ALREADY_ASSIGNED');
        }
        if ($c->custodian_id !== null && blank($data['note'] ?? null)) {
            DeliveryMissions::fail('note', 'CUSTODY_NOTE_REQUIRED');
        }
        app(Couriers::class)->assignable($courier);
        $this->update($c, [
            'courier_id' => $courier,
            'acceptance_required' => $data['acceptance_required'],
            'status' => $data['acceptance_required'] ? 'assigned' : 'accepted',
            'incident_kind' => null,
            'incident_note' => null,
        ]);
        $this->record($c->id, 'collection_assigned', $actor, [
            'courier_id' => $courier,
            'previous_courier_id' => $c->courier_id === null ? null : (int) $c->courier_id,
            'custodian_id' => $c->custodian_id === null ? null : (int) $c->custodian_id,
            'note' => $data['note'] ?? null,
        ]);

        return $this->dispatchView(Records::row('supplier_collections', $c->id, false));
    }

    public function cancel(string $id, array $data, User $actor): array
    {
        $c = $this->lock($id);
        $this->expect($c, $data['version'], ['unassigned', 'assigned', 'accepted', 'incident'], false);
        // Once goods were counted at the shop they must reach a depot; cancelling would lose track of them.
        if ($c->collected_at !== null) {
            DeliveryMissions::fail('collection', 'PARCELS_WITH_COURIER');
        }
        $this->update($c, ['status' => 'cancelled', 'cancel_reason' => $data['reason'], 'incident_kind' => null]);
        $this->record($c->id, 'collection_cancelled', $actor, ['reason' => $data['reason']]);

        return $this->dispatchView(Records::row('supplier_collections', $c->id, false));
    }

    public function retry(string $id, array $data, User $actor): array
    {
        $c = $this->lock($id);
        $this->expect($c, $data['version'], ['incident']);
        $this->update($c, [
            'status' => $c->custodian_id !== null && (int) $c->custodian_id === (int) $c->courier_id ? 'collected' : 'accepted',
            'incident_kind' => null,
            'incident_note' => null,
        ]);
        $this->record($c->id, 'incident_resolved', $actor, ['action' => 'retry', 'note' => $data['note']]);

        return $this->dispatchView(Records::row('supplier_collections', $c->id, false));
    }

    /** The depot's stock team links its own receipt (same purchase, destination depot) to close the collection. */
    public function received(string $id, array $data, User $actor): array
    {
        $c = $this->lock($id);
        $this->expect($c, $data['version'], ['dropped']);
        $receipt = DB::table('goods_receipts AS r')
            ->join('stock_locations AS l', 'l.id', '=', 'r.location_id')
            ->where('r.id', $data['receipt_id'])
            ->where('r.purchase_order_id', $c->purchase_order_id)
            ->where('l.warehouse_id', $c->warehouse_id)
            ->first(['r.id']);
        if (! $receipt) {
            DeliveryMissions::fail('receipt_id', 'RECEIPT_MISMATCH');
        }
        if (DB::table('supplier_collections')->where('receipt_id', $receipt->id)->exists()) {
            DeliveryMissions::fail('receipt_id', 'RECEIPT_ALREADY_LINKED');
        }
        $this->update($c, ['status' => 'received', 'receipt_id' => $receipt->id]);
        $this->record($c->id, 'collection_received', $actor, ['receipt_id' => $receipt->id]);

        return $this->dispatchView(Records::row('supplier_collections', $c->id, false));
    }

    public function accept(string $id, array $data, User $courier): array
    {
        $c = $this->lock($id);
        $this->owned($c, $courier);
        $this->expect($c, $data['version'], ['assigned']);
        $this->update($c, ['status' => 'accepted']);
        $this->record($c->id, 'collection_accepted', $courier, []);

        return $this->courierView(Records::row('supplier_collections', $c->id, false), $courier);
    }

    public function decline(string $id, array $data, User $courier): array
    {
        $c = $this->lock($id);
        $this->owned($c, $courier);
        $this->expect($c, $data['version'], ['assigned']);
        $this->update($c, ['status' => 'unassigned', 'courier_id' => null]);
        $this->record($c->id, 'collection_declined', $courier, ['reason' => $data['reason']]);

        return ['id' => $c->id, 'number' => $c->number, 'status' => 'declined'];
    }

    /**
     * At the shop: count each line. Fewer units than expected is recorded as a shortfall with a note, never
     * hidden. After a reassignment, the new courier takes the already counted parcels from the previous one.
     */
    public function collect(string $id, array $data, User $courier): array
    {
        $c = $this->lock($id);
        $this->owned($c, $courier);
        $this->expect($c, $data['version'], ['accepted']);
        if ($c->collected_at !== null) {
            if ($data['packages'] !== (int) $c->packages) {
                DeliveryMissions::fail('packages', 'PACKAGES_MISMATCH');
            }
            $this->update($c, ['status' => 'collected', 'custodian_id' => $courier->id]);
            $this->record($c->id, 'parcels_taken_over', $courier, [
                'from_courier_id' => (int) $c->custodian_id,
                'packages' => $data['packages'],
            ]);

            return $this->courierView(Records::row('supplier_collections', $c->id, false), $courier);
        }
        $expected = DB::table('supplier_collection_lines')
            ->where('collection_id', $c->id)
            ->lockForUpdate()
            ->get()
            ->keyBy('purchase_line_id');
        $given = collect($data['lines'] ?? [])->keyBy('purchase_line_id');
        if ($given->keys()->sort()->values()->all() !== $expected->keys()->sort()->values()->all()) {
            DeliveryMissions::fail('lines', 'ITEMS_MISMATCH');
        }
        $shortfall = false;
        $total = 0;
        foreach ($expected as $lineId => $line) {
            $quantity = (int) $given[$lineId]['quantity'];
            if ($quantity > $line->expected_quantity) {
                DeliveryMissions::fail('lines', 'QUANTITY_EXCEEDS_OPEN');
            }
            $shortfall = $shortfall || $quantity < $line->expected_quantity;
            $total += $quantity;
        }
        if ($total === 0) {
            DeliveryMissions::fail('lines', 'NOTHING_COLLECTED');
        }
        if ($shortfall && blank($data['note'] ?? null)) {
            DeliveryMissions::fail('note', 'SHORTFALL_NOTE_REQUIRED');
        }
        foreach ($expected as $lineId => $line) {
            DB::table('supplier_collection_lines')
                ->where('id', $line->id)
                ->update(['collected_quantity' => (int) $given[$lineId]['quantity']]);
        }
        $this->update($c, [
            'status' => 'collected',
            'custodian_id' => $courier->id,
            'collected_at' => now(),
            'packages' => $data['packages'],
            'shortfall' => $shortfall,
        ]);
        $this->record($c->id, 'goods_collected', $courier, [
            'packages' => $data['packages'],
            'shortfall' => $shortfall,
            'note' => $data['note'] ?? null,
        ]);

        return $this->courierView(Records::row('supplier_collections', $c->id, false), $courier);
    }

    public function reportIncident(string $id, array $data, User $courier): array
    {
        $c = $this->lock($id);
        $this->owned($c, $courier);
        $this->expect($c, $data['version'], ['accepted', 'collected']);
        $this->update($c, ['status' => 'incident', 'incident_kind' => $data['kind'], 'incident_note' => $data['note']]);
        $this->record($c->id, 'incident_reported', $courier, ['kind' => $data['kind'], 'note' => $data['note']]);

        return $this->courierView(Records::row('supplier_collections', $c->id, false), $courier);
    }

    public function drop(string $id, array $data, User $courier): array
    {
        $c = $this->lock($id);
        $this->owned($c, $courier);
        $this->expect($c, $data['version'], ['collected']);
        if ($data['packages'] !== (int) $c->packages) {
            DeliveryMissions::fail('packages', 'PACKAGES_MISMATCH');
        }
        $this->update($c, ['status' => 'dropped', 'custodian_id' => null, 'dropped_at' => now()]);
        $this->record($c->id, 'goods_dropped', $courier, ['packages' => $data['packages']]);

        return $this->courierView(Records::row('supplier_collections', $c->id, false), $courier);
    }

    /**
     * Direct collection for a paid customer order: the purchase units allocated to its lines, still to receive,
     * at one shop. They will be received there by the order's courier, or by the designated manager who goes
     * along, into an "on the road" location, then issued to the order on the spot.
     */
    public function createDirect(string $purchaseId, array $data, User $actor): array
    {
        // Lock order: order → supplier → purchase → purchase lines, as the order purchase writers.
        $order = Records::row('orders', $data['order_id']);
        if ($order->status !== 'paid' || $order->closed_at || $order->delivered_at) {
            DeliveryMissions::fail('order_id', 'ORDER_NOT_DELIVERABLE');
        }
        $initial = Records::row('purchase_orders', $purchaseId, false);
        Records::supplier($initial->supplier_id);
        $purchase = Records::row('purchase_orders', $purchaseId);
        if ($purchase->status !== 'confirmed') {
            DeliveryMissions::fail('purchase', 'PURCHASE_NOT_CONFIRMED');
        }
        app(Inventory::class)->location($data['transit_location_id']);
        if (isset($data['supervisor_id'])) {
            $supervisor = User::query()->find($data['supervisor_id']);
            if (! $supervisor || ! in_array('stock.receive', $supervisor->permissions(), true)) {
                DeliveryMissions::fail('supervisor_id', 'SUPERVISOR_INVALID');
            }
        }
        $point = $this->shopPoint($data['shop_id']);
        $allocations = DB::table('order_purchase_allocations AS a')
            ->join('order_lines AS ol', 'ol.id', '=', 'a.order_line_id')
            ->join('purchase_lines AS pl', 'pl.id', '=', 'a.purchase_line_id')
            ->join('offers AS o', 'o.id', '=', 'pl.offer_id')
            ->where('ol.order_id', $order->id)
            ->where('pl.purchase_order_id', $purchase->id)
            ->where('o.shop_id', $data['shop_id'])
            ->orderBy('a.purchase_line_id')
            ->orderBy('a.id')
            ->get(['a.id', 'a.order_line_id', 'a.purchase_line_id', 'a.quantity', 'a.released_quantity']);
        $lines = DB::table('purchase_lines')
            ->whereIn('id', $allocations->pluck('purchase_line_id')->unique()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $open = [];
        foreach ($lines as $line) {
            $open[$line->id] = $this->openQuantity($line);
        }
        $planned = [];
        foreach ($allocations as $a) {
            $promised = (int) DB::table('supplier_collection_lines AS cl')
                ->join('supplier_collections AS c', 'c.id', '=', 'cl.collection_id')
                ->where('cl.allocation_id', $a->id)
                ->whereIn('c.status', self::LIVE)
                ->sum('cl.expected_quantity');
            $wanted = min(
                $open[$a->purchase_line_id],
                $a->quantity -
                    $a->released_quantity -
                    app(OrderPurchases::class)->receivedForAllocation($a->id) -
                    $promised,
            );
            if ($wanted > 0) {
                $planned[] = [$a, $wanted];
                $open[$a->purchase_line_id] -= $wanted;
            }
        }
        if (! $planned) {
            DeliveryMissions::fail('order_id', 'NOTHING_TO_COLLECT');
        }
        $id = Records::id();
        DB::table('supplier_collections')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('supplier_collection', 'COL-', 7),
            'destination' => 'customer',
            'purchase_order_id' => $purchase->id,
            'shop_id' => $data['shop_id'],
            'point_id' => $point->id,
            'order_id' => $order->id,
            'transit_location_id' => $data['transit_location_id'],
            'supervisor_id' => $data['supervisor_id'] ?? null,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($planned as [$a, $quantity]) {
            DB::table('supplier_collection_lines')->insert([
                'id' => Records::id(),
                'collection_id' => $id,
                'purchase_line_id' => $a->purchase_line_id,
                'order_line_id' => $a->order_line_id,
                'allocation_id' => $a->id,
                'expected_quantity' => $quantity,
            ]);
        }
        $this->record($id, 'direct_collection_created', $actor, [
            'order_id' => $order->id,
            'supervisor_id' => $data['supervisor_id'] ?? null,
        ]);

        return $this->dispatchView(Records::row('supplier_collections', $id, false));
    }

    /**
     * Receipt at the shop for a direct collection, in one transaction: photo of the shop's slip checked, units
     * received with their serials into the "on the road" location, held for the customer's line, issued to the
     * order, and the parcels added to the courier's load. The receiver is the designated manager when there is
     * one, otherwise the order's courier. Fewer units than expected are recorded with a note; the order cannot
     * be handed over until the missing units are received or cancelled.
     */
    public function receiveDirect(string $id, array $data, User $actor): array
    {
        $initial = Records::row('supplier_collections', $id, false);
        if ($initial->destination !== 'customer') {
            DeliveryMissions::fail('collection', 'MISSION_STATE');
        }
        $order = Records::row('orders', $initial->order_id);
        $live = app(DeliveryMissions::class)->live($order->id);
        if (! $live) {
            DeliveryMissions::fail('collection', 'MISSION_REQUIRED');
        }
        $mission = Records::row('delivery_missions', $live->id);
        $c = $this->lock($id);
        $this->expect($c, $data['version'], ['unassigned'], false);
        if (! in_array($mission->status, ['accepted', 'in_transit'], true)) {
            DeliveryMissions::fail('mission', 'MISSION_STATE');
        }
        $receiver = $c->supervisor_id !== null ? (int) $c->supervisor_id : (int) $mission->courier_id;
        if ($actor->id !== $receiver) {
            DeliveryMissions::fail(
                'collection',
                $c->supervisor_id !== null ? 'SUPERVISOR_RECEIVES' : 'COURIER_RECEIVES',
            );
        }
        if ($mission->custodian_id !== null && (int) $mission->custodian_id !== (int) $mission->courier_id) {
            DeliveryMissions::fail('mission', 'TAKE_OVER_FIRST');
        }
        $supplier = DB::table('purchase_orders')->where('id', $c->purchase_order_id)->value('supplier_id');
        app(PrivateDocuments::class)->requireClean($data['document_id'], $supplier, [
            'stock_evidence',
            'supplier_invoice',
        ]);
        $expected = DB::table('supplier_collection_lines')
            ->where('collection_id', $c->id)
            ->orderBy('purchase_line_id')
            ->orderBy('allocation_id')
            ->lockForUpdate()
            ->get()
            ->groupBy('purchase_line_id');
        $given = collect($data['lines'])->keyBy('purchase_line_id');
        if ($given->keys()->sort()->values()->all() !== $expected->keys()->sort()->values()->all()) {
            DeliveryMissions::fail('lines', 'ITEMS_MISMATCH');
        }
        $shortfall = false;
        $receiptLines = [];
        foreach ($expected as $lineId => $rows) {
            $quantity = (int) $given[$lineId]['quantity'];
            if ($quantity > (int) $rows->sum('expected_quantity')) {
                DeliveryMissions::fail('lines', 'QUANTITY_EXCEEDS_OPEN');
            }
            $shortfall = $shortfall || $quantity < (int) $rows->sum('expected_quantity');
            if ($quantity > 0) {
                $receiptLines[] = [
                    'purchase_line_id' => $lineId,
                    'quantity' => $quantity,
                    'quality' => 'available',
                    'identifiers' => $given[$lineId]['identifiers'] ?? [],
                    'lot_number' => $given[$lineId]['lot_number'] ?? null,
                    'expires_on' => $given[$lineId]['expires_on'] ?? null,
                ];
            }
        }
        if (! $receiptLines) {
            DeliveryMissions::fail('lines', 'NOTHING_COLLECTED');
        }
        if ($shortfall && blank($data['note'] ?? null)) {
            DeliveryMissions::fail('note', 'SHORTFALL_NOTE_REQUIRED');
        }
        $receipt = app(Purchasing::class)->receive(
            $c->purchase_order_id,
            [
                'location_id' => $c->transit_location_id,
                'document_id' => $data['document_id'],
                'reference' => $c->number,
                'received_at' => now()->toIso8601String(),
                'lines' => $receiptLines,
            ],
            $actor,
        );
        foreach ($expected as $lineId => $rows) {
            $remaining = (int) $given[$lineId]['quantity'];
            $receiptLine = DB::table('receipt_lines')
                ->where('receipt_id', $receipt['id'])
                ->where('purchase_line_id', $lineId)
                ->value('id');
            foreach ($rows as $row) {
                $take = min($remaining, (int) $row->expected_quantity);
                $remaining -= $take;
                $identifiers = [];
                if ($take > 0) {
                    $held = app(OrderPurchases::class)->receive(
                        $order,
                        $row->allocation_id,
                        [
                            'version' => (int) DB::table('order_purchase_allocations')
                                ->where('id', $row->allocation_id)
                                ->value('version'),
                            'receipt_line_id' => $receiptLine,
                            'quantity' => $take,
                        ],
                        $actor,
                    );
                    $identifiers = DB::table('inventory_units')
                        ->where('reservation_id', $held['reservation_id'])
                        ->where('status', 'reserved')
                        ->orderBy('identifier')
                        ->pluck('identifier')
                        ->all();
                    app(OrderPreparation::class)->pick(
                        $order->id,
                        [
                            'version' => (int) DB::table('orders')
                                ->where('id', $order->id)
                                ->value('preparation_version'),
                            'reservation_id' => $held['reservation_id'],
                            'identifiers' => $identifiers,
                            'note' => 'Shop receipt '.$c->number,
                        ],
                        $actor,
                        true,
                    );
                }
                DB::table('supplier_collection_lines')
                    ->where('id', $row->id)
                    ->update(['collected_quantity' => $take, 'identifiers' => Records::json($identifiers)]);
            }
        }
        $this->update($c, [
            'status' => 'received',
            'courier_id' => $mission->courier_id,
            'receipt_id' => $receipt['id'],
            'document_id' => $data['document_id'],
            'received_by' => $actor->id,
            'collected_at' => now(),
            'packages' => $data['packages'],
            'shortfall' => $shortfall,
        ]);
        $this->record($c->id, 'direct_received', $actor, [
            'receipt_id' => $receipt['id'],
            'packages' => $data['packages'],
            'shortfall' => $shortfall,
            'note' => $data['note'] ?? null,
        ]);
        app(DeliveryMissions::class)->addShopParcels(
            $order,
            Records::row('delivery_missions', $mission->id),
            $data['packages'],
            $actor,
            $c->id,
        );

        return $this->dispatchView(Records::row('supplier_collections', $c->id, false));
    }

    private function shopPoint(string $shopId): object
    {
        $point = DB::table('collection_points')
            ->where('shop_id', $shopId)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
        if (! $point) {
            DeliveryMissions::fail('shop_id', 'POINT_MISSING');
        }

        return $point;
    }

    public function customer(object $c): array
    {
        $order = DB::table('orders')
            ->where('id', $c->order_id)
            ->first(['id', 'number']);

        return ['type' => 'customer', 'order_id' => $order->id, 'order_number' => $order->number];
    }

    /** What the courier needs at the shop: where, what to count, and who receives. */
    public function directView(object $c): array
    {
        $supervisor = $c->supervisor_id ? User::query()->find($c->supervisor_id) : null;

        return [
            'id' => $c->id,
            'number' => $c->number,
            'version' => (int) $c->version,
            'pickup' => $this->point($c),
            'lines' => $this->lines($c),
            'receiver' => $supervisor ? 'supervisor' : 'courier',
            'supervisor_name' => $supervisor?->name,
        ];
    }

    private function update(object $c, array $changes): void
    {
        DB::table('supplier_collections')
            ->where('id', $c->id)
            ->update([...$changes, 'version' => $c->version + 1, 'updated_at' => now()]);
    }

    private function record(string $id, string $action, User $actor, array $data): void
    {
        DB::table('supplier_collection_events')->insert([
            'collection_id' => $id,
            'action' => $action,
            'actor_id' => $actor->id,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
        Records::audit($actor, 'delivery.'.$action, $id, $data);
    }

    public function point(object $c): array
    {
        $p = DB::table('collection_points')->where('id', $c->point_id)->first();
        $shop = DB::table('shops')->where('id', $c->shop_id)->value('name');

        return [
            'type' => 'shop',
            'shop' => $shop,
            'name' => $p->name,
            'address' => $p->address,
            'district' => $p->district,
            'landmark' => $p->landmark,
            'latitude' => $p->latitude === null ? null : (float) $p->latitude,
            'longitude' => $p->longitude === null ? null : (float) $p->longitude,
        ];
    }

    public function warehouse(object $c): array
    {
        $w = DB::table('warehouses')->where('id', $c->warehouse_id)->first();

        return ['type' => 'warehouse', 'code' => $w->code, 'name' => $w->name, 'address' => $w->address];
    }

    /** Articles to count: names and quantities only, never purchase prices. */
    public function lines(object $c): array
    {
        $locale = app()->getLocale();

        return DB::table('supplier_collection_lines AS cl')
            ->join('purchase_lines AS l', 'l.id', '=', 'cl.purchase_line_id')
            ->join('variants AS v', 'v.id', '=', 'l.variant_id')
            ->join('products AS p', 'p.id', '=', 'v.product_id')
            ->where('cl.collection_id', $c->id)
            ->orderBy('v.sku')
            ->get(['cl.purchase_line_id', 'cl.expected_quantity', 'cl.collected_quantity', 'v.sku', 'p.name'])
            ->map(function ($l) use ($locale) {
                $name = json_decode($l->name, true);

                return [
                    'purchase_line_id' => $l->purchase_line_id,
                    'name' => $name[$locale] ?? $name['fr'],
                    'sku' => $l->sku,
                    'expected_quantity' => (int) $l->expected_quantity,
                    'collected_quantity' => $l->collected_quantity === null ? null : (int) $l->collected_quantity,
                ];
            })
            ->all();
    }

    public function dispatchView(object $c): array
    {
        return [
            'id' => $c->id,
            'number' => $c->number,
            'status' => $c->status,
            'version' => (int) $c->version,
            'purchase_order_id' => $c->purchase_order_id,
            'courier' => $c->courier_id === null
                    ? null
                    : [
                        'user_id' => (int) $c->courier_id,
                        ...app(Couriers::class)->publicIdentity((int) $c->courier_id) ?? [],
                    ],
            'custody' => $c->custodian_id === null ? ($c->dropped_at ? 'warehouse' : 'shop') : 'courier',
            'custodian_id' => $c->custodian_id === null ? null : (int) $c->custodian_id,
            'acceptance_required' => (bool) $c->acceptance_required,
            'pickup' => $this->point($c),
            'destination' => $c->destination === 'warehouse' ? $this->warehouse($c) : $this->customer($c),
            'supervisor_id' => $c->supervisor_id === null ? null : (int) $c->supervisor_id,
            'received_by' => $c->received_by === null ? null : (int) $c->received_by,
            'document_id' => $c->document_id,
            'lines' => $this->lines($c),
            'packages' => $c->packages === null ? null : (int) $c->packages,
            'shortfall' => (bool) $c->shortfall,
            'incident' => $c->incident_kind ? ['kind' => $c->incident_kind, 'note' => $c->incident_note] : null,
            'collected_at' => Records::iso($c->collected_at),
            'dropped_at' => Records::iso($c->dropped_at),
            'receipt_id' => $c->receipt_id,
            'cancel_reason' => $c->cancel_reason,
            'events' => DB::table('supplier_collection_events')
                ->where('collection_id', $c->id)
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($e) => [
                        'action' => $e->action,
                        'actor_id' => $e->actor_id === null ? null : (int) $e->actor_id,
                        'data' => json_decode($e->data, true),
                        'created_at' => Records::iso($e->created_at),
                    ],
                )
                ->values(),
        ];
    }

    public function courierView(object $c, User $courier): array
    {
        $base = ['id' => $c->id, 'number' => $c->number, 'status' => $c->status, 'version' => (int) $c->version];
        if (
            ! in_array($c->status, ['assigned', 'accepted', 'collected', 'incident'], true) ||
            (int) $c->courier_id !== $courier->id
        ) {
            return $base;
        }

        return [
            ...$base,
            'holding_goods' => (int) $c->custodian_id === $courier->id,
            'take_over_from_courier' => $c->collected_at !== null && (int) $c->custodian_id !== $courier->id,
            'pickup' => $this->point($c),
            'destination' => $this->warehouse($c),
            'lines' => $this->lines($c),
            'packages' => $c->packages === null ? null : (int) $c->packages,
            'shortfall' => (bool) $c->shortfall,
            'incident' => $c->incident_kind ? ['kind' => $c->incident_kind] : null,
        ];
    }

    public function live(): array
    {
        return self::LIVE;
    }
}
