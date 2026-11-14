<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\DeliveryRounds;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DeliveryRoundsController extends OperationalController
{
    public function index(Request $http, DeliveryRounds $rounds)
    {
        $d = $http->validate([
            'date' => ['nullable', 'date_format:Y-m-d'],
            'courier_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:open,cancelled'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $rounds) {
            $q = DB::table('delivery_rounds')->orderByDesc('date')->orderBy('number');
            foreach (['date', 'courier_id', 'status'] as $field) {
                if (isset($d[$field])) {
                    $q->where($field, $d[$field]);
                }
            }

            return $this->page($q, $http, fn ($r) => $rounds->view($r, false));
        });
    }

    public function show(string $id, DeliveryRounds $rounds)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $rounds->view(Records::row('delivery_rounds', $id, false), false),
            ),
        ];
    }

    public function create(Request $http, DeliveryRounds $rounds)
    {
        $data = $http->validate([
            'courier_id' => ['required', 'integer', 'min:1'],
            'date' => ['required', 'date_format:Y-m-d'],
            'mission_ids' => ['required_without_all:collection_ids,trip_ids', 'array', 'max:60'],
            'mission_ids.*' => ['uuid', 'distinct'],
            'collection_ids' => ['required_without_all:mission_ids,trip_ids', 'array', 'max:60'],
            'collection_ids.*' => ['uuid', 'distinct'],
            'trip_ids' => ['required_without_all:mission_ids,collection_ids', 'array', 'max:60'],
            'trip_ids.*' => ['uuid', 'distinct'],
        ]);

        return $this->command($http, 'delivery.round_create', $data, fn ($d) => $rounds->create($d, $http->user()), 201);
    }

    public function replan(Request $http, string $id, DeliveryRounds $rounds)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'mission_ids' => ['required_without_all:collection_ids,trip_ids', 'array', 'max:60'],
            'mission_ids.*' => ['uuid', 'distinct'],
            'collection_ids' => ['required_without_all:mission_ids,trip_ids', 'array', 'max:60'],
            'collection_ids.*' => ['uuid', 'distinct'],
            'trip_ids' => ['required_without_all:mission_ids,collection_ids', 'array', 'max:60'],
            'trip_ids.*' => ['uuid', 'distinct'],
        ]);

        return $this->command(
            $http,
            'delivery.round_replan:'.$id,
            $data,
            fn ($d) => $rounds->replan($id, $d, $http->user()),
        );
    }

    public function reorder(Request $http, string $id, DeliveryRounds $rounds)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'stop_ids' => ['required', 'array', 'min:1', 'max:240'],
            'stop_ids.*' => ['uuid', 'distinct'],
        ]);

        return $this->command(
            $http,
            'delivery.round_reorder:'.$id,
            $data,
            fn ($d) => $rounds->reorder($id, $d, $http->user()),
        );
    }

    public function cancel(Request $http, string $id, DeliveryRounds $rounds)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'delivery.round_cancel:'.$id,
            $data,
            fn ($d) => $rounds->cancel($id, $d, $http->user()),
        );
    }

    /** The signed-in courier's day, step by step; today in Douala by default. */
    public function day(Request $http, DeliveryRounds $rounds)
    {
        $d = $http->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $d['date'] ?? now(config('kora.business_timezone'))->toDateString();

        return ['data' => app(ReadSnapshot::class)->run(fn () => $rounds->courierDay($http->user(), $date))];
    }
}
