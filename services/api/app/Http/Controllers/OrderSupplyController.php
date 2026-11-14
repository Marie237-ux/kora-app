<?php

namespace App\Http\Controllers;

use App\Domain\Orders\Orders;
use App\Domain\Orders\OrderSupply;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;

final class OrderSupplyController extends OperationalController
{
    public function requestReservation(Request $http, string $id, string $lineId, OrderSupply $supply)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'expires_at' => ['required', 'date', 'after:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);

        return $this->command(
            $http,
            'order.supply_request:'.$lineId,
            $data,
            fn ($d) => $supply->request(Records::row('orders', $id), $lineId, $d, $http->user()),
            201,
        );
    }

    public function unavailable(Request $http, string $id, string $lineId, OrderSupply $supply)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'missing_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'message' => ['required', 'array:fr,en'],
            'message.fr' => ['required', 'string', 'max:1000'],
            'message.en' => ['required', 'string', 'max:1000'],
            'reference' => ['required', 'string', 'max:150'],
        ]);

        return $this->command(
            $http,
            'order.supply_unavailable:'.$lineId,
            $data,
            fn ($d) => $supply->unavailable(Records::row('orders', $id), $lineId, $d, $http->user()),
        );
    }

    public function choose(Request $http, string $id, string $caseId, OrderSupply $supply, Orders $orders)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'choice' => ['required', 'in:wait,refund_missing'],
            'accepted_refund_amount' => [
                'required_if:choice,refund_missing',
                'nullable',
                'string',
                'regex:/^(0|[1-9][0-9]{0,18})$/',
            ],
        ]);

        return $this->command(
            $http,
            'order.supply_choice:'.$caseId,
            $data,
            fn ($d) => $supply->choose(
                $orders->owned($id, $http->user(), true),
                $caseId,
                $d['version'],
                $d['choice'],
                $d['accepted_refund_amount'] ?? null,
                $http->user(),
            ),
        );
    }
}
