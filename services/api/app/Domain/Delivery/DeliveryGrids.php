<?php

namespace App\Domain\Delivery;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * A tariff grid is drafted, then published once and never edited again: every quote keeps the grid it used.
 * Publishing a new grid retires the previous one. No amount is assumed when no grid is published.
 */
final class DeliveryGrids
{
    public function current(): ?object
    {
        return DB::table('delivery_grids')->where('status', 'published')->orderByDesc('published_at')->first();
    }

    public function createDraft(array $rules, string $notes, User $actor): array
    {
        $this->check($rules);
        $id = Records::id();
        try {
            DB::table('delivery_grids')->insert([
                'id' => $id,
                'number' => (int) DB::table('delivery_grids')->max('number') + 1,
                'status' => 'draft',
                'rules' => Records::json($rules),
                'notes' => $notes,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            // Two drafts created at the same instant: the unique number keeps the sequence; retry explicitly.
            abort(409);
        }
        Records::audit($actor, 'delivery.grid_drafted', $id);

        return $this->dto(Records::row('delivery_grids', $id, false));
    }

    public function updateDraft(object $grid, array $rules, string $notes, User $actor): array
    {
        if ($grid->status !== 'draft') {
            DeliveryZones::fail('grid', 'GRID_NOT_DRAFT');
        }
        $this->check($rules);
        DB::table('delivery_grids')
            ->where('id', $grid->id)
            ->update([
                'rules' => Records::json($rules),
                'notes' => $notes,
                'version' => $grid->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'delivery.grid_draft_updated', $grid->id);

        return $this->dto(Records::row('delivery_grids', $grid->id, false));
    }

    public function publish(object $grid, User $actor): array
    {
        if ($grid->status !== 'draft') {
            DeliveryZones::fail('grid', 'GRID_NOT_DRAFT');
        }
        // Zones may have been deactivated since the draft was written.
        $this->check(json_decode($grid->rules, true));
        $previous = DB::table('delivery_grids')->where('status', 'published')->lockForUpdate()->get();
        foreach ($previous as $old) {
            DB::table('delivery_grids')
                ->where('id', $old->id)
                ->update(['status' => 'retired', 'retired_at' => now(), 'updated_at' => now()]);
        }
        DB::table('delivery_grids')
            ->where('id', $grid->id)
            ->update([
                'status' => 'published',
                'published_by' => $actor->id,
                'published_at' => now(),
                'version' => $grid->version + 1,
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'delivery.grid_published', $grid->id, [
            'retired' => $previous->pluck('id')->all(),
        ]);

        return $this->dto(Records::row('delivery_grids', $grid->id, false));
    }

    /** Structural checks that the request validator cannot express. */
    private function check(array $rules): void
    {
        $zoneIds = array_column($rules['zones'], 'zone_id');
        $active = DB::table('delivery_zones')->whereIn('id', $zoneIds)->where('active', true)->count();
        if ($active !== count($zoneIds)) {
            DeliveryZones::fail('rules.zones', 'GRID_ZONE_INVALID');
        }
        $pairs = [];
        foreach ($rules['route_supplements'] as $route) {
            $pair = $route['from_zone_id'].'>'.$route['to_zone_id'];
            if (isset($pairs[$pair]) || $route['from_zone_id'] === $route['to_zone_id']) {
                DeliveryZones::fail('rules.route_supplements', 'GRID_ROUTE_INVALID');
            }
            $pairs[$pair] = true;
        }
        $routeZones = array_unique([
            ...array_column($rules['route_supplements'], 'from_zone_id'),
            ...array_column($rules['route_supplements'], 'to_zone_id'),
        ]);
        if (DB::table('delivery_zones')->whereIn('id', $routeZones)->count() !== count($routeZones)) {
            DeliveryZones::fail('rules.route_supplements', 'GRID_ROUTE_INVALID');
        }
        $previous = 0;
        foreach ($rules['mass_brackets'] as $bracket) {
            if ($bracket['max_grams'] <= $previous) {
                DeliveryZones::fail('rules.mass_brackets', 'GRID_BRACKETS_UNORDERED');
            }
            $previous = $bracket['max_grams'];
        }
    }

    /**
     * Deterministic fee for one quote. Each constraint is charged once: a single base fee for the destination
     * zone, one supplement per collection point beyond the first, one route supplement per point located in
     * another zone, one mass bracket for the whole parcel, and at most one bulky and one fragile fee.
     *
     * @param  array<int, string>  $pointZoneIds  zone of each distinct collection point
     * @return array{breakdown: array<int, array<string, mixed>>, total: int}|array{reason: string}
     */
    public function price(
        object $grid,
        string $zoneId,
        array $pointZoneIds,
        int $grams,
        bool $bulky,
        bool $fragile,
    ): array {
        $rules = json_decode($grid->rules, true);
        $zone = collect($rules['zones'])->firstWhere('zone_id', $zoneId);
        if (! $zone) {
            return ['reason' => 'zone_not_in_grid'];
        }
        $bracket = collect($rules['mass_brackets'])->first(fn ($b) => $grams <= $b['max_grams']);
        if (! $bracket) {
            return ['reason' => 'mass_above_grid'];
        }
        $routes = collect($rules['route_supplements'])->keyBy(fn ($r) => $r['from_zone_id'].'>'.$r['to_zone_id']);
        $routeCount = 0;
        $routeAmount = 0;
        foreach ($pointZoneIds as $pointZone) {
            $route = $routes->get($pointZone.'>'.$zoneId);
            if ($route) {
                $routeCount++;
                $routeAmount = Money::sum([$routeAmount, $route['amount']]);
            }
        }
        $extra = max(0, count($pointZoneIds) - 1);
        $breakdown = [
            ['code' => 'base', 'quantity' => 1, 'amount' => $zone['base_fee']],
            [
                'code' => 'extra_collections',
                'quantity' => $extra,
                'amount' => Money::multiply($rules['extra_collection_fee'], $extra),
            ],
            ['code' => 'route', 'quantity' => $routeCount, 'amount' => $routeAmount],
            ['code' => 'mass', 'quantity' => $grams, 'amount' => $bracket['amount']],
            ['code' => 'bulky', 'quantity' => $bulky ? 1 : 0, 'amount' => $bulky ? $rules['bulky_fee'] : 0],
            ['code' => 'fragile', 'quantity' => $fragile ? 1 : 0, 'amount' => $fragile ? $rules['fragile_fee'] : 0],
        ];

        return ['breakdown' => $breakdown, 'total' => Money::sum(array_column($breakdown, 'amount'))];
    }

    public function dto(object $grid): array
    {
        return [
            'id' => $grid->id,
            'number' => (int) $grid->number,
            'status' => $grid->status,
            'rules' => json_decode($grid->rules, true),
            'notes' => $grid->notes,
            'version' => (int) $grid->version,
            'published_at' => Records::iso($grid->published_at),
            'retired_at' => Records::iso($grid->retired_at),
            'created_at' => Records::iso($grid->created_at),
        ];
    }
}
