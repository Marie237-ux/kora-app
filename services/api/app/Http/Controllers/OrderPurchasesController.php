<?php

namespace App\Http\Controllers;

use App\Domain\Orders\OrderPurchases;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class OrderPurchasesController extends OperationalController
{
    public function show(string $id, OrderPurchases $allocations)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(fn () => $allocations->view(Records::row('orders', $id, false))),
        ];
    }

    public function assign(Request $http, string $id, string $lineId, OrderPurchases $allocations)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'purchase_line_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $this->command(
            $http,
            'order.purchase_assign:'.$lineId,
            $data,
            fn ($d) => $allocations->assign(Records::row('orders', $id), $lineId, $d, $http->user()),
            201,
        );
    }

    public function receive(Request $http, string $id, OrderPurchases $allocations)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'receipt_line_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $this->command(
            $http,
            'order.receipt_assign:'.$id,
            $data,
            fn ($d) => $allocations->receive($this->orderForAllocation($id), $id, $d, $http->user()),
            201,
        );
    }

    public function releasePurchase(Request $http, string $id, OrderPurchases $allocations)
    {
        $data = $this->releaseInput($http);

        return $this->command(
            $http,
            'order.purchase_release:'.$id,
            $data,
            fn ($d) => $allocations->releasePurchase($this->orderForAllocation($id), $id, $d, $http->user()),
        );
    }

    public function releaseReceipt(Request $http, string $id, OrderPurchases $allocations)
    {
        $data = $this->releaseInput($http);

        return $this->command($http, 'order.receipt_release:'.$id, $data, function ($d) use (
            $http,
            $id,
            $allocations,
        ) {
            $receipt = Records::row('order_receipt_allocations', $id, false);

            return $allocations->releaseReceipt(
                $this->orderForAllocation($receipt->purchase_allocation_id),
                $id,
                $d,
                $http->user(),
            );
        });
    }

    private function orderForAllocation(string $id): object
    {
        $allocation = Records::row('order_purchase_allocations', $id, false);
        $orderId = DB::table('order_lines')->where('id', $allocation->order_line_id)->value('order_id');

        return Records::row('orders', $orderId);
    }

    private function releaseInput(Request $http): array
    {
        return $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
    }
}
