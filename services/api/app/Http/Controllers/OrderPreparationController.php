<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderPreparation;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrderPreparationController extends OperationalController
{
    public function index(Request $http, OrderPreparation $preparation)
    {
        $d = $http->validate(['status' => ['nullable', 'in:not_started,partial,anomaly,ready']]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $preparation) {
            $q = DB::table('orders')
                ->where('status', 'paid')
                ->whereNull('closed_at')
                ->orderBy('paid_at')
                ->orderBy('id');
            if (isset($d['status'])) {
                $q->where('preparation_status', $d['status']);
            }

            return $this->page($q, $http, fn ($o) => $preparation->queueDto($o));
        });
    }

    public function show(string $id, OrderPreparation $preparation)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(fn () => $preparation->view(Records::row('orders', $id, false))),
        ];
    }

    public function pick(Request $http, string $id, OrderPreparation $preparation)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reservation_id' => ['required', 'uuid'],
            'identifiers' => ['present', 'array', 'max:1000'],
            'identifiers.*' => ['string', 'max:100', 'distinct'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'order.preparation_pick:'.$id,
            $data,
            fn ($d) => $preparation->pick($id, $d, $http->user()),
            201,
        );
    }

    public function replace(Request $http, string $id, string $pickId, OrderPreparation $preparation)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'identifiers' => ['present', 'array', 'max:1000'],
            'identifiers.*' => ['string', 'max:100', 'distinct'],
            'condition' => ['required', 'in:damaged,quarantine'],
            'location_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'order.preparation_replace:'.$id,
            $data,
            fn ($d) => $preparation->replace($id, $pickId, $d, $http->user()),
            201,
        );
    }

    public function ready(Request $http, string $id, OrderPreparation $preparation)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'packages' => ['required', 'integer', 'min:0', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'order.preparation_ready:'.$id,
            $data,
            fn ($d) => $preparation->ready($id, $d, $http->user()),
        );
    }

    public function report(Request $http, string $id, OrderPreparation $preparation)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'order_line_id' => ['required', 'uuid'],
            'kind' => ['required', 'in:'.implode(',', OrderPreparation::KINDS)],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'order.preparation_anomaly:'.$id,
            $data,
            fn ($d) => $preparation->report($id, $d, $http->user()),
            201,
        );
    }

    public function resolve(Request $http, string $id, OrderPreparation $preparation)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'order.preparation_resolve:'.$id,
            $data,
            fn ($d) => $preparation->resolve($id, $d, $http->user()),
        );
    }
}
