<?php

namespace App\Domain\Delivery;

use App\Domain\AfterSales\AfterSalesTrips;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * A courier's day, step by step: where to collect goods (supplier shops, KORA depots, another courier), where
 * to drop or deliver them, in which order. Whether a step is done is always read from the missions and
 * collections themselves (goods held, drop or handover recorded), never ticked by hand. Rounds of one courier
 * are serialised on their courier profile; tasks are read, not locked, so their writers keep their own order.
 */
final class DeliveryRounds
{
    private const COLLECTION_LIVE = ['assigned', 'accepted', 'collected', 'incident'];

    public function create(array $data, User $actor): array
    {
        $courier = (int) $data['courier_id'];
        app(Couriers::class)->assignable($courier);
        if (
            DB::table('delivery_rounds')
                ->where('courier_id', $courier)
                ->where('date', $data['date'])
                ->where('status', 'open')
                ->exists()
        ) {
            DeliveryMissions::fail('date', 'ROUND_EXISTS');
        }
        [$missions, $collections, $trips] = $this->tasks($data, $courier, null);
        $id = Records::id();
        DB::table('delivery_rounds')->insert([
            'id' => $id,
            'number' => app(DocumentNumbers::class)->next('delivery_round', 'TRN-', 7),
            'courier_id' => $courier,
            'date' => $data['date'],
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->insertStops($id, $this->plan($missions, $collections, $courier, $trips), 1);
        Records::audit($actor, 'delivery.round_created', $id, [
            'courier_id' => $courier,
            'date' => $data['date'],
            'missions' => array_column($missions, 'id'),
            'collections' => array_column($collections, 'id'),
            'trips' => array_column($trips, 'id'),
        ]);

        return $this->view(Records::row('delivery_rounds', $id, false), false);
    }

    /**
     * New set of tasks: steps already done stay first, in their order; the rest is planned again from where
     * the courier now stands (goods on board are not collected twice).
     */
    public function replan(string $roundId, array $data, User $actor): array
    {
        $round = $this->lock($roundId, $data['version']);
        $courier = (int) $round->courier_id;
        [$missions, $collections, $trips] = $this->tasks($data, $courier, $round->id);
        $done = array_values(array_filter($this->stops($round), fn ($s) => $s['state'] === 'done'));
        DB::table('delivery_round_stops')
            ->where('round_id', $round->id)
            ->whereNotIn('id', array_column($done, 'id'))
            ->delete();
        foreach ($done as $i => $stop) {
            DB::table('delivery_round_stops')
                ->where('id', $stop['id'])
                ->update(['sequence' => $i + 1, 'updated_at' => now()]);
        }
        $this->insertStops(
            $round->id,
            $this->plan(
                array_values(array_filter($missions, fn ($m) => $m->status !== 'delivered')),
                array_values(array_filter($collections, fn ($c) => in_array($c->status, self::COLLECTION_LIVE, true))),
                $courier,
                array_values(array_filter($trips, fn ($t) => $t->status !== 'completed')),
            ),
            count($done) + 1,
        );
        $this->touch($round);
        Records::audit($actor, 'delivery.round_replanned', $round->id, [
            'missions' => array_column($missions, 'id'),
            'collections' => array_column($collections, 'id'),
        ]);

        return $this->view(Records::row('delivery_rounds', $round->id, false), false);
    }

    /** Manual order from the dispatcher: the same stops, each pickup still before its task's final stop. */
    public function reorder(string $roundId, array $data, User $actor): array
    {
        $round = $this->lock($roundId, $data['version']);
        $current = DB::table('delivery_round_stops')->where('round_id', $round->id)->get()->keyBy('id');
        $given = $data['stop_ids'];
        if (count($given) !== $current->count() || array_diff($given, $current->keys()->all())) {
            DeliveryMissions::fail('stop_ids', 'STOPS_INVALID');
        }
        $finished = [];
        foreach ($given as $id) {
            $stop = $current[$id];
            $task = $stop->mission_id ?? ($stop->trip_id ?? $stop->collection_id);
            if (in_array($stop->kind, ['delivery', 'drop'], true)) {
                $finished[$task] = true;
            } elseif (isset($finished[$task])) {
                DeliveryMissions::fail('stop_ids', 'STOPS_INVALID');
            }
        }
        foreach ($given as $i => $id) {
            DB::table('delivery_round_stops')
                ->where('id', $id)
                ->update(['sequence' => $i + 1, 'origin' => 'manual', 'updated_at' => now()]);
        }
        $this->touch($round);
        Records::audit($actor, 'delivery.round_reordered', $round->id, ['stop_ids' => $given]);

        return $this->view(Records::row('delivery_rounds', $round->id, false), false);
    }

    public function cancel(string $roundId, array $data, User $actor): array
    {
        $round = $this->lock($roundId, $data['version']);
        DB::table('delivery_rounds')
            ->where('id', $round->id)
            ->update([
                'status' => 'cancelled',
                'cancel_reason' => $data['reason'],
                'version' => $round->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'delivery.round_cancelled', $round->id, ['reason' => $data['reason']]);

        return $this->view(Records::row('delivery_rounds', $round->id, false), false);
    }

    private function lock(string $roundId, int $version): object
    {
        $initial = Records::row('delivery_rounds', $roundId, false);
        DB::table('courier_profiles')->where('user_id', $initial->courier_id)->lockForUpdate()->first();
        $round = Records::row('delivery_rounds', $roundId);
        abort_unless((int) $round->version === $version, 409);
        if ($round->status !== 'open') {
            DeliveryMissions::fail('round', 'ROUND_STATE');
        }

        return $round;
    }

    private function touch(object $round): void
    {
        DB::table('delivery_rounds')
            ->where('id', $round->id)
            ->update(['version' => $round->version + 1, 'updated_at' => now()]);
    }

    /**
     * Live tasks of this courier, in no other open round; a task finished within this round may stay listed.
     *
     * @return array{0: list<object>, 1: list<object>}
     */
    private function tasks(array $data, int $courier, ?string $roundId): array
    {
        $missionIds = $data['mission_ids'] ?? [];
        $collectionIds = $data['collection_ids'] ?? [];
        $tripIds = $data['trip_ids'] ?? [];
        if (! $missionIds && ! $collectionIds && ! $tripIds) {
            DeliveryMissions::fail('mission_ids', 'MISSION_NOT_IN_ROUND');
        }
        $missions = $this->check(
            'delivery_missions',
            'mission_id',
            $missionIds,
            $courier,
            $roundId,
            fn ($m) => ! in_array($m->status, ['delivered', 'cancelled'], true),
            ['delivered'],
        );
        $collections = $this->check(
            'supplier_collections',
            'collection_id',
            $collectionIds,
            $courier,
            $roundId,
            fn ($c) => in_array($c->status, self::COLLECTION_LIVE, true),
            ['dropped', 'received'],
        );
        $trips = $this->check(
            'after_sales_trips',
            'trip_id',
            $tripIds,
            $courier,
            $roundId,
            fn ($t) => in_array($t->status, ['accepted', 'in_transit'], true),
            ['completed'],
        );

        return [$missions, $collections, $trips];
    }

    private function check(
        string $table,
        string $column,
        array $ids,
        int $courier,
        ?string $roundId,
        callable $live,
        array $finished,
    ): array {
        $rows = DB::table($table)->whereIn('id', $ids)->orderBy('number')->get();
        if ($rows->count() !== count(array_unique($ids))) {
            DeliveryMissions::fail(str_replace('_id', '_ids', $column), 'MISSION_NOT_IN_ROUND');
        }
        foreach ($rows as $row) {
            $keptHere =
                $roundId !== null &&
                in_array($row->status, $finished, true) &&
                (int) $row->courier_id === $courier &&
                DB::table('delivery_round_stops')->where('round_id', $roundId)->where($column, $row->id)->exists();
            $elsewhere = DB::table('delivery_round_stops AS s')
                ->join('delivery_rounds AS r', 'r.id', '=', 's.round_id')
                ->where('s.'.$column, $row->id)
                ->where('r.status', 'open')
                ->when($roundId, fn ($q) => $q->where('r.id', '!=', $roundId))
                ->exists();
            if ($elsewhere || (! ($live($row) && (int) $row->courier_id === $courier) && ! $keptHere)) {
                DeliveryMissions::fail(str_replace('_id', '_ids', $column), 'MISSION_NOT_IN_ROUND');
            }
        }

        return $rows->all();
    }

    /** Planner tasks for missions ("m:") and collections ("c:"), then stops mapped back to each kind. */
    private function plan(array $missions, array $collections, int $courier, array $trips = []): array
    {
        $tasks = [];
        foreach ($missions as $m) {
            $tasks[] = $this->missionTask($m, $courier);
        }
        foreach ($collections as $c) {
            $tasks[] = $this->collectionTask($c, $courier);
        }
        foreach ($trips as $t) {
            $tasks[] = $this->tripTask($t, $courier);
        }
        $warehouses = collect($collections)->pluck('warehouse_id', 'id');

        return array_map(function ($stop) use ($warehouses) {
            [$type, $id] = explode(':', $stop['mission_id'], 2);
            if ($type === 't') {
                return [
                    'mission_id' => null,
                    'collection_id' => null,
                    'trip_id' => $id,
                    'kind' => $stop['kind'],
                    'warehouse_id' => $stop['warehouse_id'],
                ];
            }
            if ($type === 'm') {
                // A mission's shop stop is the direct receipt of one of its collections.
                return [
                    'mission_id' => $id,
                    'trip_id' => null,
                    'collection_id' => $stop['collection_id'],
                    'kind' => $stop['collection_id'] ? 'collect' : $stop['kind'],
                    'warehouse_id' => $stop['warehouse_id'],
                ];
            }

            return [
                'mission_id' => null,
                'trip_id' => null,
                'collection_id' => $id,
                'kind' => $stop['kind'] === 'pickup' ? 'collect' : 'drop',
                'warehouse_id' => $stop['kind'] === 'pickup' ? null : $warehouses[$id],
            ];
        }, app(RoundPlanner::class)->plan($tasks));
    }

    /**
     * What is left to fetch for a mission: parcels held by a previous courier, depot parcels nobody took yet,
     * and every shop where units are still to be received for the order on the way.
     */
    private function missionTask(object $m, int $courier): array
    {
        $order = DB::table('orders')->where('id', $m->order_id)->first();
        $delivery = json_decode($order->delivery_snapshot, true);
        $address = json_decode($order->address_snapshot, true);
        $slot = $this->slot($order);
        $pickups = [];
        if ($m->custodian_id !== null && (int) $m->custodian_id !== $courier) {
            $pickups[] = [
                'place' => 'C:'.$m->custodian_id,
                'warehouse_id' => null,
                'area' => 'C:'.$m->custodian_id,
            ];
        }
        if ((int) $m->depot_packages > 0 && $m->depot_picked_at === null) {
            foreach ($this->warehouses($order->id) as $warehouse) {
                $point = $this->warehousePoint($warehouse->id);
                $pickups[] = [
                    'place' => 'W:'.$warehouse->code,
                    'warehouse_id' => $warehouse->id,
                    'area' => $point->zone_id ?? 'W:'.$warehouse->id,
                    'lat' => $point?->latitude,
                    'lng' => $point?->longitude,
                ];
            }
        }
        foreach (
            DB::table('supplier_collections')
                ->where('order_id', $order->id)
                ->where('status', 'unassigned')
                ->orderBy('number')
                ->get() as $c
        ) {
            $point = DB::table('collection_points')->where('id', $c->point_id)->first();
            $pickups[] = [
                'place' => 'P:'.$point->id,
                'warehouse_id' => null,
                'collection_id' => $c->id,
                'area' => $point->zone_id,
                'lat' => $point->latitude,
                'lng' => $point->longitude,
            ];
        }

        return [
            'id' => 'm:'.$m->id,
            'number' => $m->number,
            'held' => $pickups === [],
            'pickups' => $pickups,
            'area' => $delivery['zone_id'] ?? 'D:'.$address['district'],
            'district' => $address['district'],
            'slot' => $slot ? $slot['date'].' '.$slot['starts_at'] : null,
            'lat' => $address['latitude'] ?? null,
            'lng' => $address['longitude'] ?? null,
        ];
    }

    private function collectionTask(object $c, int $courier): array
    {
        $held = $c->custodian_id !== null && (int) $c->custodian_id === $courier;
        $pickups = [];
        if ($c->custodian_id !== null && ! $held) {
            $pickups[] = [
                'place' => 'C:'.$c->custodian_id,
                'warehouse_id' => null,
                'area' => 'C:'.$c->custodian_id,
            ];
        } elseif (! $held) {
            $point = DB::table('collection_points')->where('id', $c->point_id)->first();
            $pickups[] = [
                'place' => 'P:'.$point->id,
                'warehouse_id' => null,
                'area' => $point->zone_id,
                'lat' => $point->latitude,
                'lng' => $point->longitude,
            ];
        }
        $depot = $this->warehousePoint($c->warehouse_id);

        return [
            'id' => 'c:'.$c->id,
            'number' => $c->number,
            'held' => $held,
            'pickups' => $pickups,
            'area' => $depot->zone_id ?? 'W:'.$c->warehouse_id,
            'district' => $depot->district ?? '',
            // A depot drop has no customer slot: urgent deliveries lead.
            'slot' => null,
            'lat' => $depot?->latitude,
            'lng' => $depot?->longitude,
        ];
    }

    /**
     * After-sales trip: a return is fetched at the customer's and dropped at the depot; an exchange item is
     * taken at the depot and handed to the customer.
     */
    private function tripTask(object $t, int $courier): array
    {
        $trips = app(AfterSalesTrips::class);
        $customer = $trips->place($t, true);
        $depot = $this->warehousePoint($t->warehouse_id);
        $depotStop = [
            'place' => 'W:'.$t->warehouse_id,
            'warehouse_id' => $t->warehouse_id,
            'area' => $depot->zone_id ?? 'W:'.$t->warehouse_id,
            'lat' => $depot?->latitude,
            'lng' => $depot?->longitude,
        ];
        $customerStop = [
            'place' => 'T:'.$t->id,
            'warehouse_id' => null,
            'area' => $customer['zone_id'] ?? 'D:'.$customer['district'],
            'lat' => $customer['latitude'],
            'lng' => $customer['longitude'],
        ];
        $held = $t->custodian_id !== null && (int) $t->custodian_id === $courier;
        [$from, $to] = $t->direction === 'pickup' ? [$customerStop, $depotStop] : [$depotStop, $customerStop];

        return [
            'id' => 't:'.$t->id,
            'number' => $t->number,
            'held' => $held,
            'pickups' => $held ? [] : [$from],
            'area' => $to['area'],
            'district' => $t->direction === 'pickup' ? $depot->district ?? '' : (string) $customer['district'],
            'slot' => null,
            'lat' => $to['lat'],
            'lng' => $to['lng'],
            'warehouse_id' => $to['warehouse_id'],
        ];
    }

    private function insertStops(string $roundId, array $plan, int $from): void
    {
        foreach ($plan as $i => $stop) {
            DB::table('delivery_round_stops')->insert([
                'id' => Records::id(),
                'round_id' => $roundId,
                'mission_id' => $stop['mission_id'],
                'collection_id' => $stop['collection_id'],
                'trip_id' => $stop['trip_id'] ?? null,
                'kind' => $stop['kind'],
                'warehouse_id' => $stop['warehouse_id'],
                'sequence' => $from + $i,
                'origin' => 'suggested',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /** Warehouses the order's parcels left from, read from the preparation picks. */
    private function warehouses(string $orderId): array
    {
        $codes = [];
        foreach (DB::table('order_preparation_picks')->where('order_id', $orderId)->pluck('sources') as $sources) {
            foreach (json_decode($sources, true) as $source) {
                $codes[$source['warehouse_code']] = true;
            }
        }

        return DB::table('warehouses')->whereIn('code', array_keys($codes))->orderBy('code')->get()->all();
    }

    /** The warehouse's collection point, chosen as the delivery quote chooses it (lowest priority first). */
    private function warehousePoint(string $warehouseId): ?object
    {
        return DB::table('collection_points')
            ->where('warehouse_id', $warehouseId)
            ->where('active', true)
            ->orderBy('priority')
            ->orderBy('id')
            ->first();
    }

    /** Stops in order with their state read from the missions and collections. */
    /** Stops in order with their state read from the missions and collections. */
    private function stops(object $round): array
    {
        $rows = DB::table('delivery_round_stops')
            ->where('round_id', $round->id)
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();
        $missions = DB::table('delivery_missions')
            ->whereIn('id', $rows->pluck('mission_id')->filter())
            ->get()
            ->keyBy('id');
        $collections = DB::table('supplier_collections')
            ->whereIn('id', $rows->pluck('collection_id')->filter())
            ->get()
            ->keyBy('id');
        $trips = DB::table('after_sales_trips')
            ->whereIn('id', $rows->pluck('trip_id')->filter())
            ->get()
            ->keyBy('id');
        $courier = (int) $round->courier_id;

        return $rows
            ->map(function ($s) use ($missions, $collections, $trips, $courier) {
                $task = match (true) {
                    $s->mission_id !== null => $missions[$s->mission_id],
                    $s->trip_id !== null => $trips[$s->trip_id],
                    default => $collections[$s->collection_id],
                };
                // A mission's shop stop: the direct collection received on the way.
                $collection = $s->mission_id && $s->collection_id ? $collections[$s->collection_id] : null;
                $holds = $task->custodian_id !== null && (int) $task->custodian_id === $courier;
                $state = match (true) {
                    // Reassigned, sent back or cancelled: no longer this courier's work, whoever finishes it.
                    $task->status === 'cancelled' || (int) $task->courier_id !== $courier => 'removed',
                    $collection?->status === 'cancelled' => 'removed',
                    in_array($task->status, ['delivered', 'dropped', 'received', 'completed'], true) => 'done',
                    $collection !== null => $collection->status === 'received' ? 'done' : $this->live($task),
                    $s->mission_id !== null && $s->kind === 'pickup' && $s->warehouse_id !== null => $task->depot_picked_at !== null ? 'done' : $this->live($task),
                    in_array($s->kind, ['pickup', 'collect'], true) && $holds => 'done',
                    default => $this->live($task),
                };

                return ['id' => $s->id, 'stop' => $s, 'task' => $task, 'collection' => $collection, 'state' => $state];
            })
            ->all();
    }

    private function live(object $task): string
    {
        return in_array($task->status, ['incident', 'awaiting_approval'], true) ? 'waiting' : 'todo';
    }

    private function placeKey(array $s): string
    {
        if ($s['collection'] !== null) {
            return 'R:'.$s['collection']->point_id;
        }
        if ($s['stop']->trip_id !== null) {
            return $s['stop']->warehouse_id ? 'W:'.$s['stop']->warehouse_id : 'T:'.$s['task']->id;
        }

        return match ($s['stop']->kind) {
            'pickup' => $s['stop']->warehouse_id ? 'W:'.$s['stop']->warehouse_id : 'C:'.$s['task']->custodian_id,
            'collect' => $s['task']->custodian_id !== null && $s['task']->collected_at !== null
                ? 'C:'.$s['task']->custodian_id
                : 'P:'.$s['task']->point_id,
            'drop' => 'W:'.$s['stop']->warehouse_id,
            default => 'M:'.$s['task']->id,
        };
    }

    /**
     * Steps of the day. Consecutive pickups, collections or drops at the same place form one step; each customer
     * is one step. The courier view carries addresses and contacts only for their live tasks, never codes,
     * prices or costs.
     */
    public function view(object $round, bool $courierView): array
    {
        $steps = [];
        foreach ($this->stops($round) as $s) {
            $place = $this->placeKey($s);
            $last = array_key_last($steps);
            if (
                $last !== null &&
                $s['stop']->kind !== 'delivery' &&
                $steps[$last]['kind'] === $s['stop']->kind &&
                $steps[$last]['place_key'] === $place
            ) {
                $steps[$last]['stops'][] = $s;

                continue;
            }
            $steps[] = ['kind' => $s['stop']->kind, 'place_key' => $place, 'stops' => [$s]];
        }
        $current = null;
        $out = [];
        foreach ($steps as $i => $step) {
            $states = array_column($step['stops'], 'state');
            $state = match (true) {
                ! array_diff($states, ['done', 'removed']) => in_array('done', $states, true) ? 'done' : 'removed',
                in_array('waiting', $states, true) && ! in_array('todo', $states, true) => 'waiting',
                default => 'todo',
            };
            if ($state !== 'done' && $state !== 'removed' && $current === null) {
                $current = $i + 1;
            }
            $out[] = $this->step($i + 1, $step, $state, $round, $courierView);
        }
        foreach ($out as &$step) {
            $step['current'] = $step['step'] === $current;
        }
        unset($step);
        $courier = (int) $round->courier_id;
        $all = collect($this->stops($round));
        $mine = fn ($kind) => $all
            ->filter(
                fn ($s) => ($kind === 'mission'
                    ? $s['stop']->mission_id
                    : ($kind === 'trip'
                        ? $s['stop']->trip_id
                        : ($s['stop']->mission_id === null
                            ? $s['stop']->collection_id
                            : null))) !== null,
            )
            ->pluck('task')
            ->unique('id')
            ->filter(fn ($t) => (int) $t->courier_id === $courier && $t->status !== 'cancelled');
        $missions = $mine('mission');
        $collections = $mine('collection');
        $trips = $mine('trip');

        return [
            'id' => $round->id,
            'number' => $round->number,
            'date' => substr((string) $round->date, 0, 10),
            'status' => $round->status,
            'version' => (int) $round->version,
            'courier' => [
                'user_id' => $courier,
                ...app(Couriers::class)->publicIdentity($courier) ?? [],
            ],
            'progress' => [
                'steps_total' => count(array_filter($out, fn ($s) => $s['state'] !== 'removed')),
                'steps_done' => count(array_filter($out, fn ($s) => $s['state'] === 'done')),
                'deliveries_total' => $missions->count(),
                'deliveries_done' => $missions->where('status', 'delivered')->count(),
                'collections_total' => $collections->count(),
                'collections_done' => $collections->whereIn('status', ['dropped', 'received'])->count(),
                'trips_total' => $trips->count(),
                'trips_done' => $trips->where('status', 'completed')->count(),
                // Customer parcels and supplier goods currently carried by this courier.
                'parcels_on_board' => (int) $missions
                    ->merge($collections)
                    ->merge($trips)
                    ->filter(fn ($t) => (int) $t->custodian_id === $courier)
                    ->sum('packages'),
            ],
            'current_step' => $current,
            'next_step' => $current === null
                    ? null
                    : collect($out)->first(
                        fn ($s) => $s['step'] > $current && in_array($s['state'], ['todo', 'waiting'], true),
                    )['step'] ?? null,
            'steps' => $out,
            'cancel_reason' => $round->cancel_reason,
        ];
    }

    private function step(int $number, array $step, string $state, object $round, bool $courierView): array
    {
        $tasks = array_map(fn ($s) => $s['task'], $step['stops']);
        $first = $step['stops'][0];
        $refs = array_map(
            fn ($s) => [
                ...$s['collection'] !== null
                    ? [
                        // Shop receipt on the way: the courier acts on the mission, for this collection.
                        'type' => 'shop_receipt',
                        'mission_id' => $s['task']->id,
                        'collection_id' => $s['collection']->id,
                        'number' => $s['collection']->number,
                        'task_status' => $s['collection']->status,
                        'version' => (int) $s['collection']->version,
                        'packages' => $s['collection']->packages === null ? null : (int) $s['collection']->packages,
                    ]
                    : [
                        'type' => match (true) {
                            $s['stop']->mission_id !== null => 'mission',
                            $s['stop']->trip_id !== null => 'trip',
                            default => 'collection',
                        },
                        ...match (true) {
                            $s['stop']->mission_id !== null => ['mission_id' => $s['task']->id],
                            $s['stop']->trip_id !== null => [
                                'trip_id' => $s['task']->id,
                                'direction' => $s['task']->direction,
                            ],
                            default => ['collection_id' => $s['task']->id],
                        },
                        'number' => $s['task']->number,
                        'task_status' => $s['task']->status,
                        'version' => (int) $s['task']->version,
                        'packages' => $s['task']->packages === null ? null : (int) $s['task']->packages,
                    ],
                'state' => $s['state'],
                // What the courier does next on this task, from its real state.
                'action' => $this->action($s),
                ...$courierView ? [] : ['stop_id' => $s['id']],
            ],
            $step['stops'],
        );
        $base = ['step' => $number, 'kind' => $step['kind'], 'state' => $state, 'missions' => $refs];
        $numbers = implode(', ', array_map(fn ($t) => $t->number, $tasks));
        $live = $first['state'] !== 'removed' && $first['state'] !== 'done';
        if ($first['collection'] !== null) {
            return [...$base, ...$this->receiptStep($step['stops'], $courierView && ! $live)];
        }
        if ($first['stop']->trip_id !== null) {
            return [...$base, ...$this->tripStep($first, $tasks, $numbers, $courierView && ! $live)];
        }

        return match ($step['kind']) {
            'pickup' => [...$base, ...$this->pickupStep($first, $tasks, $numbers)],
            'collect' => [...$base, ...$this->collectStep($first, $tasks, $numbers, $courierView && ! $live)],
            'drop' => [
                ...$base,
                ...$this->dropStep($first, array_sum(array_map(fn ($t) => (int) $t->packages, $tasks)), $numbers),
            ],
            default => [...$base, ...$this->deliveryStep($first, $courierView && ! $live)],
        };
    }

    /** Receiving at a shop for the customers' orders on the way: where, what, for whom and who receives. */
    private function receiptStep(array $stops, bool $hidden): array
    {
        $collections = app(SupplierCollections::class);
        $first = $stops[0]['collection'];
        $place = $collections->point($first);
        $items = [];
        $lines = [];
        foreach ($stops as $s) {
            foreach ($collections->lines($s['collection']) as $line) {
                $items[] = $line['expected_quantity'].' × '.$line['name'];
                $lines[] = $line;
            }
        }
        $orders = DB::table('orders')
            ->whereIn('id', array_map(fn ($s) => $s['task']->order_id, $stops))
            ->orderBy('number')
            ->pluck('number')
            ->implode(', ');
        $supervisor = $first->supervisor_id
            ? DB::table('users')->where('id', $first->supervisor_id)->value('name')
            : null;
        $values = [
            'place' => $place['shop'].' — '.$place['name'],
            'address' => trim($place['address'].($place['landmark'] ? ', '.$place['landmark'] : '')),
            'items' => implode(', ', $items),
            'order' => $orders,
            'collection' => implode(', ', array_map(fn ($s) => $s['collection']->number, $stops)),
            'supervisor' => $supervisor,
        ];

        return [
            'place' => $hidden
                ? ['type' => 'shop', 'shop' => $place['shop'], 'district' => $place['district']]
                : $place,
            'items' => $hidden ? [] : $lines,
            'receiver' => $supervisor ? 'supervisor' : 'courier',
            'instruction' => __($supervisor ? 'rounds.RECEIVE_SHOP_SUPERVISED' : 'rounds.RECEIVE_SHOP', $values),
        ];
    }

    /** Return to fetch at the customer's, return to drop at the depot, exchange item to take or deliver. */
    private function tripStep(array $first, array $tasks, string $numbers, bool $hidden): array
    {
        $trips = app(AfterSalesTrips::class);
        $trip = $first['task'];
        $items = implode(
            ', ',
            array_map(function ($t) use ($trips) {
                $i = $trips->items($t);

                return $i['quantity'].' × '.$i['name'];
            }, $tasks),
        );
        $packages = array_sum(array_map(fn ($t) => (int) $t->packages, $tasks));
        $cases = DB::table('after_sales_cases')
            ->whereIn('id', array_map(fn ($t) => $t->case_id, $tasks))
            ->orderBy('number')
            ->pluck('number')
            ->implode(', ');
        if ($first['stop']->warehouse_id) {
            $place = $this->warehousePlace($first['stop']->warehouse_id);
            $key = $trip->direction === 'pickup' ? 'rounds.RETURN_DROP' : 'rounds.EXCHANGE_PICKUP';

            return [
                'place' => $place,
                'instruction' => __($key, [
                    'count' => $packages,
                    'items' => $items,
                    'place' => $place['name'],
                    'address' => $place['address'],
                    'cases' => $cases,
                    'trips' => $numbers,
                ]),
            ];
        }
        $customer = $trips->place($trip, ! $hidden);
        unset($customer['zone_id']);

        return [
            'place' => $customer,
            'items' => $hidden ? [] : array_map(fn ($t) => $trips->items($t), $tasks),
            'instruction' => __($trip->direction === 'pickup' ? 'rounds.RETURN_PICKUP' : 'rounds.EXCHANGE_DELIVER', [
                'items' => $items,
                'district' => $customer['district'] ?? '—',
                'landmark' => $hidden ? '—' : $customer['landmark'] ?? '—',
                'cases' => $cases,
                'trips' => $numbers,
            ]),
        ];
    }

    private function pickupStep(array $first, array $tasks, string $numbers): array
    {
        if ($first['stop']->warehouse_id) {
            $place = $this->warehousePlace($first['stop']->warehouse_id);

            return [
                'place' => $place,
                'instruction' => __('rounds.PICKUP_WAREHOUSE', [
                    // Only the parcels prepared at the depot; shop units join the load elsewhere.
                    'count' => array_sum(array_map(fn ($t) => (int) $t->depot_packages, $tasks)),
                    'place' => $place['name'],
                    'address' => $place['address'],
                    'missions' => $numbers,
                ]),
            ];
        }

        return $this->fromCourier(
            (int) $first['task']->custodian_id,
            array_sum(array_map(fn ($t) => (int) $t->packages, $tasks)),
            $numbers,
        );
    }

    private function collectStep(array $first, array $tasks, string $numbers, bool $hidden): array
    {
        $c = $first['task'];
        if ($c->collected_at !== null && $c->custodian_id !== null && $first['state'] !== 'done') {
            return $this->fromCourier(
                (int) $c->custodian_id,
                array_sum(array_map(fn ($t) => (int) $t->packages, $tasks)),
                $numbers,
            );
        }
        $collections = app(SupplierCollections::class);
        $place = $collections->point($c);
        $items = [];
        foreach ($tasks as $task) {
            foreach ($collections->lines($task) as $line) {
                $items[] = $line['expected_quantity'].' × '.$line['name'];
            }
        }

        return [
            'place' => $hidden
                ? ['type' => 'shop', 'shop' => $place['shop'], 'district' => $place['district']]
                : $place,
            'items' => $hidden ? [] : array_merge(...array_map(fn ($t) => $collections->lines($t), $tasks)),
            'instruction' => __('rounds.COLLECT', [
                'place' => $place['shop'].' — '.$place['name'],
                'address' => trim($place['address'].($place['landmark'] ? ', '.$place['landmark'] : '')),
                'items' => implode(', ', $items),
                'collection' => $numbers,
            ]),
        ];
    }

    private function dropStep(array $first, int $packages, string $numbers): array
    {
        $place = $this->warehousePlace($first['stop']->warehouse_id);

        return [
            'place' => $place,
            'instruction' => __('rounds.DROP', [
                'count' => $packages,
                'collections' => $numbers,
                'place' => $place['name'],
                'address' => $place['address'],
            ]),
        ];
    }

    private function deliveryStep(array $first, bool $hidden): array
    {
        $mission = $first['task'];
        $order = DB::table('orders')->where('id', $mission->order_id)->first();
        $address = json_decode($order->address_snapshot, true);
        $slot = $this->slot($order);
        $delivery = json_decode($order->delivery_snapshot, true);

        return [
            'order_number' => $order->number,
            'slot' => $slot,
            'place' => [
                'type' => 'customer',
                'district' => $address['district'],
                'zone' => $this->zoneName($delivery['zone_id'] ?? null),
                ...$hidden
                    ? []
                    : [
                        'landmark' => $address['landmark'],
                        'instructions' => $address['instructions'] ?? null,
                        'latitude' => $address['latitude'] ?? null,
                        'longitude' => $address['longitude'] ?? null,
                        'contact_name' => $address['contact_name'] ?? null,
                        'contact_phone' => $address['contact_phone'] ?? null,
                        'alternate_name' => $address['alternate_name'] ?? null,
                        'alternate_phone' => $address['alternate_phone'] ?? null,
                    ],
            ],
            'instruction' => __('rounds.DELIVER', [
                'count' => (int) $mission->packages,
                'district' => $address['district'],
                'landmark' => $hidden ? '—' : $address['landmark'],
                'slot' => $slot ? $slot['starts_at'].'–'.$slot['ends_at'] : __('rounds.NO_SLOT'),
                'mission' => $mission->number,
            ]),
        ];
    }

    private function fromCourier(int $holderId, int $packages, string $numbers): array
    {
        $holder = app(Couriers::class)->publicIdentity($holderId);

        return [
            'place' => [
                'type' => 'courier',
                'name' => $holder['public_name'] ?? null,
                'badge' => $holder['badge'] ?? null,
            ],
            'instruction' => __('rounds.PICKUP_COURIER', [
                'count' => $packages,
                'name' => $holder['public_name'] ?? '?',
                'missions' => $numbers,
            ]),
        ];
    }

    private function warehousePlace(string $warehouseId): array
    {
        $warehouse = DB::table('warehouses')->where('id', $warehouseId)->first();
        $point = $this->warehousePoint($warehouseId);

        return [
            'type' => 'warehouse',
            'code' => $warehouse->code,
            'name' => $warehouse->name,
            'address' => $warehouse->address,
            'zone' => $this->zoneName($point?->zone_id),
            'district' => $point?->district,
            'landmark' => $point?->landmark,
            'latitude' => $point?->latitude === null ? null : (float) $point->latitude,
            'longitude' => $point?->longitude === null ? null : (float) $point->longitude,
        ];
    }

    private function action(array $s): ?string
    {
        if (in_array($s['state'], ['done', 'removed'], true)) {
            return null;
        }
        $task = $s['task'];
        $kind = $s['stop']->kind;
        $otherHolds = $task->custodian_id !== null && (int) $task->custodian_id !== (int) $task->courier_id;
        if ($s['collection'] !== null) {
            return match (true) {
                $task->status === 'assigned' => 'accept',
                in_array($task->status, ['incident', 'awaiting_approval'], true) => 'wait_dispatch',
                $s['collection']->supervisor_id !== null => 'wait_supervisor',
                $otherHolds => 'take_over_first',
                default => 'shop_receipt',
            };
        }
        if ($s['stop']->trip_id !== null) {
            return match (true) {
                $task->status === 'accepted' => $kind === 'pickup' ? 'take' : 'take_first',
                $task->status === 'in_transit' && $kind === 'delivery' => $task->direction === 'pickup'
                    ? 'drop'
                    : 'handover',
                default => null,
            };
        }
        if ($s['stop']->mission_id !== null) {
            $pending =
                ((int) $task->depot_packages > 0 && $task->depot_picked_at === null) ||
                DB::table('supplier_collections')
                    ->where('order_id', $task->order_id)
                    ->where('status', 'unassigned')
                    ->exists();

            return match (true) {
                $task->status === 'assigned' => 'accept',
                $task->status === 'incident' => 'wait_dispatch',
                $task->status === 'awaiting_approval' => 'wait_approval',
                $kind === 'pickup' && $s['stop']->warehouse_id !== null && $otherHolds => 'take_over_first',
                $kind === 'pickup' => 'pickup',
                $task->status === 'in_transit' && ! $pending => 'handover',
                default => 'pickup_first',
            };
        }

        return match ($task->status) {
            'assigned' => 'accept',
            'accepted' => $kind === 'collect' ? 'collect' : 'collect_first',
            'collected' => $kind === 'drop' ? 'drop' : null,
            'incident' => 'wait_dispatch',
            default => null,
        };
    }

    private function zoneName(?string $zoneId): ?string
    {
        if (! $zoneId || str_contains($zoneId, ':')) {
            return null;
        }
        $name = json_decode((string) DB::table('delivery_zones')->where('id', $zoneId)->value('name'), true);

        return $name[app()->getLocale()] ?? ($name['fr'] ?? null);
    }

    private function slot(object $order): ?array
    {
        $delivery = json_decode($order->delivery_snapshot, true);
        $rule =
            $delivery['slot']['rule_id'] ?? null
                ? DB::table('delivery_slot_rules')->where('id', $delivery['slot']['rule_id'])->first()
                : null;

        return $rule
            ? [
                'date' => $delivery['slot']['date'],
                'starts_at' => substr($rule->starts_at, 0, 5),
                'ends_at' => substr($rule->ends_at, 0, 5),
            ]
            : null;
    }

    public function courierDay(User $courier, string $date): ?array
    {
        $round = DB::table('delivery_rounds')
            ->where('courier_id', $courier->id)
            ->where('date', $date)
            ->where('status', 'open')
            ->first();

        return $round ? $this->view($round, true) : null;
    }
}
