<?php

namespace App\Domain\AfterSales;

use App\Domain\Delivery\Couriers;
use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Inventory\Inventory;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/**
 * Courier trips of an after-sales case. A pickup fetches the returned items at the customer's address and drops
 * them at a depot, where the stock team receives the return as usual. A delivery takes an exchange item, issued
 * from stock for the case under the same terms as the item sold, from the depot to the customer, who confirms
 * with their own one-time code. Lock order: case → trip.
 */
final class AfterSalesTrips
{
    private const LIVE = ['unassigned', 'accepted', 'in_transit'];

    /** Pickup of the units still to come back for an accepted return, exchange or repair. */
    public function createPickup(string $caseId, array $data, User $actor): array
    {
        $case = Records::row('after_sales_cases', $caseId);
        if (
            $case->status !== 'accepted' ||
            ! in_array($case->resolution, ['return_refund', 'exchange', 'repair'], true)
        ) {
            AfterSales::fail('case', 'CASE_STATE');
        }
        $left =
            (int) $case->quantity -
            (int) DB::table('after_sales_returns')->where('case_id', $case->id)->sum('quantity');
        if ($left < 1) {
            AfterSales::fail('case', 'NOTHING_TO_PICK_UP');
        }
        $this->warehouse($data['warehouse_id']);

        return $this->insert(
            $case,
            'pickup',
            $data['warehouse_id'],
            $left,
            json_decode($case->identifiers, true),
            [],
            $actor,
        );
    }

    /**
     * Exchange shipment prepared by the stock team: replacement units, sold under the same terms as the units
     * delivered, are issued from stock for the case right away (reference: the case number), then carried.
     */
    public function createDelivery(string $caseId, array $data, User $actor): array
    {
        $case = Records::row('after_sales_cases', $caseId);
        if ($case->status !== 'accepted' || $case->resolution !== 'exchange') {
            AfterSales::fail('case', 'CASE_STATE');
        }
        $this->warehouse($data['warehouse_id']);
        $line = DB::table('order_lines')->where('id', $case->order_line_id)->first();
        $pick = DB::table('order_preparation_picks')->where('order_line_id', $line->id)->orderBy('picked_at')->first();
        $lot = json_decode($pick->sources, true)[0]['lot_id'];
        $hash = DB::table('stock_lots')->where('id', $lot)->value('terms_hash');
        $id = Records::id();
        $inventory = app(Inventory::class);
        $reservation = $inventory->reserveReplacement($line->offer_id, $hash, (int) $case->quantity, $id, null, $actor);
        $identifiers = DB::table('inventory_units')
            ->where('reservation_id', $reservation['id'])
            ->orderBy('identifier')
            ->pluck('identifier')
            ->all();
        $inventory->consume($reservation['id'], $case->number, $actor);
        [$hashCode, $secret] = $this->code($id);

        return $this->insert(
            $case,
            'delivery',
            $data['warehouse_id'],
            (int) $case->quantity,
            $identifiers,
            [
                'id' => $id,
                'reservation_id' => $reservation['id'],
                'packages' => $data['packages'],
                'code_hash' => $hashCode,
                'code_secret' => $secret,
                'code_expires_at' => CarbonImmutable::now()->addHours((int) config('kora.handover_code_hours', 72)),
            ],
            $actor,
        );
    }

    /**
     * The customer's repaired unit (or the unit that could not be repaired) goes back to them: it leaves stock
     * to its owner and a courier hands it over with a one-time code.
     */
    public function createRepairReturn(string $caseId, array $data, User $actor): array
    {
        $case = Records::row('after_sales_cases', $caseId);
        if ($case->status !== 'accepted' || $case->resolution !== 'repair') {
            AfterSales::fail('case', 'CASE_STATE');
        }
        $repair = DB::table('after_sales_repairs')
            ->where('id', $data['repair_id'])
            ->where('case_id', $case->id)
            ->lockForUpdate()
            ->first();
        abort_unless($repair, 404);
        $this->warehouse($data['warehouse_id']);
        $location = DB::table('inventory_balances AS b')
            ->join('stock_locations AS l', 'l.id', '=', 'b.location_id')
            ->where('b.id', $repair->balance_id)
            ->value('l.warehouse_id');
        if ($location !== $data['warehouse_id']) {
            AfterSales::fail('warehouse_id', 'REPAIR_NOT_AT_WAREHOUSE');
        }
        $id = Records::id();
        app(AfterSalesRepairs::class)->issue($repair, $id, $actor);
        [$hashCode, $secret] = $this->code($id);

        return $this->insert(
            $case,
            'delivery',
            $data['warehouse_id'],
            (int) $repair->quantity,
            json_decode($repair->identifiers, true),
            [
                'id' => $id,
                'repair_id' => $repair->id,
                'packages' => $data['packages'],
                'code_hash' => $hashCode,
                'code_secret' => $secret,
                'code_expires_at' => CarbonImmutable::now()->addHours((int) config('kora.handover_code_hours', 72)),
            ],
            $actor,
        );
    }

