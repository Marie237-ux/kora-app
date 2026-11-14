<?php

namespace App\Domain\Procurement;

use App\Domain\Shared\Money;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

final class PurchaseCommitment
{
    /** Unrecognized non-consignment purchases plus registered invoices, without double counting billed units. */
    public function unrecognized(?string $supplierId = null): int
    {
        return Money::parse($this->unrecognizedTotal($supplierId));
    }

    /** Read-model aggregate; the existing per-operation credit guards retain their bounded int API. */
    public function unrecognizedTotal(?string $supplierId = null): string
    {
        $amounts = [];
        $lines = DB::table('purchase_lines as l')
            ->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
            ->whereIn('p.status', ['draft', 'confirmed'])
            ->where('p.mode', '!=', 'consignment')
            ->select('l.*', 'p.status as purchase_status');
        if ($supplierId) {
            $lines->where('p.supplier_id', $supplierId);
        }
        foreach ($lines->cursor() as $line) {
            $committed =
                $line->purchase_status === 'confirmed'
                    ? $line->quantity
                    : min($line->quantity, $line->received_quantity);
            $amounts[] = Money::multiply((int) $line->unit_cost, max(0, $committed - $line->invoiced_quantity));
        }
        $extras = DB::table('receipt_overage_approvals as a')
            ->join('purchase_lines as l', 'l.id', '=', 'a.purchase_line_id')
            ->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
            ->leftJoin('audit_events as audit_seq', function ($join) {
                $join
                    ->on('audit_seq.resource_id', '=', DB::raw('CAST(a.id AS VARCHAR(100))'))
                    ->where('audit_seq.action', 'purchase.overage_accepted');
            })
            ->whereIn('p.status', ['draft', 'confirmed'])
            ->where('p.mode', '!=', 'consignment')
            ->select('a.quantity', 'a.unit_cost', 'l.quantity as ordered_quantity', 'l.invoiced_quantity')
            ->selectRaw(
                'SUM(a.quantity) OVER (PARTITION BY l.id ORDER BY a.created_at, audit_seq.id, a.id ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) - a.quantity AS prior_extra_quantity',
            );
        if ($supplierId) {
            $extras->where('p.supplier_id', $supplierId);
        }
        foreach ($extras->cursor() as $extra) {
            $billed = max(0, $extra->invoiced_quantity - $extra->ordered_quantity - $extra->prior_extra_quantity);
            $amounts[] = Money::multiply((int) $extra->unit_cost, $extra->quantity - min($extra->quantity, $billed));
        }
        $invoices = DB::table('purchase_invoices')->where('status', 'registered')->select('amount');
        if ($supplierId) {
            $invoices->where('supplier_id', $supplierId);
        }
        foreach ($invoices->cursor() as $invoice) {
            $amounts[] = (int) $invoice->amount;
        }

        $total = BigInteger::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return (string) $total;
    }
}
