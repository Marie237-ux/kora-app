<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Shipping between cities (team settings): flat fee and delay per origin and destination city ("*" = any other
 * city). A checkout quote uses the exact route first, then the "*" one; without an active route the fee is shown
 * "to be confirmed" and nothing can be paid. Every change is versioned and audited.
 */
final class ShippingRoutesController extends OperationalController
{
    public function index(Request $http)
    {
        $d = $http->validate(['origin_city' => ['nullable', 'string', 'max:80']]);
        $q = DB::table('shipping_routes')
            ->when($d['origin_city'] ?? null, fn ($q, $city) => $q->where('origin_city', $city))
            ->orderBy('origin_city')
            ->orderByRaw("CASE WHEN destination_city = '*' THEN 1 ELSE 0 END")
            ->orderBy('destination_city')
            ->orderBy('id');

        return $this->page($q, $http, fn ($r) => $this->dto($r));
    }

    /** Create, or update with the version read (optimistic lock). */
    public function save(Request $http, ?string $id = null)
    {
        $d = $http->validate([
            ...$id ? ['version' => ['required', 'integer', 'min:1']] : [],
            'origin_city' => ['required', 'string', 'max:80'],
            'destination_city' => ['required', 'string', 'max:80', 'different:origin_city'],
            'fee' => ['required', 'integer', 'min:0', 'max:10000000'],
            'days_min' => ['required', 'integer', 'min:0', 'max:60'],
            'days_max' => ['required', 'integer', 'min:0', 'max:60', 'gte:days_min'],
            'to_door' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ]);
        $d['origin_city'] = trim($d['origin_city']);
        $d['destination_city'] = trim($d['destination_city']);
        abort_if(
            DB::table('shipping_routes')
                ->where('origin_city', $d['origin_city'])
                ->where('destination_city', $d['destination_city'])
                ->when($id, fn ($q) => $q->where('id', '!=', $id))
                ->exists(),
            409,
        );

        return $this->command($http, 'shipping.route:'.($id ?? 'new'), $d, function ($data) use ($http, $id) {
            $values = [
                'origin_city' => $data['origin_city'],
                'destination_city' => $data['destination_city'],
                'fee' => $data['fee'],
                'days_min' => $data['days_min'],
                'days_max' => $data['days_max'],
                'to_door' => $data['to_door'],
                'active' => $data['active'],
                'updated_at' => now(),
            ];
            if ($id) {
                $route = DB::table('shipping_routes')->where('id', $id)->lockForUpdate()->first();
                abort_unless($route, 404);
                abort_unless((int) $route->version === $data['version'], 409);
                DB::table('shipping_routes')->where('id', $id)->update([...$values, 'version' => $route->version + 1]);
            } else {
                $id = Records::id();
                DB::table('shipping_routes')->insert([...$values, 'id' => $id, 'version' => 1, 'created_at' => now()]);
            }
            Records::audit($http->user(), 'delivery.shipping_route_saved', $id, [
                'origin_city' => $data['origin_city'],
                'destination_city' => $data['destination_city'],
                'fee' => $data['fee'],
                'active' => $data['active'],
            ]);

            return $this->dto(DB::table('shipping_routes')->where('id', $id)->first());
        }, $http->isMethod('post') ? 201 : 200);
    }

    private function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'origin_city' => $r->origin_city,
            'destination_city' => $r->destination_city,
            'fee' => (int) $r->fee,
            'days_min' => (int) $r->days_min,
            'days_max' => (int) $r->days_max,
            'to_door' => (bool) $r->to_door,
            'active' => (bool) $r->active,
            'version' => (int) $r->version,
            'updated_at' => Records::iso($r->updated_at),
        ];
    }
}
