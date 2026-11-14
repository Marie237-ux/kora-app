<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\DeliveryGrids;
use App\Domain\Delivery\DeliverySlots;
use App\Domain\Delivery\DeliveryZones;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ManageDeliveryController extends OperationalController
{
    private const AMOUNT = ['required', 'integer', 'min:0', 'max:100000000'];

    public function zones(Request $http, DeliveryZones $zones)
    {
        return $this->page(DB::table('delivery_zones')->orderBy('code'), $http, fn ($zone) => $zones->dto($zone));
    }

    public function saveZone(Request $http, DeliveryZones $zones, ?string $id = null)
    {
        $d = $http->validate([
            ...$id ? ['version' => ['required', 'integer', 'min:1']] : [],
            'code' => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/'],
            'name' => ['required', 'array:fr,en'],
            'name.fr' => ['required', 'string', 'max:150'],
            'name.en' => ['required', 'string', 'max:150'],
            'districts' => ['required', 'array', 'min:1', 'max:200'],
            'districts.*' => ['required', 'string', 'max:120'],
            'active' => ['required', 'boolean'],
        ]);
        abort_if(
            DB::table('delivery_zones')
                ->where('code', $d['code'])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->exists(),
            409,
        );

        return $this->command(
            $http,
            'delivery.zone:'.($id ?? 'new'),
            $d,
            function ($data) use ($http, $zones, $id) {
                $zone = $id ? Records::row('delivery_zones', $id) : null;
                abort_if($zone && (int) $zone->version !== $data['version'], 409);

                return $zones->save($zone, $data, $http->user());
            },
            $id ? 200 : 201,
        );
    }

    public function points(Request $http)
    {
        return $this->page(
            DB::table('collection_points')->orderBy('name')->orderBy('id'),
            $http,
            fn ($p) => $this->pointDto($p),
        );
    }

    public function savePoint(Request $http, ?string $id = null)
    {
        $d = $http->validate([
            ...$id ? ['version' => ['required', 'integer', 'min:1']] : [],
            'zone_id' => ['required', 'uuid', 'exists:delivery_zones,id'],
            'shop_id' => [
                'nullable',
                'required_without:warehouse_id',
                'prohibits:warehouse_id',
                'uuid',
                'exists:shops,id',
            ],
            'warehouse_id' => ['nullable', 'required_without:shop_id', 'uuid', 'exists:warehouses,id'],
            'name' => ['required', 'string', 'max:150'],
            'address' => ['required', 'string', 'max:500'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'active' => ['required', 'boolean'],
            // Optional location: omitted fields keep their current value; coordinates go together.
            'district' => ['sometimes', 'nullable', 'string', 'max:120'],
            'landmark' => ['sometimes', 'nullable', 'string', 'max:300'],
            'latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90', 'required_with:longitude'],
            'longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180', 'required_with:latitude'],
        ]);
        if (
            array_key_exists('latitude', $d) !== array_key_exists('longitude', $d) ||
            (($d['latitude'] ?? null) === null) !== (($d['longitude'] ?? null) === null)
        ) {
            throw ValidationException::withMessages([
                'latitude' => __('validation.required_with', ['attribute' => 'latitude', 'values' => 'longitude']),
            ]);
        }

        return $this->command(
            $http,
            'delivery.point:'.($id ?? 'new'),
            $d,
            function ($data) use ($http, $id) {
                $values = [
                    'zone_id' => $data['zone_id'],
                    'shop_id' => $data['shop_id'] ?? null,
                    'warehouse_id' => $data['warehouse_id'] ?? null,
                    'name' => $data['name'],
                    'address' => $data['address'],
                    'priority' => $data['priority'],
                    'active' => $data['active'],
                    ...array_intersect_key($data, array_flip(['district', 'landmark', 'latitude', 'longitude'])),
                    'updated_at' => now(),
                ];
                if ($id) {
                    $point = Records::row('collection_points', $id);
                    abort_if((int) $point->version !== $data['version'], 409);
                    DB::table('collection_points')
                        ->where('id', $id)
                        ->update([...$values, 'version' => $point->version + 1]);
                } else {
                    $id = Records::id();
                    DB::table('collection_points')->insert([...$values, 'id' => $id, 'created_at' => now()]);
                }
                Records::audit($http->user(), 'delivery.point_saved', $id);

                return $this->pointDto(Records::row('collection_points', $id, false));
            },
            $id ? 200 : 201,
        );
    }

    private function pointDto(object $p): array
    {
        return [
            'id' => $p->id,
            'zone_id' => $p->zone_id,
            'shop_id' => $p->shop_id,
            'warehouse_id' => $p->warehouse_id,
            'name' => $p->name,
            'address' => $p->address,
            'district' => $p->district,
            'landmark' => $p->landmark,
            'latitude' => $p->latitude === null ? null : (float) $p->latitude,
            'longitude' => $p->longitude === null ? null : (float) $p->longitude,
            'priority' => (int) $p->priority,
            'active' => (bool) $p->active,
            'version' => (int) $p->version,
        ];
    }

    public function grids(Request $http, DeliveryGrids $grids)
    {
        return $this->page(DB::table('delivery_grids')->orderByDesc('number'), $http, fn ($g) => $grids->dto($g));
    }

    private function gridData(Request $http, bool $update): array
    {
        return $http->validate([
            ...$update ? ['version' => ['required', 'integer', 'min:1']] : [],
            'notes' => ['nullable', 'string', 'max:2000'],
            'rules' => [
                'required',
                'array:demo,zones,extra_collection_fee,route_supplements,mass_brackets,bulky_fee,fragile_fee,validity_minutes,warehouse_preparation_minutes',
            ],
            'rules.demo' => ['required', 'boolean'],
            'rules.zones' => ['required', 'array', 'min:1', 'max:200'],
            'rules.zones.*' => ['array:zone_id,base_fee'],
            'rules.zones.*.zone_id' => ['required', 'uuid', 'distinct'],
            'rules.zones.*.base_fee' => self::AMOUNT,
            'rules.extra_collection_fee' => self::AMOUNT,
            'rules.route_supplements' => ['present', 'array', 'max:2000'],
            'rules.route_supplements.*' => ['array:from_zone_id,to_zone_id,amount'],
            'rules.route_supplements.*.from_zone_id' => ['required', 'uuid'],
            'rules.route_supplements.*.to_zone_id' => ['required', 'uuid'],
            'rules.route_supplements.*.amount' => self::AMOUNT,
            'rules.mass_brackets' => ['required', 'array', 'min:1', 'max:50'],
            'rules.mass_brackets.*' => ['array:max_grams,amount'],
            'rules.mass_brackets.*.max_grams' => ['required', 'integer', 'min:1', 'max:100000000'],
            'rules.mass_brackets.*.amount' => self::AMOUNT,
            'rules.bulky_fee' => self::AMOUNT,
            'rules.fragile_fee' => self::AMOUNT,
            'rules.validity_minutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'rules.warehouse_preparation_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
        ]);
    }

    public function createGrid(Request $http, DeliveryGrids $grids)
    {
        $d = $this->gridData($http, false);

        return $this->command(
            $http,
            'delivery.grid_create',
            $d,
            fn ($data) => $grids->createDraft($data['rules'], $data['notes'] ?? '', $http->user()),
            201,
        );
    }

    public function updateGrid(Request $http, string $id, DeliveryGrids $grids)
    {
        $d = $this->gridData($http, true);

        return $this->command($http, 'delivery.grid_update:'.$id, $d, function ($data) use ($http, $id, $grids) {
            $grid = Records::row('delivery_grids', $id);
            abort_if((int) $grid->version !== $data['version'], 409);

            return $grids->updateDraft($grid, $data['rules'], $data['notes'] ?? '', $http->user());
        });
    }

    public function publishGrid(Request $http, string $id, DeliveryGrids $grids)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1'], 'reviewed' => ['required', 'accepted']]);

        return $this->command($http, 'delivery.grid_publish:'.$id, $d, function ($data) use ($http, $id, $grids) {
            $grid = Records::row('delivery_grids', $id);
            abort_if((int) $grid->version !== $data['version'], 409);

            return $grids->publish($grid, $http->user());
        });
    }

    public function rules(Request $http, DeliverySlots $slots)
    {
        return $this->page(
            DB::table('delivery_slot_rules')->orderBy('weekday')->orderBy('starts_at')->orderBy('id'),
            $http,
            fn ($r) => $slots->ruleDto($r),
        );
    }

    public function saveRule(Request $http, DeliverySlots $slots, ?string $id = null)
    {
        $d = $http->validate([
            ...$id ? ['version' => ['required', 'integer', 'min:1']] : [],
            'zone_id' => ['nullable', 'uuid', 'exists:delivery_zones,id'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
            'capacity' => ['required', 'integer', 'min:0', 'max:10000'],
            'cutoff_minutes' => ['required', 'integer', 'min:0', 'max:10080'],
            'active' => ['required', 'boolean'],
        ]);

        return $this->command(
            $http,
            'delivery.rule:'.($id ?? 'new'),
            $d,
            function ($data) use ($http, $slots, $id) {
                $values = [
                    ...array_intersect_key(
                        $data,
                        array_flip(['weekday', 'starts_at', 'ends_at', 'capacity', 'cutoff_minutes', 'active']),
                    ),
                    'zone_id' => $data['zone_id'] ?? null,
                    'updated_at' => now(),
                ];
                if ($id) {
                    $rule = Records::row('delivery_slot_rules', $id);
                    abort_if((int) $rule->version !== $data['version'], 409);
                    // Places already held stay valid; a lower capacity only limits new holds.
                    DB::table('delivery_slot_rules')
                        ->where('id', $id)
                        ->update([...$values, 'version' => $rule->version + 1]);
                } else {
                    $id = Records::id();
                    DB::table('delivery_slot_rules')->insert([...$values, 'id' => $id, 'created_at' => now()]);
                }
                Records::audit($http->user(), 'delivery.slot_rule_saved', $id);

                return $slots->ruleDto(Records::row('delivery_slot_rules', $id, false));
            },
            $id ? 200 : 201,
        );
    }

    public function closures(Request $http, DeliverySlots $slots)
    {
        $http->validate(['from' => ['nullable', 'date_format:Y-m-d']]);

        return $this->page(
            DB::table('delivery_slot_closures')
                ->where('date', '>=', $http->query('from', now(config('kora.business_timezone'))->toDateString()))
                ->orderBy('date')
                ->orderBy('id'),
            $http,
            fn ($c) => $slots->closureDto($c),
        );
    }

    public function createClosure(Request $http, DeliverySlots $slots)
    {
        $d = $http->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'rule_id' => ['nullable', 'uuid', 'exists:delivery_slot_rules,id'],
            'capacity' => ['required', 'integer', 'min:0', 'max:10000'],
            'reason' => ['required', 'string', 'max:300'],
        ]);

        return $this->command(
            $http,
            'delivery.closure',
            $d,
            function ($data) use ($http, $slots) {
                $id = Records::id();
                DB::table('delivery_slot_closures')->insert([
                    'id' => $id,
                    'date' => $data['date'],
                    'rule_id' => $data['rule_id'] ?? null,
                    'capacity' => $data['capacity'],
                    'reason' => $data['reason'],
                    'created_by' => $http->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Records::audit($http->user(), 'delivery.slot_closure_created', $id, ['date' => $data['date']]);

                return $slots->closureDto(Records::row('delivery_slot_closures', $id, false));
            },
            201,
        );
    }

    /** Occupancy of the coming slots for planning: capacity, held and confirmed places per occurrence. */
    public function occupancy(Request $http, DeliverySlots $slots)
    {
        $d = $http->validate(['zone_id' => ['required', 'uuid', 'exists:delivery_zones,id']]);

        return ['data' => $slots->available($d['zone_id'], 0, null, true)];
    }
}
