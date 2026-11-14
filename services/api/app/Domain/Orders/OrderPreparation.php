<?php

namespace App\Domain\Orders;

use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Inventory\Inventory;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Picking of a paid order: each retained reservation (KORA stock or allocated receipt) is issued from stock once,
 * with its exact serials checked. Readiness is an explicit act once every non-cancelled unit is picked. Units still
 * to be bought or collected directly from a supplier are not prepared here.
 */
final class OrderPreparation
{
    public const KINDS = ['missing', 'damaged', 'expired', 'mismatch', 'other'];

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('preparation.'.$code)]);
    }

    /** Lock order: order → line → reservation → offer → balances, as the other order writers. */
    private function order(string $id, bool $open = true): object
    {
        $order = Records::row('orders', $id);
        if ($open && ($order->status !== 'paid' || $order->closed_at)) {
            self::fail('order', 'PAID_OPEN_ORDER_REQUIRED');
        }

        return $order;
    }

    private function line(object $order, string $lineId): object
    {
        $line = DB::table('order_lines')->where('id', $lineId)->where('order_id', $order->id)->lockForUpdate()->first();
        abort_unless($line, 404);

        return $line;
    }

    /**
     * $direct: units received at a shop on the way for an order already declared ready; they leave the "on the
     * road" location for the customer exactly like a depot pick, with their serials checked.
     */
    public function pick(string $orderId, array $data, User $actor, bool $direct = false): array
    {
        $order = $this->order($orderId);
        abort_unless((int) $order->preparation_version === $data['version'], 409);
        if ($order->prepared_at && ! $direct) {
            self::fail('order', 'ALREADY_READY');
        }
        $initial = Records::row('inventory_reservations', $data['reservation_id'], false);
        if (
            ! $initial->order_line_id ||
            ! DB::table('order_lines')->where('id', $initial->order_line_id)->where('order_id', $order->id)->exists()
        ) {
            self::fail('reservation_id', 'RESERVATION_NOT_IN_ORDER');
        }
        $line = $this->line($order, $initial->order_line_id);
        $reservation = Records::row('inventory_reservations', $initial->id);
        if ($reservation->status !== 'active') {
            self::fail('reservation_id', 'RESERVATION_NOT_ACTIVE');
        }
        $origin = $this->origin($line, $reservation->id);
        $expected = DB::table('inventory_units')
            ->where('reservation_id', $reservation->id)
            ->where('status', 'reserved')
            ->orderBy('identifier')
            ->pluck('identifier')
            ->all();
        $given = $data['identifiers'];
        sort($given);
        // The preparer scans what is actually in hand; a different serial must not leave under this sale.
        if ($given !== $expected) {
            self::fail('identifiers', 'IDENTIFIERS_MISMATCH');
        }
        $sources = $this->sources($reservation->id);
        app(Inventory::class)->consume($reservation->id, $order->number, $actor);
        $id = Records::id();
        DB::table('order_preparation_picks')->insert([
            'id' => $id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'reservation_id' => $reservation->id,
            'origin' => $origin,
            'quantity' => $reservation->quantity,
            'identifiers' => Records::json($expected),
            'sources' => Records::json($sources),
            'note' => $data['note'] ?? null,
            'picked_by' => $actor->id,
            'picked_at' => now(),
        ]);
        $this->refresh($order);
        $this->record($order, 'preparation_picked', $id, $actor, [
            'line_id' => $line->id,
            'reservation_id' => $reservation->id,
            'quantity' => (int) $reservation->quantity,
        ]);

        return $this->view(Records::row('orders', $order->id, false));
    }

    private function origin(object $line, string $reservationId): string
    {
        if ($line->reservation_id === $reservationId) {
            return 'warehouse';
        }
        if (
            DB::table('order_preparation_returns')
                ->where('replacement_reservation_id', $reservationId)
                ->where('order_line_id', $line->id)
                ->exists()
        ) {
            return 'replacement';
        }
        $held = DB::table('order_receipt_allocations AS r')
            ->join('order_purchase_allocations AS a', 'a.id', '=', 'r.purchase_allocation_id')
            ->where('r.reservation_id', $reservationId)
            ->where('r.status', 'held')
            ->where('a.order_line_id', $line->id)
            ->exists();
        if (! $held) {
            self::fail('reservation_id', 'RESERVATION_NOT_IN_ORDER');
        }

        return 'receipt';
    }

    /** Where the units physically were, kept with the pick: locations may be renamed or closed later. */
    private function sources(string $reservationId): array
    {
        return DB::table('inventory_reservation_lines AS rl')
            ->join('inventory_balances AS b', 'b.id', '=', 'rl.balance_id')
            ->join('stock_lots AS lot', 'lot.id', '=', 'b.lot_id')
            ->join('stock_locations AS loc', 'loc.id', '=', 'b.location_id')
            ->join('warehouses AS w', 'w.id', '=', 'loc.warehouse_id')
            ->where('rl.reservation_id', $reservationId)
            ->orderBy('w.code')
            ->orderBy('loc.code')
            ->orderBy('rl.balance_id')
            ->get([
                'rl.balance_id',
                'rl.quantity',
                'b.condition',
                'lot.id AS lot_id',
                'lot.lot_number',
                'lot.expires_on',
                'w.code AS warehouse_code',
                'loc.code AS location_code',
                'loc.name AS location_name',
            ])
            ->map(
                fn ($s) => [
                    'balance_id' => $s->balance_id,
                    'warehouse_code' => $s->warehouse_code,
                    'location_code' => $s->location_code,
                    'location_name' => $s->location_name,
                    'lot_id' => $s->lot_id,
                    'lot_number' => $s->lot_number,
                    'expires_on' => $s->expires_on,
                    'condition' => $s->condition,
                    'quantity' => (int) $s->quantity,
                ],
            )
            ->all();
    }

    public function ready(string $orderId, array $data, User $actor): array
    {
        $order = $this->order($orderId);
        abort_unless((int) $order->preparation_version === $data['version'], 409);
        if ($order->prepared_at) {
            self::fail('order', 'ALREADY_READY');
        }
        if ($this->openAnomalies($order->id) > 0) {
            self::fail('anomalies', 'ANOMALY_OPEN');
        }
        $required = 0;
        foreach (
            DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->lockForUpdate()->get() as $line
        ) {
            $needed = $line->quantity - $line->cancelled_quantity;
            // Units a courier will receive at a shop on the way count as covered, not as prepared.
            if ($this->picked($line->id) + $this->direct($line->id) < $needed) {
                self::fail('lines', 'PREPARATION_INCOMPLETE');
            }
            $required += $needed;
        }
        if ($required === 0) {
            self::fail('lines', 'NOTHING_TO_PREPARE');
        }
        // Zero depot parcels only when nothing was picked here and everything comes from shops on the way.
        if (
            $data['packages'] === 0 &&
            (DB::table('order_preparation_picks')->where('order_id', $order->id)->exists() ||
                ! DB::table('supplier_collections')
                    ->where('order_id', $order->id)
                    ->where('status', 'unassigned')
                    ->exists())
        ) {
            self::fail('packages', 'PACKAGES_REQUIRED');
        }
        $this->refresh($order, [
            'prepared_at' => now(),
            'prepared_by' => $actor->id,
            'preparation_packages' => $data['packages'],
        ]);
        $this->record($order, 'preparation_ready', $order->id, $actor, [
            'packages' => $data['packages'],
            'note' => $data['note'] ?? null,
        ]);

        return $this->view(Records::row('orders', $order->id, false));
    }

    public function report(string $orderId, array $data, User $actor): array
    {
        $order = $this->order($orderId);
        abort_unless((int) $order->preparation_version === $data['version'], 409);
        // Once a courier mission exists, problems are handled on the mission, which knows who holds the parcels.
        if (app(DeliveryMissions::class)->live($order->id)) {
            self::fail('order', 'MISSION_ACTIVE');
        }
        $line = $this->line($order, $data['order_line_id']);
        if ($data['quantity'] > $line->quantity - $line->cancelled_quantity) {
            self::fail('quantity', 'QUANTITY_INVALID');
        }
        $id = Records::id();
        DB::table('order_preparation_anomalies')->insert([
            'id' => $id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'kind' => $data['kind'],
            'quantity' => $data['quantity'],
            'note' => $data['note'],
            'reported_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // A parcel found faulty after it was declared ready is no longer ready for a courier.
        $this->refresh($order, $order->prepared_at ? ['prepared_at' => null, 'prepared_by' => null] : []);
        $this->record($order, 'preparation_anomaly', $id, $actor, [
            'line_id' => $line->id,
            'kind' => $data['kind'],
            'quantity' => $data['quantity'],
            'readiness_withdrawn' => (bool) $order->prepared_at,
        ]);

        return $this->anomalyDto(Records::row('order_preparation_anomalies', $id, false));
    }

    public function resolve(string $anomalyId, array $data, User $actor): array
    {
        $initial = Records::row('order_preparation_anomalies', $anomalyId, false);
        $order = $this->order($initial->order_id, false);
        $anomaly = Records::row('order_preparation_anomalies', $anomalyId);
        abort_unless((int) $anomaly->version === $data['version'], 409);
        if ($anomaly->status !== 'open') {
            self::fail('anomaly', 'ANOMALY_RESOLVED');
        }
        DB::table('order_preparation_anomalies')
            ->where('id', $anomalyId)
            ->update([
                'status' => 'resolved',
                'resolution_note' => $data['note'],
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'version' => $anomaly->version + 1,
                'updated_at' => now(),
            ]);
        $this->refresh($order);
        $this->record($order, 'preparation_anomaly_resolved', $anomalyId, $actor, ['note' => $data['note']]);

        return $this->anomalyDto(Records::row('order_preparation_anomalies', $anomalyId, false));
    }

    /** Recomputes the synthesis from the facts; the caller holds the order lock. */
    private function refresh(object $order, array $changes = []): void
    {
        $prepared = array_key_exists('prepared_at', $changes) ? $changes['prepared_at'] : $order->prepared_at;
        $status = match (true) {
            $this->openAnomalies($order->id) > 0 => 'anomaly',
            $prepared !== null => 'ready',
            DB::table('order_preparation_picks')->where('order_id', $order->id)->exists() => 'partial',
            default => 'not_started',
        };
        DB::table('orders')
            ->where('id', $order->id)
            ->update([
                ...$changes,
                'preparation_status' => $status,
                'preparation_version' => $order->preparation_version + 1,
                'updated_at' => now(),
            ]);
    }

    private function openAnomalies(string $orderId): int
    {
        return DB::table('order_preparation_anomalies')->where('order_id', $orderId)->where('status', 'open')->count();
    }

    /** Units of the line promised to a direct shop collection that is not received yet. */
    public function direct(string $lineId): int
    {
        return (int) DB::table('supplier_collection_lines AS cl')
            ->join('supplier_collections AS c', 'c.id', '=', 'cl.collection_id')
            ->where('cl.order_line_id', $lineId)
            ->where('c.status', 'unassigned')
            ->sum('cl.expected_quantity');
    }

    /** Units issued for the line and still issued: a unit sent back to stock for replacement no longer counts. */
    public function picked(string $lineId): int
    {
        return (int) DB::table('order_preparation_picks')->where('order_line_id', $lineId)->sum('quantity') -
            (int) DB::table('order_preparation_returns')->where('order_line_id', $lineId)->sum('quantity');
    }

    /**
     * Net issued units of an order, keyed by line: quantities and serials actually leaving with the courier.
     *
     * @return array<string, array{quantity: int, identifiers: list<string>}>
     */
    public function issued(string $orderId): array
    {
        $lines = [];
        foreach (
            DB::table('order_preparation_picks')->where('order_id', $orderId)->orderBy('order_line_id')->get() as $pick
        ) {
            $lines[$pick->order_line_id] ??= ['quantity' => 0, 'identifiers' => []];
            $lines[$pick->order_line_id]['quantity'] += (int) $pick->quantity;
            array_push($lines[$pick->order_line_id]['identifiers'], ...json_decode($pick->identifiers, true));
        }
        foreach (DB::table('order_preparation_returns')->where('order_id', $orderId)->get() as $return) {
            $lines[$return->order_line_id]['quantity'] -= (int) $return->quantity;
            $lines[$return->order_line_id]['identifiers'] = array_values(
                array_diff($lines[$return->order_line_id]['identifiers'], json_decode($return->identifiers, true)),
            );
        }
        foreach ($lines as $id => &$line) {
            sort($line['identifiers']);
            if ($line['quantity'] <= 0) {
                unset($lines[$id]);
            }
        }
        unset($line);
        ksort($lines);

        return $lines;
    }

    /**
     * An issued unit found damaged or wrong before handover goes back to stock set aside (damaged or quarantine)
     * and a replacement sold under the same terms is held for the line, to be picked like any other unit. The
     * order is no longer ready until then. Not while a courier mission is live: problems then go through it.
     */
    public function replace(string $orderId, string $pickId, array $data, User $actor): array
    {
        $order = $this->order($orderId);
        abort_unless((int) $order->preparation_version === $data['version'], 409);
        if (app(DeliveryMissions::class)->live($order->id)) {
            self::fail('order', 'MISSION_ACTIVE');
        }
        $pick = DB::table('order_preparation_picks')->where('id', $pickId)->where('order_id', $order->id)->first();
        abort_unless($pick, 404);
        $line = $this->line($order, $pick->order_line_id);
        $returned = DB::table('order_preparation_returns')->where('pick_id', $pick->id)->get();
        $left = (int) $pick->quantity - (int) $returned->sum('quantity');
        $identifiers = $data['identifiers'];
        sort($identifiers);
        $issued = array_values(
            array_diff(
                json_decode($pick->identifiers, true),
                $returned->flatMap(fn ($r) => json_decode($r->identifiers, true))->all(),
            ),
        );
        if (
            $data['quantity'] > $left ||
            array_diff($identifiers, $issued) ||
            ($issued && count($identifiers) !== $data['quantity'])
        ) {
            self::fail('identifiers', 'IDENTIFIERS_MISMATCH');
        }
        $offer = DB::table('order_lines')->where('id', $line->id)->value('offer_id');
        if ($identifiers) {
            $units = DB::table('inventory_units')
                ->whereIn('identifier', $identifiers)
                ->where('variant_id', DB::table('offers')->where('id', $offer)->value('variant_id'))
                ->where('status', 'consumed')
                ->get();
            $lots = $units->countBy('lot_id')->all();
            $unitIds = $units->pluck('id')->all();
        } else {
            $lots = [];
            $remaining = $data['quantity'];
            foreach (json_decode($pick->sources, true) as $source) {
                $take = min($remaining, (int) $source['quantity']);
                if ($take > 0) {
                    $lots[$source['lot_id']] = ($lots[$source['lot_id']] ?? 0) + $take;
                    $remaining -= $take;
                }
            }
            $unitIds = [];
        }
        $inventory = app(Inventory::class);
        $inventory->returnIssued(
            $lots,
            $unitIds,
            $data['location_id'],
            $data['condition'],
            'preparation_return',
            'preparation',
            $pick->id,
            $order->number.' replacement',
            $actor,
        );
        $hash = DB::table('stock_lots')->where('id', array_key_first($lots))->value('terms_hash');
        $id = Records::id();
        $reservation = $inventory->reserveReplacement($offer, $hash, $data['quantity'], $id, $line->id, $actor);
        DB::table('order_preparation_returns')->insert([
            'id' => $id,
            'pick_id' => $pick->id,
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'quantity' => $data['quantity'],
            'identifiers' => Records::json($identifiers),
            'condition' => $data['condition'],
            'replacement_reservation_id' => $reservation['id'],
            'note' => $data['note'],
            'returned_by' => $actor->id,
            'created_at' => now(),
        ]);
        $this->refresh($order, $order->prepared_at ? ['prepared_at' => null, 'prepared_by' => null] : []);
        $this->record($order, 'preparation_replaced', $id, $actor, [
            'pick_id' => $pick->id,
            'quantity' => $data['quantity'],
            'condition' => $data['condition'],
            'replacement_reservation_id' => $reservation['id'],
        ]);

        return $this->view(Records::row('orders', $order->id, false));
    }

    private function record(object $order, string $action, string $id, User $actor, array $data): void
    {
        app(Orders::class)->event($order->id, $action, $actor, ['preparation_id' => $id, ...$data]);
        Records::audit($actor, 'order.'.$action, $id, $data);
    }

    /** Internal picking list: locations, lots and serials, without prices, costs or the customer's address. */
    public function view(object $order): array
    {
        $locale = app()->getLocale();
        $delivery = json_decode($order->delivery_snapshot, true);
        $rule =
            $delivery['slot']['rule_id'] ?? null
                ? DB::table('delivery_slot_rules')->where('id', $delivery['slot']['rule_id'])->first()
                : null;

        return [
            'order_id' => $order->id,
            'number' => $order->number,
            'order_status' => $order->status,
            'status' => $order->preparation_status,
            'version' => (int) $order->preparation_version,
            'paid_at' => Records::iso($order->paid_at),
            'prepared_at' => Records::iso($order->prepared_at),
            'packages' => $order->preparation_packages === null ? null : (int) $order->preparation_packages,
            'slot' => $rule
                ? ['date' => $delivery['slot']['date'], 'starts_at' => $rule->starts_at, 'ends_at' => $rule->ends_at]
                : null,
            'lines' => DB::table('order_lines')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($line) => $this->lineView($line, $locale))
                ->values(),
            'anomalies' => DB::table('order_preparation_anomalies')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($a) => $this->anomalyDto($a))
                ->values(),
        ];
    }

    private function lineView(object $line, string $locale): array
    {
        $snapshot = json_decode($line->snapshot, true);
        $required = $line->quantity - $line->cancelled_quantity;
        $picked = $this->picked($line->id);
        $held = DB::table('inventory_reservations')
            ->where('order_line_id', $line->id)
            ->where('status', 'active')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        return [
            'id' => $line->id,
            'name' => $snapshot['name'][$locale] ?? $snapshot['name']['fr'],
            'sku' => $snapshot['sku'],
            'quantity' => (int) $line->quantity,
            'cancelled_quantity' => (int) $line->cancelled_quantity,
            'required_quantity' => $required,
            'prepared_quantity' => $picked,
            'held_quantity' => (int) $held->sum('quantity'),
            'direct_collection_quantity' => $this->direct($line->id),
            // Units neither picked, held in a KORA location nor planned for a shop receipt: still to buy or receive.
            'awaiting_stock_quantity' => max(
                0,
                $required - $picked - (int) $held->sum('quantity') - $this->direct($line->id),
            ),
            'sources' => $held
                ->map(
                    fn ($r) => [
                        'reservation_id' => $r->id,
                        'origin' => match (true) {
                            $line->reservation_id === $r->id => 'warehouse',
                            DB::table('order_preparation_returns')
                                ->where('replacement_reservation_id', $r->id)
                                ->exists() => 'replacement',
                            default => 'receipt',
                        },
                        'quantity' => (int) $r->quantity,
                        'identifiers' => DB::table('inventory_units')
                            ->where('reservation_id', $r->id)
                            ->where('status', 'reserved')
                            ->orderBy('identifier')
                            ->pluck('identifier')
                            ->all(),
                        'locations' => $this->sources($r->id),
                    ],
                )
                ->values(),
            'picks' => DB::table('order_preparation_picks')
                ->where('order_line_id', $line->id)
                ->orderBy('picked_at')
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($p) => [
                        'id' => $p->id,
                        'reservation_id' => $p->reservation_id,
                        'origin' => $p->origin,
                        'quantity' => (int) $p->quantity,
                        'identifiers' => json_decode($p->identifiers, true),
                        'locations' => json_decode($p->sources, true),
                        'note' => $p->note,
                        'returned_quantity' => (int) DB::table('order_preparation_returns')
                            ->where('pick_id', $p->id)
                            ->sum('quantity'),
                        'picked_by' => (int) $p->picked_by,
                        'picked_at' => Records::iso($p->picked_at),
                    ],
                )
                ->values(),
        ];
    }

    public function anomalyDto(object $a): array
    {
        return [
            'id' => $a->id,
            'order_id' => $a->order_id,
            'order_line_id' => $a->order_line_id,
            'kind' => $a->kind,
            'quantity' => (int) $a->quantity,
            'note' => $a->note,
            'status' => $a->status,
            'resolution_note' => $a->resolution_note,
            'reported_by' => (int) $a->reported_by,
            'resolved_by' => $a->resolved_by === null ? null : (int) $a->resolved_by,
            'resolved_at' => Records::iso($a->resolved_at),
            'version' => (int) $a->version,
            'created_at' => Records::iso($a->created_at),
        ];
    }

    /** Queue of paid open orders for the stock team, oldest payment first. */
    public function queueDto(object $order): array
    {
        $lines = DB::table('order_lines')->where('order_id', $order->id)->get();
        $required = 0;
        $prepared = 0;
        foreach ($lines as $line) {
            $required += $line->quantity - $line->cancelled_quantity;
            $prepared += $this->picked($line->id);
        }
        $delivery = json_decode($order->delivery_snapshot, true);

        return [
            'order_id' => $order->id,
            'number' => $order->number,
            'status' => $order->preparation_status,
            'version' => (int) $order->preparation_version,
            'paid_at' => Records::iso($order->paid_at),
            'slot_date' => $delivery['slot']['date'] ?? null,
            'required_quantity' => $required,
            'prepared_quantity' => $prepared,
            'open_anomalies' => $this->openAnomalies($order->id),
        ];
    }
}
