<?php

namespace App\Http\Controllers;

use App\Domain\AfterSales\AfterSalesRepairs;
use App\Domain\AfterSales\AfterSalesTrips;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Repairs of customers' units and defective units sent back to suppliers, from after-sales cases. */
final class AfterSalesRepairsController extends OperationalController
{
    public function index(Request $http, AfterSalesRepairs $repairs)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:open,at_repairer,back,returning,returned'],
            'supplier_id' => ['nullable', 'uuid'],
        ]);
        $q = DB::table('after_sales_repairs')->orderBy('created_at')->orderBy('id');
        foreach (['status', 'supplier_id'] as $key) {
            if (isset($d[$key])) {
                $q->where($key, $d[$key]);
            }
        }

        return $this->page($q, $http, fn ($r) => $repairs->dto($r));
    }

    public function open(Request $http, string $id, AfterSalesRepairs $repairs)
    {
        $data = $http->validate([
            'return_id' => ['required', 'uuid'],
            'repairer_kind' => ['required', 'in:supplier,third_party,internal'],
            'supplier_id' => ['nullable', 'uuid', 'required_if:repairer_kind,supplier'],
            'repairer_name' => ['nullable', 'string', 'max:150', 'required_if:repairer_kind,third_party'],
        ]);

        return $this->command(
            $http,
            'after_sales.repair_open:'.$id,
            $data,
            fn ($d) => $repairs->open($id, $d, $http->user()),
            201,
        );
    }

    public function send(Request $http, string $id, AfterSalesRepairs $repairs)
    {
        $data = $http->validate([
            'version' => ['required', 'integer'],
            'location_id' => ['required', 'uuid'],
            'document_id' => ['required', 'uuid'],
            'reference' => ['nullable', 'string', 'max:150'],
        ]);

        return $this->command(
            $http,
            'after_sales.repair_send:'.$id,
            $data,
            fn ($d) => $repairs->send($id, $d, $http->user()),
        );
    }

    public function back(Request $http, string $id, AfterSalesRepairs $repairs)
    {
        $data = $http->validate([
            'version' => ['required', 'integer'],
            'location_id' => ['required', 'uuid'],
            'outcome' => ['required', 'in:repaired,not_repairable'],
            'note' => ['required', 'string', 'max:2000'],
            'document_id' => ['nullable', 'uuid'],
            'cost' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'cost_bearer' => ['nullable', 'required_with:cost', 'in:supplier,kora,customer'],
        ]);

        return $this->command(
            $http,
            'after_sales.repair_back:'.$id,
            $data,
            fn ($d) => $repairs->back($id, $d, $http->user()),
        );
    }

    public function returnTrip(Request $http, string $id, AfterSalesTrips $trips)
    {
        $data = $http->validate([
            'repair_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->command(
            $http,
            'after_sales.repair_return:'.$data['repair_id'],
            $data,
            fn ($d) => $trips->createRepairReturn($id, $d, $http->user()),
            201,
        );
    }

    public function toSupplier(Request $http, string $id, AfterSalesRepairs $repairs)
    {
        $data = $http->validate([
            'balance_id' => ['required', 'uuid'],
            'quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'reason' => ['required', 'string', 'max:1000'],
            'supplier_reference' => ['required', 'string', 'max:150'],
            'document_id' => ['required', 'uuid'],
        ]);

        return $this->command(
            $http,
            'after_sales.to_supplier:'.$id,
            $data,
            fn ($d) => $repairs->sendToSupplier($id, $d, $http->user()),
            201,
        );
    }

    public function decision(Request $http, string $id, AfterSalesRepairs $repairs)
    {
        $data = $http->validate([
            'version' => ['required', 'integer'],
            'decision' => ['required', 'in:credit,replacement,repair,refused'],
            'note' => ['required', 'string', 'max:2000'],
            'document_id' => ['nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'after_sales.supplier_decision:'.$id,
            $data,
            fn ($d) => $repairs->supplierDecision($id, $d, $http->user()),
        );
    }
}