    private function insert(
        object $case,
        string $direction,
        string $warehouse,
        int $quantity,
        array $identifiers,
        array $extra,
        User $actor,
    ): array {
        if (
            DB::table('after_sales_trips')
                ->where('case_id', $case->id)
                ->where('direction', $direction)
                ->whereIn('status', self::LIVE)
                ->exists()
        ) {
            AfterSales::fail('case', 'TRIP_EXISTS');
        }
        $id = $extra['id'] ?? Records::id();
        DB::table('after_sales_trips')->insert([
            ...$extra,
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('after_sales_trip', 'TRS-', 7),
            'case_id' => $case->id,
            'direction' => $direction,
            'warehouse_id' => $warehouse,
            'quantity' => $quantity,
            'identifiers' => Records::json($identifiers),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->record(
            $id,
            $direction === 'pickup'
                ? 'pickup_created'
                : (isset($extra['repair_id'])
                    ? 'repair_issued'
                    : 'exchange_issued'),
            $actor,
            [
                'quantity' => $quantity,
                'identifiers' => $identifiers,
            ],
        );

        return $this->dispatchView(Records::row('after_sales_trips', $id, false));
    }

    private function warehouse(string $id): void
    {
        if (! DB::table('warehouses')->where('id', $id)->where('active', true)->exists()) {
            AfterSales::fail('warehouse_id', 'WAREHOUSE_INACTIVE');
        }
    }

    private function lock(string $id, int $version, array $statuses): object
    {
        $initial = Records::row('after_sales_trips', $id, false);
        Records::row('after_sales_cases', $initial->case_id);
        $trip = Records::row('after_sales_trips', $id);
        abort_unless((int) $trip->version === $version, 409);
        if (! in_array($trip->status, $statuses, true)) {
            AfterSales::fail('trip', 'CASE_STATE');
        }

        return $trip;
    }

    private function owned(object $trip, User $courier): void
    {
        abort_unless((int) $trip->courier_id === $courier->id, 404);
    }

    public function assign(string $id, array $data, User $actor): array
    {
        $trip = $this->lock($id, $data['version'], ['unassigned', 'accepted']);
        if ($trip->courier_id !== null && (int) $trip->courier_id === (int) $data['courier_id']) {
            DeliveryMissions::fail('courier_id', 'ALREADY_ASSIGNED');
        }
        app(Couriers::class)->assignable((int) $data['courier_id']);
        $this->update($trip, ['courier_id' => $data['courier_id'], 'status' => 'accepted']);
        $this->record($trip->id, 'assigned', $actor, ['courier_id' => (int) $data['courier_id']]);

        return $this->dispatchView(Records::row('after_sales_trips', $trip->id, false));
    }

    /**
     * Cancelling a pickup is possible while nothing was taken; an exchange shipment once issued stays a stock
     * fact and is not cancelled here.
     */
    public function cancel(string $id, array $data, User $actor): array
    {
        $trip = $this->lock($id, $data['version'], ['unassigned', 'accepted']);
        if ($trip->direction !== 'pickup') {
            AfterSales::fail('trip', 'CASE_STATE');
        }
        $this->update($trip, ['status' => 'cancelled', 'cancel_reason' => $data['reason']]);
        $this->record($trip->id, 'cancelled', $actor, ['reason' => $data['reason']]);

        return $this->dispatchView(Records::row('after_sales_trips', $trip->id, false));
    }

    /**
     * Pickup trip: the courier checks quantity and serials at the customer's door. Delivery trip: the courier
     * takes the exchange parcels at the depot.
     */
    public function take(string $id, array $data, User $courier): array
    {
        $trip = $this->lock($id, $data['version'], ['accepted']);
        $this->owned($trip, $courier);
        if ($trip->direction === 'pickup') {
            $identifiers = $data['identifiers'] ?? [];
            sort($identifiers);
            $expected = json_decode($trip->identifiers, true);
            sort($expected);
            if ($data['quantity'] !== (int) $trip->quantity || ($expected && $identifiers !== $expected)) {
                DeliveryMissions::fail('items', 'ITEMS_MISMATCH');
            }
        } elseif ($data['packages'] !== (int) $trip->packages) {
            DeliveryMissions::fail('packages', 'PACKAGES_MISMATCH');
        }
        $this->update($trip, [
            'status' => 'in_transit',
            'custodian_id' => $courier->id,
            'picked_at' => now(),
            'packages' => $data['packages'],
        ]);
        $this->record($trip->id, 'taken', $courier, ['packages' => $data['packages']]);

        return $this->courierView(Records::row('after_sales_trips', $trip->id, false), $courier);
    }

    /** Pickup trip: returned items handed to the depot's stock team, who then receive the return. */
    public function drop(string $id, array $data, User $courier): array
    {
        $trip = $this->lock($id, $data['version'], ['in_transit']);
        $this->owned($trip, $courier);
        if ($trip->direction !== 'pickup') {
            AfterSales::fail('trip', 'CASE_STATE');
        }
        if ($data['packages'] !== (int) $trip->packages) {
            DeliveryMissions::fail('packages', 'PACKAGES_MISMATCH');
        }
        $this->update($trip, ['status' => 'completed', 'custodian_id' => null, 'completed_at' => now()]);
        $this->record($trip->id, 'dropped', $courier, ['packages' => $data['packages']]);

        return $this->courierView(Records::row('after_sales_trips', $trip->id, false), $courier);
    }

    /**
     * Delivery trip: handover with the customer's code. A wrong code is recorded and counted though the request
     * fails, so the result is returned rather than thrown.
     */
    public function handover(string $id, array $data, User $courier): array
    {
        $trip = $this->lock($id, $data['version'], ['in_transit']);
        $this->owned($trip, $courier);
        if ($trip->direction !== 'delivery') {
            AfterSales::fail('trip', 'CASE_STATE');
        }
        if (CarbonImmutable::parse($trip->code_expires_at, 'UTC')->isPast()) {
            DeliveryMissions::fail('code', 'CODE_EXPIRED');
        }
        $max = (int) config('kora.handover_code_attempts', 5);
        if ($trip->code_failures >= $max) {
            DeliveryMissions::fail('code', 'CODE_LOCKED');
        }
        if (! hash_equals($trip->code_hash, $this->hash($trip->id, $data['code']))) {
            $failures = $trip->code_failures + 1;
            $this->update($trip, ['code_failures' => $failures]);
            $this->record($trip->id, 'code_rejected', $courier, ['failures' => $failures]);

            return [
                'rejected' => $failures >= $max ? 'CODE_LOCKED' : 'CODE_INVALID',
                'attempts_left' => max(0, $max - $failures),
                'trip' => $this->courierView(Records::row('after_sales_trips', $trip->id, false), $courier),
            ];
        }
        $this->update($trip, ['status' => 'completed', 'custodian_id' => null, 'completed_at' => now()]);
        $this->record($trip->id, 'delivered', $courier, []);
        if ($trip->repair_id) {
            app(AfterSalesRepairs::class)->delivered($trip->repair_id);
        }

        return $this->courierView(Records::row('after_sales_trips', $trip->id, false), $courier);
    }

    /** True once the exchange item reached the customer: the case can then be resolved. */
    public function exchangeDelivered(string $caseId): bool
    {
        return DB::table('after_sales_trips')
            ->where('case_id', $caseId)
            ->where('direction', 'delivery')
            ->whereNull('repair_id')
            ->where('status', 'completed')
            ->exists();
    }

    /** @return array{0: string, 1: string} */
    private function code(string $id): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [$this->hash($id, $code), Crypt::encryptString($code)];
    }

    private function hash(string $id, string $code): string
    {
        return hash_hmac('sha256', 'after-sales|'.$id.'|'.$code, (string) config('app.key'));
    }

    private function update(object $trip, array $changes): void
    {
        DB::table('after_sales_trips')
            ->where('id', $trip->id)
            ->update([...$changes, 'version' => $trip->version + 1, 'updated_at' => now()]);
    }

    private function record(string $id, string $action, User $actor, array $data): void
    {
        DB::table('after_sales_trip_events')->insert([
            'trip_id' => $id,
            'action' => $action,
            'actor_id' => $actor->id,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
        Records::audit($actor, 'after_sales.trip_'.$action, $id, $data);
    }

    /** Customer address of the case's order: where to fetch or deliver. */
    public function place(object $trip, bool $details): array
    {
        $case = DB::table('after_sales_cases')->where('id', $trip->case_id)->first();
        $order = DB::table('orders')->where('id', $case->order_id)->first();
        $address = json_decode($order->address_snapshot, true);
        $delivery = json_decode($order->delivery_snapshot, true);

        return [
            'type' => 'customer',
            'district' => $address['district'] ?? null,
            'zone_id' => $delivery['zone_id'] ?? null,
            ...$details
                ? [
                    'landmark' => $address['landmark'] ?? null,
                    'instructions' => $address['instructions'] ?? null,
                    'latitude' => $address['latitude'] ?? null,
                    'longitude' => $address['longitude'] ?? null,
                    'contact_name' => $address['contact_name'] ?? null,
                    'contact_phone' => $address['contact_phone'] ?? null,
                ]
                : [],
        ];
    }

    public function items(object $trip): array
    {
        $case = DB::table('after_sales_cases')->where('id', $trip->case_id)->first();
        $line = DB::table('order_lines')->where('id', $case->order_line_id)->first();
        $snapshot = json_decode($line->snapshot, true);

        return [
            'name' => $snapshot['name'][app()->getLocale()] ?? $snapshot['name']['fr'],
            'sku' => $snapshot['sku'],
            'quantity' => (int) $trip->quantity,
            'identifiers' => json_decode($trip->identifiers, true),
        ];
    }

    public function dispatchView(object $trip): array
    {
        $case = DB::table('after_sales_cases')
            ->where('id', $trip->case_id)
            ->first(['id', 'number']);
        $warehouse = DB::table('warehouses')
            ->where('id', $trip->warehouse_id)
            ->first(['code', 'name', 'address']);

        return [
            'id' => $trip->id,
            'number' => $trip->number,
            'case' => ['id' => $case->id, 'number' => $case->number],
            'direction' => $trip->direction,
            'status' => $trip->status,
            'version' => (int) $trip->version,
            'courier' => $trip->courier_id === null
                    ? null
                    : [
                        'user_id' => (int) $trip->courier_id,
                        ...app(Couriers::class)->publicIdentity((int) $trip->courier_id) ?? [],
                    ],
            'custody' => $trip->custodian_id === null ? ($trip->status === 'completed' ? 'done' : 'start') : 'courier',
            'warehouse' => ['code' => $warehouse->code, 'name' => $warehouse->name, 'address' => $warehouse->address],
            'customer' => $this->place($trip, false),
            'items' => $this->items($trip),
            'packages' => $trip->packages === null ? null : (int) $trip->packages,
            'code' => $trip->direction === 'delivery'
                    ? [
                        'expires_at' => Records::iso($trip->code_expires_at),
                        'attempts_left' => max(
                            0,
                            (int) config('kora.handover_code_attempts', 5) - (int) $trip->code_failures,
                        ),
                    ]
                    : null,
            'picked_at' => Records::iso($trip->picked_at),
            'completed_at' => Records::iso($trip->completed_at),
            'cancel_reason' => $trip->cancel_reason,
        ];
    }

    /** Courier view: address and contact only while the trip is live and theirs; never the customer's code. */
    public function courierView(object $trip, User $courier): array
    {
        $base = [
            'id' => $trip->id,
            'number' => $trip->number,
            'direction' => $trip->direction,
            'status' => $trip->status,
            'version' => (int) $trip->version,
        ];
        if (! in_array($trip->status, self::LIVE, true) || (int) $trip->courier_id !== $courier->id) {
            return $base;
        }
        $warehouse = DB::table('warehouses')
            ->where('id', $trip->warehouse_id)
            ->first(['code', 'name', 'address']);

        return [
            ...$base,
            'holding' => (int) $trip->custodian_id === $courier->id,
            'warehouse' => ['code' => $warehouse->code, 'name' => $warehouse->name, 'address' => $warehouse->address],
            'customer' => $this->place($trip, true),
            'items' => $this->items($trip),
            'packages' => $trip->packages === null ? null : (int) $trip->packages,
        ];
    }

    /** Customer view inside their case: courier's public identity and the exchange handover code. */
    public function customerView(object $trip): array
    {
        $live = in_array($trip->status, self::LIVE, true);

        return [
            'number' => $trip->number,
            'direction' => $trip->direction,
            'status' => $trip->status,
            'courier' => $live && $trip->courier_id !== null
                    ? app(Couriers::class)->publicIdentity((int) $trip->courier_id)
                    : null,
            'handover_code' => $live &&
                $trip->direction === 'delivery' &&
                $trip->code_failures < (int) config('kora.handover_code_attempts', 5)
                    ? Crypt::decryptString($trip->code_secret)
                    : null,
            'completed_at' => Records::iso($trip->completed_at),
        ];
    }
}
