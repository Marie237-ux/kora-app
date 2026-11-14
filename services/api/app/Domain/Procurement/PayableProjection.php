<?php

namespace App\Domain\Procurement;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class PayableProjection
{
    public function query(?string $supplierId = null, ?string $contractId = null): Builder
    {
        $paid = DB::table('supplier_payment_allocations')
            ->select('obligation_id')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('obligation_id');
        $credited = DB::table('supplier_credit_allocations')
            ->select('obligation_id')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('obligation_id');
        $q = DB::table('payable_schedule as s')
            ->join('supplier_obligations as o', 'o.id', '=', 's.obligation_id')
            ->join('purchase_orders as p', 'p.id', '=', 'o.purchase_order_id')
            ->leftJoinSub($paid, 'paid', 'paid.obligation_id', '=', 'o.id')
            ->leftJoinSub($credited, 'credited', 'credited.obligation_id', '=', 'o.id')
            ->whereColumn('s.version', 'o.schedule_version')
            ->select(
                's.id',
                's.obligation_id',
                's.version',
                's.sequence',
                's.due_on',
                's.amount',
                'o.supplier_id',
                'o.purchase_order_id',
                'p.contract_id',
            )
            ->selectRaw('COALESCE(paid.total, 0) + COALESCE(credited.total, 0) AS covered')
            ->selectRaw(
                'SUM(s.amount) OVER (PARTITION BY o.id ORDER BY s.due_on, s.sequence ROWS BETWEEN UNBOUNDED PRECEDING AND CURRENT ROW) - s.amount AS prior_amount',
            );
        if ($supplierId) {
            $q->where('o.supplier_id', $supplierId);
        }
        if ($contractId) {
            $q->where('p.contract_id', $contractId);
        }
        // Filtering/pagination must happen OUTSIDE this window projection.
        $remaining = DB::query()
            ->fromSub($q, 'installments')
            ->select('installments.*')
            ->selectRaw(
                'CASE WHEN covered <= prior_amount THEN amount WHEN covered >= prior_amount + amount THEN 0 ELSE prior_amount + amount - covered END AS outstanding',
            );

        return DB::query()->fromSub($remaining, 'installment_balances');
    }

    public function remaining(object $row): int
    {
        return (int) $row->outstanding;
    }
}
