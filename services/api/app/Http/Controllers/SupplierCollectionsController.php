<?php

namespace App\Http\Controllers;

use App\Domain\Delivery\SupplierCollections;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class SupplierCollectionsController extends OperationalController
{
    public function index(Request $http, SupplierCollections $collections)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:unassigned,assigned,accepted,collected,incident,dropped,received,cancelled'],
            'courier_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'uuid'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $collections) {
            $q = DB::table('supplier_collections')->orderBy('created_at')->orderBy('id');
            foreach (['status', 'courier_id', 'warehouse_id'] as $field) {
                if (isset($d[$field])) {
                    $q->where($field, $d[$field]);
                }
            }

            return $this->page($q, $http, fn ($c) => $collections->dispatchView($c));
        });
    }

    public function show(string $id, SupplierCollections $collections)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $collections->dispatchView(Records::row('supplier_collections', $id, false)),
            ),
        ];
    }

    public function create(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'shop_id' => ['required', 'uuid'],
            'warehouse_id' => ['required', 'uuid'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:purchase_line_id,quantity'],
            'lines.*.purchase_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
        ]);

        return $this->command(
            $http,
            'delivery.collection_create:'.$id,
            $data,
            fn ($d) => $collections->create($id, $d, $http->user()),
            201,
        );
    }

    public function createDirect(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'order_id' => ['required', 'uuid'],
            'shop_id' => ['required', 'uuid'],
            'transit_location_id' => ['required', 'uuid'],
            'supervisor_id' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->command(
            $http,
            'delivery.direct_collection_create:'.$id,
            $data,
            fn ($d) => $collections->createDirect($id, $d, $http->user()),
            201,
        );
    }

    /** Receipt at the shop by the manager designated to go along with the courier. */
    public function directReceipt(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate(self::directReceiptRules());

        return $this->command(
            $http,
            'delivery.direct_receipt:'.$id,
            $data,
            fn ($d) => $collections->receiveDirect($id, $d, $http->user()),
        );
    }

    public static function directReceiptRules(): array
    {
        return [
            'version' => ['required', 'integer', 'min:1'],
            'document_id' => ['required', 'uuid'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:purchase_line_id,quantity,identifiers,lot_number,expires_on'],
            'lines.*.purchase_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.identifiers' => ['present', 'array', 'max:10000'],
            'lines.*.identifiers.*' => ['required', 'string', 'max:100', 'distinct'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function assign(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'courier_id' => ['required', 'integer', 'min:1'],
            'acceptance_required' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'delivery.collection_assign:'.$id,
            $data,
            fn ($d) => $collections->assign($id, $d, $http->user()),
        );
    }

    public function cancel(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'delivery.collection_cancel:'.$id,
            $data,
            fn ($d) => $collections->cancel($id, $d, $http->user()),
        );
    }

    public function retry(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'delivery.collection_retry:'.$id,
            $data,
            fn ($d) => $collections->retry($id, $d, $http->user()),
        );
    }

    public function received(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'receipt_id' => ['required', 'uuid'],
        ]);

        return $this->command(
            $http,
            'delivery.collection_received:'.$id,
            $data,
            fn ($d) => $collections->received($id, $d, $http->user()),
        );
    }

    public function courierIndex(Request $http, SupplierCollections $collections)
    {
        $courier = $http->user();

        return app(ReadSnapshot::class)->run(
            fn () => $this->page(
                DB::table('supplier_collections')
                    ->where('courier_id', $courier->id)
                    ->whereIn('status', ['assigned', 'accepted', 'collected', 'incident'])
                    ->orderBy('created_at')
                    ->orderBy('id'),
                $http,
                fn ($c) => $collections->courierView($c, $courier),
            ),
        );
    }

    public function courierShow(Request $http, string $id, SupplierCollections $collections)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $collections->courierView(Records::row('supplier_collections', $id, false), $http->user()),
            ),
        ];
    }

    public function accept(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'courier.collection_accept:'.$id,
            $data,
            fn ($d) => $collections->accept($id, $d, $http->user()),
        );
    }

    public function decline(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'courier.collection_decline:'.$id,
            $data,
            fn ($d) => $collections->decline($id, $d, $http->user()),
        );
    }

    public function collect(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
            'lines' => ['sometimes', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:purchase_line_id,quantity'],
            'lines.*.purchase_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'courier.collection_collect:'.$id,
            $data,
            fn ($d) => $collections->collect($id, $d, $http->user()),
        );
    }

    public function incident(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'kind' => ['required', 'in:'.implode(',', SupplierCollections::INCIDENTS)],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'courier.collection_incident:'.$id,
            $data,
            fn ($d) => $collections->reportIncident($id, $d, $http->user()),
            201,
        );
    }

    public function drop(Request $http, string $id, SupplierCollections $collections)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'packages' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->command(
            $http,
            'courier.collection_drop:'.$id,
            $data,
            fn ($d) => $collections->drop($id, $d, $http->user()),
        );
    }
}
