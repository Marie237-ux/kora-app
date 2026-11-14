<?php

namespace App\Domain\Procurement;

use App\Domain\Shared\Money;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class PayableForecast
{
    private function sum(array $amounts): string
    {
        $total = BigInteger::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return (string) $total;
    }

    /** Run in a ReadSnapshot. Funds not yet reconciled by another domain remain unknown. */
    public function calculate(string $from, string $to, ?string $supplierId = null, ?string $today = null): array
    {
        $projection = app(PayableProjection::class);
        $days = [];
        $before = [];
        $within = [];
        $after = [];
        $past = [];
        $today ??= CarbonImmutable::now('Africa/Douala')->toDateString();
        foreach ($projection->query($supplierId)->orderBy('due_on')->orderBy('id')->cursor() as $row) {
            $remaining = $projection->remaining($row);
            if ($remaining < 1) {
                continue;
            }
            if ($row->due_on < $today) {
                $past[] = $remaining;
            }
            if ($row->due_on < $from) {
                $before[] = $remaining;
            } elseif ($row->due_on > $to) {
                $after[] = $remaining;
            } else {
                $within[] = $remaining;
                $days[$row->due_on] ??= ['due_on' => $row->due_on, 'amount' => 0, 'installment_count' => 0];
                $days[$row->due_on]['amount'] = $this->sum([$days[$row->due_on]['amount'], $remaining]);
                $days[$row->due_on]['installment_count']++;
            }
        }
        $undated = [];
        $advances = [];
        $credits = [];
        $consignment = [];
        $paid = DB::table('supplier_payment_allocations')
            ->select('obligation_id')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('obligation_id');
        $credited = DB::table('supplier_credit_allocations')
            ->select('obligation_id')
            ->selectRaw('SUM(amount) AS total')
            ->groupBy('obligation_id');
        $waiting = DB::table('supplier_obligations as o')
            ->leftJoinSub($paid, 'p', 'p.obligation_id', '=', 'o.id')
            ->leftJoinSub($credited, 'c', 'c.obligation_id', '=', 'o.id')
            ->whereNotExists(
                fn ($q) => $q
                    ->selectRaw('1')
                    ->from('payable_schedule as s')
                    ->whereColumn('s.obligation_id', 'o.id')
                    ->whereColumn('s.version', 'o.schedule_version'),
            )
            ->selectRaw('o.amount - COALESCE(p.total, 0) - COALESCE(c.total, 0) AS outstanding');
        if ($supplierId) {
            $waiting->where('o.supplier_id', $supplierId);
        }
        foreach ($waiting->cursor() as $row) {
            $undated[] = (int) $row->outstanding;
        }
        foreach (
            [
                'supplier_payments' => ['supplier_payment_allocations', 'payment_id'],
                'supplier_credits' => ['supplier_credit_allocations', 'credit_id'],
            ] as $table => [$allocations, $foreignKey]
        ) {
            $allocated = DB::table($allocations)
                ->select($foreignKey)
                ->selectRaw('SUM(amount) AS total')
                ->groupBy($foreignKey);
            $q = DB::table($table.' as r')
                ->leftJoinSub($allocated, 'a', 'a.'.$foreignKey, '=', 'r.id')
                ->selectRaw('r.amount - COALESCE(a.total, 0) AS available');
            if ($table === 'supplier_payments') {
                $q->where('r.status', 'verified');
            }
            if ($supplierId) {
                $q->where('r.supplier_id', $supplierId);
            }
            foreach ($q->cursor() as $row) {
                if ($table === 'supplier_payments') {
                    $advances[] = (int) $row->available;
                } else {
                    $credits[] = (int) $row->available;
                }
            }
        }
        $sales = DB::table('consignment_sales as s')
            ->join('stock_lots as l', 'l.id', '=', 's.lot_id')
            ->whereNull('s.statement_id')
            ->select('s.quantity', 's.unit_cost');
        if ($supplierId) {
            $sales->where('l.supplier_id', $supplierId);
        }
        foreach ($sales->cursor() as $sale) {
            $consignment[] = Money::multiply((int) $sale->unit_cost, (int) $sale->quantity);
        }

        return [
            'currency' => 'XAF',
            'calculated_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'timezone' => 'Africa/Douala',
            'from' => $from,
            'to' => $to,
            'supplier_id' => $supplierId,
            'totals' => [
                'before_window' => $this->sum($before),
                'within_window' => $this->sum($within),
                'after_window' => $this->sum($after),
                'undated' => $this->sum($undated),
                'recognized_outstanding' => $this->sum([
                    $this->sum($before),
                    $this->sum($within),
                    $this->sum($after),
                    $this->sum($undated),
                ]),
                'past_due' => $this->sum($past),
                'unrecognized_commitment' => app(PurchaseCommitment::class)->unrecognizedTotal($supplierId),
                'unallocated_advances' => $this->sum($advances),
                'unapplied_credits' => $this->sum($credits),
                'consignment_sales_to_reconcile' => $this->sum($consignment),
            ],
            'days' => array_map(fn ($d) => [...$d, 'amount' => (string) $d['amount']], array_values($days)),
            'treasury' => [
                'available_funds' => null,
                'provider_held_funds' => null,
                'refunds_to_fund' => null,
                'free_cash' => null,
            ],
            'coverage' => 'supplier_payables_only',
        ];
    }
}
