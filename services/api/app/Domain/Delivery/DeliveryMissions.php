<?php

namespace App\Domain\Delivery;

use App\Domain\Orders\OrderPreparation;
use App\Domain\Orders\Orders;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Delivery of a prepared order by a courier. The mission follows who holds the parcels (warehouse or a courier),
 * and the order counts as delivered only after the customer's one-time code, or a supervised alternative, at
 * the door. A reassignment bumps the version so that an earlier courier's device cannot complete the mission.
 */
final class DeliveryMissions
{
    public const INCIDENTS = [
        'customer_absent',
        'address_unreachable',
        'item_missing',
        'damaged',
        'vehicle_unavailable',
        'pickup_discrepancy',
        'other',
    ];

    public const ALTERNATIVES = ['code_unavailable', 'code_locked', 'recipient_delegate', 'other'];

    private const LIVE = ['unassigned', 'assigned', 'accepted', 'in_transit', 'incident', 'awaiting_approval'];

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('missions.'.$code)]);
    }

    /** Lock order: order → mission → incident/alternative → courier profile, as the other order writers. */
    private function lock(string $missionId): array
    {
        $initial = Records::row('delivery_missions', $missionId, false);
        $order = Records::row('orders', $initial->order_id);
        $mission = Records::row('delivery_missions', $missionId);

        return [$order, $mission];
    }

    private function expect(object $mission, int $version, array $statuses): void
    {
        abort_unless((int) $mission->version === $version, 409);
        if (! in_array($mission->status, $statuses, true)) {
            self::fail('mission', 'MISSION_STATE');
        }
    }

    /** Couriers only ever reach their own current missions; anything else looks absent. */
    private function owned(object $mission, User $courier): void
    {
        abort_unless((int) $mission->courier_id === $courier->id, 404);
    }

    public function create(string $orderId, array $data, User $actor): array
    {
        $order = Records::row('orders', $orderId);
        if ($order->status !== 'paid' || $order->closed_at || $order->delivered_at) {
            self::fail('order', 'ORDER_NOT_DELIVERABLE');
        }
        if ($order->preparation_status !== 'ready' || ! $order->prepared_at) {
            self::fail('order', 'ORDER_NOT_READY');
        }
        if ($this->live($order->id)) {
            self::fail('order', 'MISSION_EXISTS');
        }
        $id = Records::id();
        [$hash, $secret] = $this->code($id);
        DB::table('delivery_missions')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('delivery_mission', 'MIS-', 7),
            'order_id' => $order->id,
            'status' => 'unassigned',
            'packages' => $order->preparation_packages,
            // Zero when every unit is to be received at a shop on the way (direct collection).
            'depot_packages' => $order->preparation_packages,
            'code_hash' => $hash,
            'code_secret' => $secret,
            'code_expires_at' => $this->codeExpiry(),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->record($order, $id, 'mission_created', $actor, ['note' => $data['note'] ?? null]);

        return $this->dispatchView(Records::row('delivery_missions', $id, false));
    }

    public function assign(string $missionId, array $data, User $actor): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->expect($mission, $data['version'], ['unassigned', 'assigned', 'accepted', 'in_transit', 'incident']);
        $courier = (int) $data['courier_id'];
        if ($mission->courier_id !== null && (int) $mission->courier_id === $courier) {
            self::fail('courier_id', 'ALREADY_ASSIGNED');
        }
        // Parcels already out with a courier change hands physically: the handover must be stated.
        if ($mission->custodian_id !== null && blank($data['note'] ?? null)) {
            self::fail('note', 'CUSTODY_NOTE_REQUIRED');
        }
        if ($mission->status === 'incident') {
            $this->closeIncident($mission, 'reassigned', $data['note'] ?? 'Reassigned', $actor);
        }
        app(Couriers::class)->assignable($courier);
        $this->update($mission, [
            'courier_id' => $courier,
            'acceptance_required' => $data['acceptance_required'],
            'sequence' => $data['sequence'] ?? null,
            'status' => $data['acceptance_required'] ? 'assigned' : 'accepted',
            'assignment' => $mission->assignment + 1,
        ]);
        $this->record($order, $mission->id, 'mission_assigned', $actor, [
            'courier_id' => $courier,
            'previous_courier_id' => $mission->courier_id === null ? null : (int) $mission->courier_id,
            'custodian_id' => $mission->custodian_id === null ? null : (int) $mission->custodian_id,
            'acceptance_required' => $data['acceptance_required'],
            'note' => $data['note'] ?? null,
        ]);

        return $this->dispatchView(Records::row('delivery_missions', $mission->id, false));
    }

    public function cancel(string $missionId, array $data, User $actor): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->expect($mission, $data['version'], ['unassigned', 'assigned', 'accepted', 'incident']);
        if ($mission->custodian_id !== null) {
            self::fail('mission', 'PARCELS_WITH_COURIER');
        }
        if ($mission->status === 'incident') {
            $this->closeIncident($mission, 'cancelled', $data['reason'], $actor);
        }
        $this->update($mission, ['status' => 'cancelled', 'cancel_reason' => $data['reason']]);
        $this->record($order, $mission->id, 'mission_cancelled', $actor, ['reason' => $data['reason']]);

        return $this->dispatchView(Records::row('delivery_missions', $mission->id, false));
    }

    /** A new code replaces an expired or locked one; earlier codes stop working at once. */
    public function renewCode(string $missionId, array $data, User $actor): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->expect($mission, $data['version'], ['unassigned', 'assigned', 'accepted', 'in_transit', 'incident']);
        [$hash, $secret] = $this->code($mission->id);
        $this->update($mission, [
            'code_hash' => $hash,
            'code_secret' => $secret,
            'code_expires_at' => $this->codeExpiry(),
            'code_failures' => 0,
        ]);
        $this->record($order, $mission->id, 'code_renewed', $actor, [
            'previous_failures' => (int) $mission->code_failures,
        ]);

        return $this->dispatchView(Records::row('delivery_missions', $mission->id, false));
    }

    public function resolveIncident(string $missionId, array $data, User $actor): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->expect($mission, $data['version'], ['incident']);
        $incident = $this->closeIncident($mission, $data['action'], $data['note'], $actor);
        $changes = match ($data['action']) {
            // Same courier tries again: back on the road if they hold the parcels, otherwise back to pickup.
            'retry' => [
                'status' => $mission->custodian_id !== null && (int) $mission->custodian_id === (int) $mission->courier_id
                        ? 'in_transit'
                        : 'accepted',
            ],
            // Everything the courier carried, shop units included, is now at the depot to be picked up again.
            'return_to_warehouse' => [
                'depot_packages' => $mission->packages,
                'depot_picked_at' => null,
                'status' => 'unassigned',
                'courier_id' => null,
                'custodian_id' => null,
                'sequence' => null,
            ],
        };
        $this->update($mission, $changes);
        $this->record($order, $mission->id, 'incident_resolved', $actor, [
            'incident_id' => $incident->id,
            'action' => $data['action'],
            'note' => $data['note'],
        ]);

        return $this->dispatchView(Records::row('delivery_missions', $mission->id, false));
    }

    public function reviewAlternative(string $missionId, array $data, User $actor, bool $approve): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->expect($mission, $data['version'], ['awaiting_approval']);
        $request = DB::table('delivery_alternative_requests')
            ->where('mission_id', $mission->id)
            ->where('status', 'pending')
            ->lockForUpdate()
            ->first();
        abort_unless($request, 404);
        // Supervised means someone other than the courier who asks for it.
        if ((int) $request->courier_id === $actor->id) {
            self::fail('mission', 'SUPERVISOR_REQUIRED');
        }
        DB::table('delivery_alternative_requests')
            ->where('id', $request->id)
            ->update([
                'status' => $approve ? 'approved' : 'rejected',
                'reviewed_by' => $actor->id,
                'review_note' => $data['note'],
                'reviewed_at' => now(),
                'updated_at' => now(),
            ]);
        if ($approve) {
            $this->deliver($order, $mission, 'alternative', $actor, ['alternative_id' => $request->id]);
        } else {
            $this->update($mission, ['status' => 'in_transit']);
            $this->record($order, $mission->id, 'alternative_rejected', $actor, [
                'alternative_id' => $request->id,
                'note' => $data['note'],
            ]);
        }

        return $this->dispatchView(Records::row('delivery_missions', $mission->id, false));
    }

    public function accept(string $missionId, array $data, User $courier): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->owned($mission, $courier);
        $this->expect($mission, $data['version'], ['assigned']);
        $this->update($mission, ['status' => 'accepted']);
        $this->record($order, $mission->id, 'mission_accepted', $courier, []);

        return $this->courierView(Records::row('delivery_missions', $mission->id, false), $courier);
    }

    public function decline(string $missionId, array $data, User $courier): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->owned($mission, $courier);
        $this->expect($mission, $data['version'], ['assigned']);
        $this->update($mission, ['status' => 'unassigned', 'courier_id' => null, 'sequence' => null]);
        $this->record($order, $mission->id, 'mission_declined', $courier, ['reason' => $data['reason']]);

        return ['id' => $mission->id, 'number' => $mission->number, 'status' => 'declined'];
    }

    /**
     * Parcels taken either from the depot (once, the prepared count) or from the courier who held them before a
     * reassignment. Units bought at a shop on the way are taken through the shop receipt, not here.
     */
    public function pickup(string $missionId, array $data, User $courier): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->owned($mission, $courier);
        $this->expect($mission, $data['version'], ['accepted', 'in_transit']);
        $previous =
            $mission->custodian_id !== null && (int) $mission->custodian_id !== $courier->id
                ? (int) $mission->custodian_id
                : null;
        $depotPending = (int) $mission->depot_packages > 0 && $mission->depot_picked_at === null;
        if ($previous === null && ! $depotPending) {
            self::fail('mission', 'NOTHING_TO_PICK_UP');
        }
        // What the previous courier holds: everything except depot parcels nobody has taken yet.
        $expected =
            $previous !== null
                ? (int) $mission->packages - ($depotPending ? (int) $mission->depot_packages : 0)
                : (int) $mission->depot_packages;
        // A missing parcel is an incident to report, not something to confirm away.
        if ($data['packages'] !== $expected) {
            self::fail('packages', 'PACKAGES_MISMATCH');
        }
        $this->update($mission, [
            'status' => 'in_transit',
            'custodian_id' => $courier->id,
            ...$previous === null ? ['depot_picked_at' => now()] : [],
        ]);
        $this->record($order, $mission->id, 'parcels_picked_up', $courier, [
            'packages' => $data['packages'],
            'from' => $previous === null ? 'warehouse' : 'courier',
            'from_courier_id' => $previous,
        ]);

        return $this->courierView(Records::row('delivery_missions', $mission->id, false), $courier);
    }

    public function reportIncident(string $missionId, array $data, User $courier): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->owned($mission, $courier);
        $this->expect($mission, $data['version'], ['accepted', 'in_transit']);
        $id = Records::id();
        DB::table('delivery_mission_incidents')->insert([
            'id' => $id,
            'mission_id' => $mission->id,
            'kind' => $data['kind'],
            'note' => $data['note'],
            'reported_by' => $courier->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->update($mission, ['status' => 'incident']);
        $this->record($order, $mission->id, 'incident_reported', $courier, [
            'incident_id' => $id,
            'kind' => $data['kind'],
        ]);

        return $this->courierView(Records::row('delivery_missions', $mission->id, false), $courier);
    }

    /**
     * Handover with the customer's code. A wrong code is recorded and counted even though the request fails, so
     * the result is returned rather than thrown: an exception would roll the attempt back with the transaction.
     */
    public function handover(string $missionId, array $data, User $courier): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->owned($mission, $courier);
        $this->expect($mission, $data['version'], ['in_transit']);
        $this->deliverable($order);
        $this->complete($order, $mission);
        $control = $this->control($order, $mission, $data);
        if (CarbonImmutable::parse($mission->code_expires_at, 'UTC')->isPast()) {
            self::fail('code', 'CODE_EXPIRED');
        }
        if ($mission->code_failures >= $this->maxAttempts()) {
            self::fail('code', 'CODE_LOCKED');
        }
        $accepted = hash_equals($mission->code_hash, $this->hash($mission->id, $data['code']));
        DB::table('delivery_handover_attempts')->insert([
            'mission_id' => $mission->id,
            'courier_id' => $courier->id,
            'accepted' => $accepted,
            'created_at' => now(),
        ]);
        if (! $accepted) {
            $failures = $mission->code_failures + 1;
            $this->update($mission, ['code_failures' => $failures]);
            $this->record($order, $mission->id, 'code_rejected', $courier, ['failures' => $failures]);

            return [
                'rejected' => $failures >= $this->maxAttempts() ? 'CODE_LOCKED' : 'CODE_INVALID',
                'attempts_left' => max(0, $this->maxAttempts() - $failures),
                'mission' => $this->courierView(Records::row('delivery_missions', $mission->id, false), $courier),
            ];
        }
        $this->deliver($order, $mission, 'code', $courier, ['control' => $control]);

        return $this->courierView(Records::row('delivery_missions', $mission->id, false), $courier);
    }

    public function requestAlternative(string $missionId, array $data, User $courier): array
    {
        [$order, $mission] = $this->lock($missionId);
        $this->owned($mission, $courier);
        $this->expect($mission, $data['version'], ['in_transit']);
        $this->deliverable($order);
        $this->complete($order, $mission);
        $control = $this->control($order, $mission, $data);
        $id = Records::id();
        DB::table('delivery_alternative_requests')->insert([
            'id' => $id,
            'mission_id' => $mission->id,
            'courier_id' => $courier->id,
            'reason' => $data['reason'],
            'note' => $data['note'],
            'control' => Records::json($control),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->update($mission, ['status' => 'awaiting_approval']);
        $this->record($order, $mission->id, 'alternative_requested', $courier, [
            'alternative_id' => $id,
            'reason' => $data['reason'],
        ]);

        return $this->courierView(Records::row('delivery_missions', $mission->id, false), $courier);
    }

    private function deliverable(object $order): void
    {
        if ($order->status !== 'paid' || $order->closed_at || $order->delivered_at) {
            self::fail('order', 'ORDER_NOT_DELIVERABLE');
        }
    }

    /** Nothing may be handed over while depot parcels, shop receipts or any ordered unit are still missing. */
    private function complete(object $order, object $mission): void
    {
        if ((int) $mission->depot_packages > 0 && $mission->depot_picked_at === null) {
            self::fail('mission', 'DEPOT_PICKUP_PENDING');
        }
        if (DB::table('supplier_collections')->where('order_id', $order->id)->where('status', 'unassigned')->exists()) {
            self::fail('mission', 'SHOP_RECEIPT_PENDING');
        }
        $picked = $this->prepared($order->id);
        foreach (DB::table('order_lines')->where('order_id', $order->id)->get() as $line) {
            if (($picked[$line->id]['quantity'] ?? 0) < $line->quantity - $line->cancelled_quantity) {
                self::fail('items', 'UNITS_MISSING');
            }
        }
    }

    /** Called by the shop receipt of a direct collection: the received parcels join the mission's load. */
    public function addShopParcels(
        object $order,
        object $mission,
        int $packages,
        User $actor,
        string $collectionId,
    ): void {
        $this->update($mission, [
            'packages' => $mission->packages + $packages,
            'custodian_id' => $mission->courier_id,
            'status' => 'in_transit',
        ]);
        $this->record($order, $mission->id, 'shop_parcels_received', $actor, [
            'collection_id' => $collectionId,
            'packages' => $packages,
        ]);
    }

    /**
     * Per-item check at the door: every prepared unit, with its exact serials, and every parcel. A difference is
     * reported as an incident; it is never confirmed as a complete delivery.
     */
    private function control(object $order, object $mission, array $data): array
    {
        if ($data['packages'] !== (int) $mission->packages) {
            self::fail('packages', 'PACKAGES_MISMATCH');
        }
        $expected = $this->prepared($order->id);
        $given = [];
        foreach ($data['items'] as $item) {
            $identifiers = $item['identifiers'] ?? [];
            sort($identifiers);
            $given[$item['order_line_id']] = ['quantity' => (int) $item['quantity'], 'identifiers' => $identifiers];
        }
        ksort($given);
        $wanted = array_map(
            fn ($line) => ['quantity' => $line['quantity'], 'identifiers' => $line['identifiers']],
            $expected,
        );
        if ($given !== $wanted) {
            self::fail('items', 'ITEMS_MISMATCH');
        }

        return ['packages' => (int) $mission->packages, 'items' => $given];
    }

    /** What actually left the warehouse for this order and still goes, keyed by line. */
    private function prepared(string $orderId): array
    {
        return app(OrderPreparation::class)->issued($orderId);
    }

    private function deliver(object $order, object $mission, string $method, User $actor, array $data): void
    {
        $this->update($mission, [
            'status' => 'delivered',
            'delivered_at' => now(),
            'delivery_method' => $method,
            'custodian_id' => null,
        ]);
        // The verified handover is the only writer of the order's delivery date; loyalty waits for it.
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['delivered_at' => now(), 'updated_at' => now()]);
        $this->record($order, $mission->id, 'delivered', $actor, ['method' => $method, ...$data]);
    }

    private function closeIncident(object $mission, string $resolution, string $note, User $actor): object
    {
        $incident = DB::table('delivery_mission_incidents')
            ->where('mission_id', $mission->id)
            ->where('status', 'open')
            ->lockForUpdate()
            ->first();
        abort_unless($incident, 404);
        DB::table('delivery_mission_incidents')
            ->where('id', $incident->id)
            ->update([
                'status' => 'resolved',
                'resolution' => $resolution,
                'resolution_note' => $note,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'version' => $incident->version + 1,
                'updated_at' => now(),
            ]);

        return $incident;
    }

    private function update(object $mission, array $changes): void
    {
        DB::table('delivery_missions')
            ->where('id', $mission->id)
            ->update([...$changes, 'version' => $mission->version + 1, 'updated_at' => now()]);
    }

    private function record(object $order, string $missionId, string $action, User $actor, array $data): void
    {
        DB::table('delivery_mission_events')->insert([
            'mission_id' => $missionId,
            'action' => $action,
            'actor_id' => $actor->id,
            'data' => Records::json($data),
            'created_at' => now(),
        ]);
        app(Orders::class)->event($order->id, 'delivery_'.$action, $actor, ['mission_id' => $missionId, ...$data]);
        Records::audit($actor, 'delivery.'.$action, $missionId, $data);
    }

    public function live(string $orderId): ?object
    {
        return DB::table('delivery_missions')->where('order_id', $orderId)->whereIn('status', self::LIVE)->first();
    }

    /** @return array{0: string, 1: string} hash for checking, encrypted copy for the customer's own view */
    private function code(string $missionId): array
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        return [$this->hash($missionId, $code), Crypt::encryptString($code)];
    }

    private function hash(string $missionId, string $code): string
    {
        return hash_hmac('sha256', $missionId.'|'.$code, (string) config('app.key'));
    }

    private function codeExpiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addHours((int) config('kora.handover_code_hours', 72));
    }

    private function maxAttempts(): int
    {
        return (int) config('kora.handover_code_attempts', 5);
    }

    private function codeState(object $mission): array
    {
        $expired = CarbonImmutable::parse($mission->code_expires_at, 'UTC')->isPast();

        return [
            'expires_at' => Records::iso($mission->code_expires_at),
            'expired' => $expired,
            'locked' => $mission->code_failures >= $this->maxAttempts(),
            'attempts_left' => max(0, $this->maxAttempts() - (int) $mission->code_failures),
        ];
    }

    /** Back-office view: the code itself never appears, only whether it can still be used. */
    public function dispatchView(object $mission): array
    {
        $order = DB::table('orders')->where('id', $mission->order_id)->first();
        $address = json_decode($order->address_snapshot, true);

        return [
            'id' => $mission->id,
            'number' => $mission->number,
            'status' => $mission->status,
            'version' => (int) $mission->version,
            'order' => [
                'id' => $order->id,
                'number' => $order->number,
                'slot' => $this->slot($order),
                'district' => $address['district'],
            ],
            'courier' => $mission->courier_id === null
                    ? null
                    : [
                        'user_id' => (int) $mission->courier_id,
                        ...app(Couriers::class)->publicIdentity((int) $mission->courier_id) ?? [],
                    ],
            'custody' => $mission->custodian_id === null ? 'warehouse' : 'courier',
            'custodian_id' => $mission->custodian_id === null ? null : (int) $mission->custodian_id,
            'acceptance_required' => (bool) $mission->acceptance_required,
            'sequence' => $mission->sequence === null ? null : (int) $mission->sequence,
            'assignment' => (int) $mission->assignment,
            'packages' => (int) $mission->packages,
            'depot_packages' => (int) $mission->depot_packages,
            'depot_picked_at' => Records::iso($mission->depot_picked_at),
            'direct_collections' => DB::table('supplier_collections')
                ->where('order_id', $mission->order_id)
                ->orderBy('number')
                ->get()
                ->map(fn ($c) => ['id' => $c->id, 'number' => $c->number, 'status' => $c->status])
                ->values(),
            'code' => $this->codeState($mission),
            'delivery_method' => $mission->delivery_method,
            'delivered_at' => Records::iso($mission->delivered_at),
            'cancel_reason' => $mission->cancel_reason,
            'incidents' => DB::table('delivery_mission_incidents')
                ->where('mission_id', $mission->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($i) => $this->incidentDto($i))
                ->values(),
            'photos' => app(MissionProofs::class)->photos($mission->id),
            'alternatives' => DB::table('delivery_alternative_requests')
                ->where('mission_id', $mission->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($a) => $this->alternativeDto($a))
                ->values(),
            'events' => DB::table('delivery_mission_events')
                ->where('mission_id', $mission->id)
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
            'created_at' => Records::iso($mission->created_at),
        ];
    }

    /**
     * Courier view: address, contacts and items only while the mission is live and theirs. Once delivered or
     * cancelled the history keeps the reference and outcome, not the customer's details.
     */
    public function courierView(object $mission, User $courier): array
    {
        $base = [
            'id' => $mission->id,
            'number' => $mission->number,
            'status' => $mission->status,
            'version' => (int) $mission->version,
            'packages' => (int) $mission->packages,
            'delivered_at' => Records::iso($mission->delivered_at),
        ];
        if (! in_array($mission->status, self::LIVE, true) || (int) $mission->courier_id !== $courier->id) {
            return $base;
        }
        $order = DB::table('orders')->where('id', $mission->order_id)->first();
        $address = json_decode($order->address_snapshot, true);
        $locale = app()->getLocale();
        $prepared = $this->prepared($order->id);

        return [
            ...$base,
            'order_number' => $order->number,
            'sequence' => $mission->sequence === null ? null : (int) $mission->sequence,
            'holding_parcels' => (int) $mission->custodian_id === $courier->id,
            'depot' => [
                'packages' => (int) $mission->depot_packages,
                'picked' => $mission->depot_picked_at !== null,
            ],
            // Shops to receive from on the way: what to count, and whether a manager receives instead.
            'shop_receipts' => DB::table('supplier_collections')
                ->where('order_id', $order->id)
                ->where('status', 'unassigned')
                ->orderBy('number')
                ->get()
                ->map(fn ($c) => app(SupplierCollections::class)->directView($c))
                ->values(),
            'pickup' => $mission->custodian_id === null
                    ? ['from' => 'warehouse', 'warehouses' => $this->warehouses($order->id)]
                    : ['from' => (int) $mission->custodian_id === $courier->id ? 'self' : 'courier'],
            'slot' => $this->slot($order),
            'address' => [
                'district' => $address['district'],
                'landmark' => $address['landmark'],
                'instructions' => $address['instructions'] ?? null,
                'latitude' => $address['latitude'] ?? null,
                'longitude' => $address['longitude'] ?? null,
                'contact_name' => $address['contact_name'] ?? null,
                'contact_phone' => $address['contact_phone'] ?? null,
                'alternate_name' => $address['alternate_name'] ?? null,
                'alternate_phone' => $address['alternate_phone'] ?? null,
            ],
            'items' => DB::table('order_lines')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->filter(fn ($line) => isset($prepared[$line->id]))
                ->map(function ($line) use ($locale, $prepared) {
                    $snapshot = json_decode($line->snapshot, true);

                    return [
                        'order_line_id' => $line->id,
                        'name' => $snapshot['name'][$locale] ?? $snapshot['name']['fr'],
                        'sku' => $snapshot['sku'],
                        'quantity' => $prepared[$line->id]['quantity'],
                        'identifiers' => $prepared[$line->id]['identifiers'],
                    ];
                })
                ->values(),
            'code' => $this->codeState($mission),
            // Shown as a QR code for the customer to check the courier; never the handover code.
            'verification' => app(MissionProofs::class)->verification($mission),
            'photos' => count(app(MissionProofs::class)->photos($mission->id)),
            'open_incident' => ($i = DB::table('delivery_mission_incidents')
                ->where('mission_id', $mission->id)
                ->where('status', 'open')
                ->first())
                ? ['kind' => $i->kind, 'created_at' => Records::iso($i->created_at)]
                : null,
        ];
    }

    /** Customer view: mission reference, public courier identity and their own one-time code. */
    public function customerView(object $order): ?array
    {
        $mission =
            $this->live($order->id) ??
            DB::table('delivery_missions')->where('order_id', $order->id)->where('status', 'delivered')->first();
        if (! $mission) {
            return null;
        }
        $live = in_array($mission->status, self::LIVE, true);
        $code = $this->codeState($mission);

        return [
            'reference' => $mission->number,
            'status' => match ($mission->status) {
                'unassigned', 'assigned', 'accepted' => 'scheduled',
                'in_transit', 'awaiting_approval' => 'out_for_delivery',
                'incident' => 'delayed',
                default => 'delivered',
            },
            'courier' => $live && $mission->courier_id !== null
                    ? app(Couriers::class)->publicIdentity((int) $mission->courier_id)
                    : null,
            'handover_code' => $live && ! $code['expired'] && ! $code['locked'] ? Crypt::decryptString($mission->code_secret) : null,
            'handover_code_expires_at' => $live ? $code['expires_at'] : null,
            'delivered_at' => Records::iso($mission->delivered_at),
        ];
    }

    private function slot(object $order): ?array
    {
        $delivery = json_decode($order->delivery_snapshot, true);
        $rule =
            $delivery['slot']['rule_id'] ?? null
                ? DB::table('delivery_slot_rules')->where('id', $delivery['slot']['rule_id'])->first()
                : null;

        return $rule
            ? ['date' => $delivery['slot']['date'], 'starts_at' => $rule->starts_at, 'ends_at' => $rule->ends_at]
            : null;
    }

    private function warehouses(string $orderId): array
    {
        $codes = [];
        foreach (DB::table('order_preparation_picks')->where('order_id', $orderId)->pluck('sources') as $sources) {
            foreach (json_decode($sources, true) as $source) {
                $codes[$source['warehouse_code']] = true;
            }
        }
        ksort($codes);

        return array_keys($codes);
    }

    public function incidentDto(object $i): array
    {
        return [
            'id' => $i->id,
            'kind' => $i->kind,
            'note' => $i->note,
            'status' => $i->status,
            'resolution' => $i->resolution,
            'resolution_note' => $i->resolution_note,
            'reported_by' => (int) $i->reported_by,
            'resolved_by' => $i->resolved_by === null ? null : (int) $i->resolved_by,
            'created_at' => Records::iso($i->created_at),
            'resolved_at' => Records::iso($i->resolved_at),
        ];
    }

    public function alternativeDto(object $a): array
    {
        return [
            'id' => $a->id,
            'courier_id' => (int) $a->courier_id,
            'reason' => $a->reason,
            'note' => $a->note,
            'control' => json_decode($a->control, true),
            'status' => $a->status,
            'reviewed_by' => $a->reviewed_by === null ? null : (int) $a->reviewed_by,
            'review_note' => $a->review_note,
            'created_at' => Records::iso($a->created_at),
            'reviewed_at' => Records::iso($a->reviewed_at),
        ];
    }
}
