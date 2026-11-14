<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Purchasing;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PurchasingController extends OperationalController
{
    public function index(Request $request, Purchasing $purchases)
    {
        $data = $request->validate([
            'supplier_id' => ['nullable', 'uuid'],
            'status' => ['nullable', 'in:draft,confirmed'],
        ]);
        $query = DB::table('purchase_orders')->orderByDesc('created_at')->orderBy('id');
        foreach (['supplier_id', 'status'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return $this->page($query, $request, fn ($r) => $purchases->dto($r));
    }

    public function show(string $id, Purchasing $purchases)
    {
        return ['data' => $purchases->dto(Records::row('purchase_orders', $id, false))];
    }

    public function store(Request $request, Purchasing $purchases)
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid'],
            'contract_id' => ['required', 'uuid'],
            'purpose' => ['required', 'in:warehouse_stock,customer_needs'],
            'reference' => ['required', 'string', 'max:150'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:offer_id,quantity'],
            'lines.*.offer_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
        ]);

        return $this->command(
            $request,
            'purchase.create',
            $data,
            fn ($d) => $purchases->create($d, $request->user()),
            201,
        );
    }

    public function confirm(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'supplier_confirmation_reference' => ['required', 'string', 'max:150'],
            'credit_override_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'purchase.confirm:'.$id,
            $data,
            fn ($d) => $purchases->confirm($id, $d, $request->user()),
        );
    }

    public function receive(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate([
            'location_id' => ['required', 'uuid'],
            'document_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:150'],
            'received_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => [
                'array:purchase_line_id,quantity,unit_cost,variance_reason,quality,lot_number,expires_on,identifiers',
            ],
            'lines.*.purchase_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_cost' => ['nullable', 'integer', 'min:0', 'max:1000000000000'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:1000'],
            'lines.*.quality' => ['required', 'in:available,quarantine,damaged,returned'],
            'lines.*.lot_number' => ['nullable', 'string', 'max:100'],
            'lines.*.expires_on' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.identifiers' => ['present', 'array', 'max:10000'],
            'lines.*.identifiers.*' => ['required', 'string', 'max:100', 'distinct'],
        ]);

        return $this->command(
            $request,
            'purchase.receive:'.$id,
            $data,
            fn ($d) => $purchases->receive($id, $d, $request->user()),
            201,
        );
    }

    public function receipts(Request $request, string $id)
    {
        Records::row('purchase_orders', $id, false);
        $financial = in_array('purchases.read', $request->user()->permissions(), true);

        return $this->page(
            DB::table('goods_receipts')->where('purchase_order_id', $id)->orderByDesc('created_at')->orderBy('id'),
            $request,
            fn ($r) => [
                'id' => $r->id,
                'purchase_order_id' => $r->purchase_order_id,
                'location_id' => $r->location_id,
                'reference' => $r->reference,
                'received_at' => Records::iso($r->received_at),
                'document_id' => $r->document_id,
                'created_at' => Records::iso($r->created_at),
                'lines' => DB::table('receipt_lines')
                    ->where('receipt_id', $r->id)
                    ->orderBy('id')
                    ->get()
                    ->map(
                        fn ($l) => [
                            'id' => $l->id,
                            'purchase_line_id' => $l->purchase_line_id,
                            'quantity' => (int) $l->quantity,
                            'quality' => $l->quality,
                            'excess_quantity' => (int) $l->excess_quantity,
                            'variance_reason' => $l->variance_reason,
                            'overage_approval_id' => DB::table('receipt_overage_approvals')
                                ->where('receipt_line_id', $l->id)
                                ->value('id'),
                            ...$financial ? ['unit_cost' => (int) $l->unit_cost] : [],
                        ],
                    )
                    ->all(),
            ],
        );
    }

    public function invoices(Request $request, Purchasing $purchases)
    {
        $data = $request->validate([
            'purchase_order_id' => ['nullable', 'uuid'],
            'supplier_id' => ['nullable', 'uuid'],
        ]);
        $query = DB::table('purchase_invoices')->orderByDesc('created_at')->orderBy('id');
        foreach (['purchase_order_id', 'supplier_id'] as $field) {
            if (! empty($data[$field])) {
                $query->where($field, $data[$field]);
            }
        }

        return $this->page($query, $request, fn ($row) => $purchases->invoiceDto($row));
    }

    public function storeInvoice(Request $request, Purchasing $purchases)
    {
        $data = $request->validate($this->invoiceRules());

        return $this->command(
            $request,
            'purchase_invoice.create',
            $data,
            fn ($d) => $purchases->registerInvoice($d, $request->user()),
            201,
        );
    }

    private function invoiceRules(): array
    {
        return [
            'purchase_order_id' => ['required', 'uuid'],
            'number' => ['required', 'string', 'max:150'],
            'issued_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.now('Africa/Douala')->toDateString()],
            'document_id' => ['required', 'uuid'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*' => ['array:purchase_line_id,quantity,unit_payable,variance_reason'],
            'lines.*.purchase_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'lines.*.unit_payable' => ['required', 'integer', 'min:0', 'max:1000000000000'],
            'lines.*.variance_reason' => ['nullable', 'string', 'max:1000'],
            'charges' => ['present', 'array', 'max:20'],
            'charges.*' => ['array:label,amount'],
            'charges.*.label' => ['required', 'string', 'max:150'],
            'charges.*.amount' => ['required', 'string'],
        ];
    }

    public function approveInvoice(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $request,
            'purchase_invoice.approve:'.$id,
            $data,
            fn ($d) => $purchases->approveInvoice($id, $d['version'], $request->user()),
        );
    }

    public function showInvoice(string $id, Purchasing $purchases)
    {
        return ['data' => $purchases->invoiceDto(Records::row('purchase_invoices', $id, false))];
    }

    public function correctInvoice(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate([
            ...$this->invoiceRules(),
            'version' => ['required', 'integer', 'min:1'],
            'correction_reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'purchase_invoice.correct:'.$id,
            $data,
            fn ($d) => $purchases->correctInvoice($id, $d, $request->user()),
        );
    }

    public function cancelRegistration(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'purchase_invoice.cancel:'.$id,
            $data,
            fn ($d) => $purchases->cancelRegistration($id, $d, $request->user()),
        );
    }

    public function invoiceHistory(Request $request, string $id)
    {
        Records::row('purchase_invoices', $id, false);

        return $this->page(
            DB::table('purchase_invoice_revisions')->where('invoice_id', $id)->orderByDesc('version'),
            $request,
            fn ($r) => [
                'version' => (int) $r->version,
                'action' => $r->action,
                'snapshot' => json_decode($r->snapshot, true),
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function approveOverage(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'document_id' => ['required', 'uuid'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $request,
            'purchase.overage:'.$id,
            $data,
            fn ($d) => $purchases->approveOverage($id, $d, $request->user()),
        );
    }

    public function consignmentStatement(Request $request, string $id, Purchasing $purchases)
    {
        $data = $request->validate([
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:starts_on',
                'before_or_equal:'.now('Africa/Douala')->toDateString(),
            ],
            'document_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:150'],
        ]);

        return $this->command(
            $request,
            'consignment.statement:'.$id,
            $data,
            fn ($d) => $purchases->consignStatement($id, $d, $request->user()),
            201,
        );
    }
}
