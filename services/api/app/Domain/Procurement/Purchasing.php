<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Inventory\Inventory;
use App\Domain\Pricing\AgreementResolver;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Purchasing
{
    public function create(array $data, User $actor): array
    {
        $supplier = Records::supplier($data['supplier_id']);
        if ($supplier->status !== 'active') {
            Money::fail('supplier_id', 'SUPPLIER_INACTIVE');
        }
        $contract = app(Contracts::class)->effective($data['contract_id'], $supplier->id);
        $snapshot = app(Contracts::class)->dto($contract);
        $terms = $snapshot['terms'];
        $lines = [];
        $amounts = [];
        foreach ($data['lines'] as $input) {
            $context = app(Inventory::class)->offerTerms($input['offer_id']);
            if ($context['shop']->supplier_id !== $supplier->id) {
                Money::fail('lines', 'SUPPLIER_MISMATCH');
            }
            $profile = DB::table('inventory_profiles')->where('variant_id', $context['variant']->id)->first();
            if (! $profile) {
                Money::fail('lines', 'PROFILE_REQUIRED');
            }
            $quote = app(AgreementResolver::class)->quote(
                $supplier->id,
                $context['product']->id,
                $context['product']->category_id,
                (int) $context['offer']->reference_price,
                (int) $context['offer']->reference_price,
                $input['quantity'],
            );
            $cost = $quote['calculation']['purchase_unit'];
            $amounts[] = Money::multiply($cost, $input['quantity']);
            $lines[] = [
                'id' => Records::id(),
                'offer_id' => $input['offer_id'],
                'variant_id' => $context['variant']->id,
                'quantity' => $input['quantity'],
                'unit_cost' => $cost,
                'terms_hash' => $context['hash'],
                'tracking_mode' => $profile->tracking_mode,
                'requires_expiry' => $profile->requires_expiry,
                'commercial_snapshot' => Records::json([
                    'terms' => $context['terms'],
                    'quote' => $quote,
                    'profile' => [
                        'tracking_mode' => $profile->tracking_mode,
                        'requires_expiry' => (bool) $profile->requires_expiry,
                        'logistics' => json_decode($profile->logistics, true),
                        'version' => (int) $profile->version,
                    ],
                ]),
            ];
        }
        $id = Records::id();
        $total = Money::sum($amounts);
        DB::table('purchase_orders')->insert([
            'id' => $id,
            'supplier_id' => $supplier->id,
            'contract_id' => $contract->id,
            'contract_snapshot' => Records::json($snapshot),
            'mode' => $terms['mode'],
            'purpose' => $data['purpose'],
            'reference' => $data['reference'],
            'expected_total' => $total,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($lines as $line) {
            DB::table('purchase_lines')->insert([
                ...$line,
                'purchase_order_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        Records::audit($actor, 'purchase.created', $id, [
            'contract_id' => $contract->id,
            'contract_version' => $contract->version,
            'expected_total' => (string) $total,
        ]);

        return $this->dto(Records::row('purchase_orders', $id));
    }

    public function confirm(string $id, array $data, User $actor): array
    {
        $initial = Records::row('purchase_orders', $id, false);
        Records::supplier($initial->supplier_id);
        $order = Records::row('purchase_orders', $id);
        abort_unless((int) $order->version === $data['version'], 409);
        if ($order->status !== 'draft') {
            Money::fail('status', 'PURCHASE_LOCKED');
        }
        app(Contracts::class)->effective($order->contract_id, $order->supplier_id);
        $terms = json_decode($order->contract_snapshot, true)['terms'];
        $exposure = app(Payables::class)->commitment($order->supplier_id);
        $limit = $terms['credit_limit'] === null ? null : Money::parse($terms['credit_limit']);
        $additional = [];
        foreach (DB::table('purchase_lines')->where('purchase_order_id', $id)->get() as $line) {
            $additional[] = Money::multiply(
                (int) $line->unit_cost,
                max(0, $line->quantity - max($line->received_quantity, $line->invoiced_quantity)),
            );
        }
        if ($limit !== null && Money::sum([$exposure, Money::sum($additional)]) > $limit) {
            if (empty($data['credit_override_reason']) || ! in_array('contracts.approve', $actor->permissions(), true)) {
                Money::fail('credit_limit', 'CREDIT_LIMIT_EXCEEDED');
            }
        }
        DB::table('purchase_orders')
            ->where('id', $id)
            ->update([
                'status' => 'confirmed',
                'version' => $order->version + 1,
                'supplier_confirmation_reference' => $data['supplier_confirmation_reference'],
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'purchase.confirmed', $id, [
            'credit_override_reason' => $data['credit_override_reason'] ?? null,
            'exposure_before' => (string) $exposure,
        ]);

        return $this->dto(Records::row('purchase_orders', $id));
    }

    public function receive(string $id, array $data, User $actor): array
    {
        $initial = Records::row('purchase_orders', $id, false);
        Records::supplier($initial->supplier_id);
        $order = Records::row('purchase_orders', $id);
        if (! in_array($order->status, ['draft', 'confirmed'], true)) {
            Money::fail('status', 'PURCHASE_LOCKED');
        }
        app(Inventory::class)->location($data['location_id']);
        $document = app(PrivateDocuments::class)->requireClean($data['document_id'], $order->supplier_id, [
            'stock_evidence',
            'supplier_invoice',
        ]);
        $receiptId = Records::id();
        $receivedAt = CarbonImmutable::parse($data['received_at'])->utc();
        DB::table('goods_receipts')->insert([
            'id' => $receiptId,
            'purchase_order_id' => $id,
            'location_id' => $data['location_id'],
            'received_at' => $receivedAt,
            'reference' => $data['reference'],
            'document_id' => $document->id,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $lots = [];
        foreach (collect($data['lines'])->sortBy('purchase_line_id')->values() as $input) {
            $line = Records::row('purchase_lines', $input['purchase_line_id']);
            if ($line->purchase_order_id !== $id) {
                Money::fail('lines', 'PURCHASE_LINE_MISMATCH');
            }
            $input['unit_cost'] ??= (int) $line->unit_cost;
            $input['document_id'] = $document->id;
            if ($input['unit_cost'] !== (int) $line->unit_cost) {
                $required = $order->mode === 'consignment' ? 'finance.write' : 'purchases.write';
                abort_unless(in_array($required, $actor->permissions(), true), 403);
            }
            $excess = max(0, $input['quantity'] - max(0, $line->quantity - $line->received_quantity));
            if (($input['unit_cost'] !== (int) $line->unit_cost || $excess > 0) && empty($input['variance_reason'])) {
                Money::fail('variance_reason', 'VARIANCE_REASON_REQUIRED');
            }
            if ($excess > 0 && $input['quality'] === 'available') {
                Money::fail('quality', 'EXCESS_QUARANTINE');
            }
            if ($line->received_quantity + $input['quantity'] > 1000000000) {
                Money::fail('quantity', 'QUANTITY_INVALID');
            }
            $receiptLineId = Records::id();
            DB::table('receipt_lines')->insert([
                'id' => $receiptLineId,
                'receipt_id' => $receiptId,
                'purchase_line_id' => $line->id,
                'quantity' => $input['quantity'],
                'unit_cost' => $input['unit_cost'],
                'variance_reason' => $input['variance_reason'] ?? null,
                'quality' => $input['quality'],
                'excess_quantity' => $excess,
            ]);
            $lots[] = app(Inventory::class)->receive(
                $line,
                $receiptLineId,
                $data['location_id'],
                $input,
                $order->mode,
                $actor,
            );
            DB::table('purchase_lines')
                ->where('id', $line->id)
                ->update(['received_quantity' => $line->received_quantity + $input['quantity'], 'updated_at' => now()]);
        }
        app(Payables::class)->scheduleAfterReceipt($order, $actor);
        Records::audit($actor, 'purchase.received', $receiptId, [
            'purchase_order_id' => $id,
            'document_sha256' => $document->sha256,
        ]);
        Records::event('GoodsReceived', $receiptId, ['id' => $receiptId, 'purchase_order_id' => $id]);

        return [
            'id' => $receiptId,
            'purchase_order_id' => $id,
            'lot_ids' => $lots,
            'received_at' => $receivedAt->toIso8601String(),
            'purchase_status' => $order->status,
            'supplier_payment_implied' => false,
        ];
    }

    public function registerInvoice(array $data, User $actor): array
    {
        $initial = Records::row('purchase_orders', $data['purchase_order_id'], false);
        Records::supplier($initial->supplier_id);
        $order = Records::row('purchase_orders', $initial->id);
        if ($order->mode === 'consignment') {
            Money::fail('purchase_order_id', 'CONSIGNMENT_STATEMENT_REQUIRED');
        }
        $document = app(PrivateDocuments::class)->requireClean($data['document_id'], $order->supplier_id, [
            'supplier_invoice',
        ]);
        [$lines, $amounts] = $this->invoiceLines($order, $data['lines']);
        foreach ($data['charges'] as $charge) {
            $amounts[] = Money::parse($charge['amount']);
        }
        $amount = Money::sum($amounts);
        $id = Records::id();
        DB::table('purchase_invoices')->insert([
            'id' => $id,
            'purchase_order_id' => $order->id,
            'supplier_id' => $order->supplier_id,
            'number' => $data['number'],
            'issued_on' => $data['issued_on'],
            'document_id' => $document->id,
            'amount' => $amount,
            'charges' => Records::json($data['charges']),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($lines as $line) {
            DB::table('purchase_invoice_lines')->insert(['id' => Records::id(), 'invoice_id' => $id, ...$line]);
        }
        Records::audit($actor, 'purchase_invoice.registered', $id, [
            'document_sha256' => $document->sha256,
            'amount' => (string) $amount,
        ]);
        $this->invoiceRevision($id, 'registered', $actor);

        return $this->invoiceDto(Records::row('purchase_invoices', $id));
    }

    public function approveInvoice(string $id, int $version, User $actor): array
    {
        $initial = Records::row('purchase_invoices', $id, false);
        Records::supplier($initial->supplier_id);
        $invoice = Records::row('purchase_invoices', $id);
        if ($invoice->status !== 'registered') {
            Money::fail('status', 'INVOICE_ALREADY_APPROVED');
        }
        abort_unless((int) $invoice->version === $version, 409);
        app(PrivateDocuments::class)->requireClean($invoice->document_id, $invoice->supplier_id, ['supplier_invoice']);
        $order = Records::row('purchase_orders', $invoice->purchase_order_id);
        DB::table('purchase_invoices')
            ->where('id', $id)
            ->update([
                'status' => 'approved',
                'version' => $version + 1,
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'updated_at' => now(),
            ]);
        $obligation =
            $invoice->amount > 0
                ? app(Payables::class)->createObligation(
                    $order,
                    'invoice',
                    $id,
                    (int) $invoice->amount,
                    $invoice->issued_on,
                    $actor,
                )
                : null;
        Records::audit($actor, 'purchase_invoice.approved', $id, ['obligation_id' => $obligation['id'] ?? null]);
        $this->invoiceRevision($id, 'approved', $actor);

        return ['invoice' => $this->invoiceDto(Records::row('purchase_invoices', $id)), 'obligation' => $obligation];
    }

    public function consignStatement(string $orderId, array $data, User $actor): array
    {
        $initial = Records::row('purchase_orders', $orderId, false);
        Records::supplier($initial->supplier_id);
        $order = Records::row('purchase_orders', $orderId);
        if ($order->mode !== 'consignment') {
            Money::fail('purchase_order_id', 'CONSIGNMENT_ONLY');
        }
        app(PrivateDocuments::class)->requireClean($data['document_id'], $order->supplier_id, [
            'supplier_invoice',
            'supplier_contract',
        ]);
        $start = CarbonImmutable::parse($data['starts_on'], 'Africa/Douala')->startOfDay()->utc();
        $end = CarbonImmutable::parse($data['ends_on'], 'Africa/Douala')->addDay()->startOfDay()->utc();
        $lotIds = DB::table('stock_lots')
            ->join('purchase_lines', 'purchase_lines.id', '=', 'stock_lots.purchase_line_id')
            ->where('purchase_lines.purchase_order_id', $orderId)
            ->pluck('stock_lots.id');
        $sales = DB::table('consignment_sales')
            ->whereIn('lot_id', $lotIds)
            ->whereNull('statement_id')
            ->where('sold_at', '>=', $start)
            ->where('sold_at', '<', $end)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $amount = Money::sum($sales->map(fn ($s) => Money::multiply((int) $s->unit_cost, (int) $s->quantity))->all());
        if ($sales->isEmpty()) {
            Money::fail('period', 'NO_UNSETTLED_CONSIGNMENT');
        }
        $id = Records::id();
        DB::table('consignment_statements')->insert([
            'id' => $id,
            'purchase_order_id' => $orderId,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'amount' => $amount,
            'document_id' => $data['document_id'],
            'reference' => $data['reference'],
            'approved_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('consignment_sales')
            ->whereIn('id', $sales->pluck('id'))
            ->update(['statement_id' => $id]);
        $obligation =
            $amount > 0
                ? app(Payables::class)->createObligation(
                    $order,
                    'consignment_statement',
                    $id,
                    $amount,
                    $data['ends_on'],
                    $actor,
                )
                : null;
        Records::audit($actor, 'consignment_statement.approved', $id, ['amount' => (string) $amount]);

        return [
            'id' => $id,
            'amount' => (string) $amount,
            'quantity_sold' => (int) $sales->sum('quantity'),
            'obligation' => $obligation,
        ];
    }

    public function dto(object $row): array
    {
        return [
            'id' => $row->id,
            'supplier_id' => $row->supplier_id,
            'contract_id' => $row->contract_id,
            'contract_snapshot' => json_decode($row->contract_snapshot, true),
            'mode' => $row->mode,
            'purpose' => $row->purpose,
            'reference' => $row->reference,
            'status' => $row->status,
            'expected_total' => (string) $row->expected_total,
            'version' => (int) $row->version,
            'supplier_confirmation_reference' => $row->supplier_confirmation_reference,
            'confirmed_at' => Records::iso($row->confirmed_at),
            'lines' => DB::table('purchase_lines')
                ->where('purchase_order_id', $row->id)
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($line) => [
                        'id' => $line->id,
                        'offer_id' => $line->offer_id,
                        'variant_id' => $line->variant_id,
                        'quantity' => (int) $line->quantity,
                        'received_quantity' => (int) $line->received_quantity,
                        'invoiced_quantity' => (int) $line->invoiced_quantity,
                        'approved_overage_quantity' => (int) $line->approved_overage_quantity,
                        'unit_cost' => (int) $line->unit_cost,
                        'tracking_mode' => $line->tracking_mode,
                        'requires_expiry' => (bool) $line->requires_expiry,
                        'commercial_snapshot' => json_decode($line->commercial_snapshot, true),
                    ],
                )
                ->all(),
            'created_at' => Records::iso($row->created_at),
        ];
    }

    public function invoiceDto(object $row): array
    {
        return [
            'id' => $row->id,
            'purchase_order_id' => $row->purchase_order_id,
            'supplier_id' => $row->supplier_id,
            'number' => $row->number,
            'issued_on' => $row->issued_on,
            'document_id' => $row->document_id,
            'amount' => (string) $row->amount,
            'charges' => json_decode($row->charges, true),
            'status' => $row->status,
            'version' => (int) $row->version,
            'lines' => DB::table('purchase_invoice_lines')
                ->where('invoice_id', $row->id)
                ->orderBy('id')
                ->get()
                ->map(
                    fn ($l) => [
                        'purchase_line_id' => $l->purchase_line_id,
                        'quantity' => (int) $l->quantity,
                        'unit_payable' => (int) $l->unit_payable,
                        'variance_reason' => $l->variance_reason,
                    ],
                )
                ->all(),
            'approved_at' => Records::iso($row->approved_at),
            'created_at' => Records::iso($row->created_at),
        ];
    }

    private function invoiceLines(object $order, array $inputs): array
    {
        $amounts = [];
        $lines = [];
        foreach (collect($inputs)->sortBy('purchase_line_id')->values() as $input) {
            $line = Records::row('purchase_lines', $input['purchase_line_id']);
            if (
                $line->purchase_order_id !== $order->id ||
                $input['quantity'] > $line->quantity + $line->approved_overage_quantity - $line->invoiced_quantity
            ) {
                Money::fail('lines', 'INVOICE_QUANTITY_EXCEEDED');
            }
            if ($input['unit_payable'] !== (int) $line->unit_cost && empty($input['variance_reason'])) {
                Money::fail('variance_reason', 'VARIANCE_REASON_REQUIRED');
            }
            $amounts[] = Money::multiply($input['unit_payable'], $input['quantity']);
            $lines[] = $input;
            DB::table('purchase_lines')
                ->where('id', $line->id)
                ->update(['invoiced_quantity' => $line->invoiced_quantity + $input['quantity'], 'updated_at' => now()]);
        }

        return [$lines, $amounts];
    }

    public function correctInvoice(string $id, array $data, User $actor): array
    {
        $initial = Records::row('purchase_invoices', $id, false);
        Records::supplier($initial->supplier_id);
        $invoice = Records::row('purchase_invoices', $id);
        $order = Records::row('purchase_orders', $invoice->purchase_order_id);
        if (
            ! in_array($invoice->status, ['registered', 'cancelled'], true) ||
            $data['purchase_order_id'] !== $order->id
        ) {
            Money::fail('status', 'INVOICE_ALREADY_APPROVED');
        }
        abort_unless((int) $invoice->version === $data['version'], 409);
        $document = app(PrivateDocuments::class)->requireClean($data['document_id'], $invoice->supplier_id, [
            'supplier_invoice',
        ]);
        if ($invoice->status === 'registered') {
            $this->releaseInvoiceQuantities($invoice);
        }
        [$lines, $amounts] = $this->invoiceLines($order, $data['lines']);
        foreach ($data['charges'] as $charge) {
            $amounts[] = Money::parse($charge['amount']);
        }
        $amount = Money::sum($amounts);
        DB::table('purchase_invoice_lines')->where('invoice_id', $id)->delete();
        foreach ($lines as $line) {
            DB::table('purchase_invoice_lines')->insert(['id' => Records::id(), 'invoice_id' => $id, ...$line]);
        }
        DB::table('purchase_invoices')
            ->where('id', $id)
            ->update([
                'number' => $data['number'],
                'issued_on' => $data['issued_on'],
                'document_id' => $document->id,
                'amount' => $amount,
                'charges' => Records::json($data['charges']),
                'status' => 'registered',
                'version' => $invoice->version + 1,
                'updated_at' => now(),
            ]);
        $this->invoiceRevision($id, 'registration_corrected', $actor, $data['correction_reason']);
        Records::audit($actor, 'purchase_invoice.registration_corrected', $id, [
            'version' => $invoice->version + 1,
            'reason' => $data['correction_reason'],
        ]);

        return $this->invoiceDto(Records::row('purchase_invoices', $id));
    }

    public function cancelRegistration(string $id, array $data, User $actor): array
    {
        $initial = Records::row('purchase_invoices', $id, false);
        Records::supplier($initial->supplier_id);
        $invoice = Records::row('purchase_invoices', $id);
        if ($invoice->status !== 'registered') {
            Money::fail('status', 'INVOICE_ALREADY_APPROVED');
        }
        abort_unless((int) $invoice->version === $data['version'], 409);
        $this->releaseInvoiceQuantities($invoice);
        DB::table('purchase_invoices')
            ->where('id', $id)
            ->update(['status' => 'cancelled', 'version' => $invoice->version + 1, 'updated_at' => now()]);
        $this->invoiceRevision($id, 'registration_cancelled', $actor, $data['reason']);
        Records::audit($actor, 'purchase_invoice.registration_cancelled', $id, ['reason' => $data['reason']]);

        return $this->invoiceDto(Records::row('purchase_invoices', $id));
    }

    private function releaseInvoiceQuantities(object $invoice): void
    {
        foreach (
            DB::table('purchase_invoice_lines')->where('invoice_id', $invoice->id)->orderBy('purchase_line_id')->get() as $old
        ) {
            $line = Records::row('purchase_lines', $old->purchase_line_id);
            if ($line->invoiced_quantity < $old->quantity) {
                Money::fail('invoice', 'STOCK_INCONSISTENT');
            }
            DB::table('purchase_lines')
                ->where('id', $line->id)
                ->update(['invoiced_quantity' => $line->invoiced_quantity - $old->quantity, 'updated_at' => now()]);
        }
    }

    public function approveOverage(string $receiptLineId, array $data, User $actor): array
    {
        $initial = Records::row('receipt_lines', $receiptLineId, false);
        $line = Records::row('purchase_lines', $initial->purchase_line_id, false);
        $initialOrder = Records::row('purchase_orders', $line->purchase_order_id, false);
        Records::supplier($initialOrder->supplier_id);
        $order = Records::row('purchase_orders', $initialOrder->id);
        $line = Records::row('purchase_lines', $line->id);
        abort_unless((int) $order->version === $data['version'], 409);
        if (
            $initial->excess_quantity < 1 ||
            DB::table('receipt_overage_approvals')->where('receipt_line_id', $receiptLineId)->exists()
        ) {
            Money::fail('receipt', 'OVERAGE_ALREADY_RECONCILED');
        }
        app(PrivateDocuments::class)->requireClean($data['document_id'], $order->supplier_id, ['supplier_contract']);
        if ($line->approved_overage_quantity + $initial->excess_quantity > 1000000000) {
            Money::fail('quantity', 'QUANTITY_INVALID');
        }
        $id = Records::id();
        DB::table('receipt_overage_approvals')->insert([
            'id' => $id,
            'receipt_line_id' => $receiptLineId,
            'purchase_line_id' => $line->id,
            'quantity' => $initial->excess_quantity,
            'unit_cost' => $initial->unit_cost,
            'document_id' => $data['document_id'],
            'reason' => $data['reason'],
            'approved_by' => $actor->id,
            'created_at' => now(),
        ]);
        DB::table('purchase_lines')
            ->where('id', $line->id)
            ->increment('approved_overage_quantity', $initial->excess_quantity);
        DB::table('purchase_orders')
            ->where('id', $order->id)
            ->update(['version' => $order->version + 1, 'updated_at' => now()]);
        Records::audit($actor, 'purchase.overage_accepted', $id, [
            'receipt_line_id' => $receiptLineId,
            'quantity' => $initial->excess_quantity,
            'document_id' => $data['document_id'],
        ]);

        return [
            'id' => $id,
            'receipt_line_id' => $receiptLineId,
            'approved_quantity' => (int) $initial->excess_quantity,
            'purchase_order' => $this->dto(Records::row('purchase_orders', $order->id)),
        ];
    }

    private function invoiceRevision(string $id, string $action, User $actor, ?string $reason = null): void
    {
        $invoice = Records::row('purchase_invoices', $id);
        DB::table('purchase_invoice_revisions')->insert([
            'invoice_id' => $id,
            'version' => $invoice->version,
            'action' => $action,
            'snapshot' => Records::json(['invoice' => $this->invoiceDto($invoice), 'reason' => $reason]),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
    }
}
