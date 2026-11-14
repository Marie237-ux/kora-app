<?php

namespace App\Http\Controllers;

use App\Domain\AfterSales\AfterSalesTrips;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AfterSalesTripsController extends OperationalController
{
    public function index(Request $http, AfterSalesTrips $trips)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:unassigned,accepted,in_transit,completed,cancelled'],
            'courier_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $trips) {
            $q = DB::table('after_sales_trips')->orderBy('created_at')->orderBy('id');
            foreach (['status', 'courier_id'] as $field) {
                if (isset($d[$field])) {
                    $q->where($field, $d[$field]);
                }
            }

            return $this->page($q, $http, fn ($t) => $trips->dispatchView($t));
        });
    }

    public function pickup(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate(['warehouse_id' => ['required', 'uuid']]);

        return $this->command(
            $http,
            'after_sales.pickup_trip:'.$id,
            $data,
            fn ($d) => $trips->createPickup($id, $d, $http->user()),
            201,
        );
    }

    public function exchange(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'warehouse_id' => ['required', 'uuid'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->command(
            $http,
            'after_sales.exchange_trip:'.$id,
            $data,
            fn ($d) => $trips->createDelivery($id, $d, $http->user()),
            201,
        );
    }

    public function assign(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'courier_id' => ['required', 'integer', 'min:1'],
        ]);

        return $this->command(
            $http,
            'after_sales.trip_assign:'.$id,
            $data,
            fn ($d) => $trips->assign($id, $d, $http->user()),
        );
    }

    public function cancel(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'after_sales.trip_cancel:'.$id,
            $data,
            fn ($d) => $trips->cancel($id, $d, $http->user()),
        );
    }

    public function courierIndex(Request $http, AfterSalesTrips $trips)
    {
        $courier = $http->user();

        return app(ReadSnapshot::class)->run(
            fn () => $this->page(
                DB::table('after_sales_trips')
                    ->where('courier_id', $courier->id)
                    ->whereIn('status', ['accepted', 'in_transit'])
                    ->orderBy('created_at')
                    ->orderBy('id'),
                $http,
                fn ($t) => $trips->courierView($t, $courier),
            ),
        );
    }

    public function courierShow(Request $http, string $id, AfterSalesTrips $trips)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $trips->courierView(Records::row('after_sales_trips', $id, false), $http->user()),
            ),
        ];
    }

    public function take(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'identifiers' => ['nullable', 'array', 'max:1000'],
            'identifiers.*' => ['string', 'max:100', 'distinct'],
        ]);

        return $this->command($http, 'courier.trip_take:'.$id, $data, fn ($d) => $trips->take($id, $d, $http->user()));
    }

    public function drop(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->command($http, 'courier.trip_drop:'.$id, $data, fn ($d) => $trips->drop($id, $d, $http->user()));
    }

    public function handover(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);
        $response = $this->command(
            $http,
            'courier.trip_handover:'.$id,
            $data,
            fn ($d) => $trips->handover($id, $d, $http->user()),
        );
        $result = $response->getData(true)['data'];
        if (! isset($result['rejected'])) {
            return $response;
        }

        // The failed attempt is committed and counted; the response still says the handover did not happen.
        return response()->json(
            [
                'error' => [
                    'code' => 'VALIDATION_FAILED',
                    'message' => __('api.VALIDATION_FAILED'),
                    'fields' => ['code' => [__('missions.'.$result['rejected'])]],
                    'request_id' => $http->attributes->get('request_id'),
                ],
                'data' => ['attempts_left' => $result['attempts_left'], 'trip' => $result['trip']],
            ],
            422,
        );
    }
}
