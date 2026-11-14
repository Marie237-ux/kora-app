<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\PayableForecast;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Current operational state, read in one snapshot. Collected money never implies external availability. */
final class TreasuryForecast
{
    private function add(string ...$amounts): string
    {
        $total = BigInteger::zero();
        foreach ($amounts as $amount) {
            $total = $total->plus($amount);
        }

        return (string) $total;
    }

    private function bucket(string $date, string $from, string $to): string
    {
        return $date < $from ? 'before_window' : ($date > $to ? 'after_window' : 'within_window');
    }

    private function expenses(object $book, string $from, string $to, string $today): array
    {
        $totals = array_fill_keys(
            [
                'before_window',
                'within_window',
                'after_window',
                'committed',
                'pending_budget',
                'not_yet_effective',
                'net_spent',
                'cancelled_unspent',
                'past_due',
            ],
            '0',
        );
        $days = [];
        foreach (
            DB::table('expense_plans')->where('book_id', $book->id)->orderBy('due_on')->orderBy('id')->cursor() as $plan
        ) {
            $balance = app(ExpensePlans::class)->balance($plan, $today);
            foreach (['committed', 'net_spent', 'cancelled_unspent'] as $key) {
                $totals[$key] = $this->add($totals[$key], $balance[$key]);
            }
            $cancelled = $plan->cancelled_on && $plan->cancelled_on <= $today;
            if (! $cancelled && ! $plan->approved_on) {
                $totals['pending_budget'] = $this->add($totals['pending_budget'], $balance['remaining']);
            } elseif (! $cancelled && $plan->approved_on && $plan->approved_on > $today) {
                // Future approval is visible as planning, not silently treated as today's commitment.
                $totals['not_yet_effective'] = $this->add($totals['not_yet_effective'], $balance['remaining']);
            }
            $amount = $balance['committed'];
            $bucket = $this->bucket($plan->due_on, $from, $to);
            $totals[$bucket] = $this->add($totals[$bucket], $amount);
            if ($plan->due_on < $today) {
                $totals['past_due'] = $this->add($totals['past_due'], $amount);
            }
            if ($bucket === 'within_window' && $amount !== '0') {
                $days[$plan->due_on] = $this->add($days[$plan->due_on] ?? '0', $amount);
            }
        }

        return [
            ...$totals,
            'effective_on' => $today,
            'days' => array_map(
                fn ($date, $amount) => ['due_on' => $date, 'amount' => $amount],
                array_keys($days),
                array_values($days),
            ),
        ];
    }

    private function refunds(): array
    {
        $result = ['to_fund' => '0', 'paid' => '0', 'requested_count' => 0, 'paid_count' => 0, 'non_xaf_count' => 0];
        foreach (
            DB::table('customer_refunds as r')
                ->join('orders as o', 'o.id', '=', 'r.order_id')
                ->whereIn('r.status', ['requested', 'paid'])
                ->select('r.amount', 'r.status', 'o.currency')
                ->cursor() as $refund
        ) {
            if ($refund->currency !== 'XAF') {
                $result['non_xaf_count']++;

                continue;
            }
            $key = $refund->status === 'requested' ? 'to_fund' : 'paid';
            $result[$key] = $this->add($result[$key], (string) $refund->amount);
            $result[$refund->status === 'requested' ? 'requested_count' : 'paid_count']++;
        }

        return $result;
    }

    private function collections(): array
    {
        $result = [
            'confirmed_collected' => '0',
            'confirmed_count' => 0,
            'unresolved_attempt_count' => 0,
            'test_attempt_count' => 0,
            'duplicate_transaction_count' => 0,
        ];
        $transactions = [];
        foreach (
            DB::table('payment_attempts')
                ->whereIn('status', ['confirmed', 'to_verify', 'reversed'])
                ->orderBy('id')
                ->cursor() as $attempt
        ) {
            if ($attempt->testmode !== null && (bool) $attempt->testmode) {
                $result['test_attempt_count']++;
            } elseif (
                $attempt->testmode !== null &&
                $attempt->status === 'confirmed' &&
                $attempt->currency === 'XAF' &&
                $attempt->received_amount !== null &&
                $attempt->confirmed_at &&
                $attempt->provider_transaction_id
            ) {
                $key = json_encode([$attempt->provider, $attempt->provider_transaction_id], JSON_THROW_ON_ERROR);
                $transactions[$key][] = (string) $attempt->received_amount;
            } else {
                // Disputed/unknown-mode amounts may be in a different currency; never add them as XAF.
                $result['unresolved_attempt_count']++;
            }
        }
        foreach ($transactions as $amounts) {
            if (count($amounts) !== 1) {
                $result['duplicate_transaction_count']++;
                $result['unresolved_attempt_count'] += count($amounts);
            } else {
                $result['confirmed_collected'] = $this->add($result['confirmed_collected'], $amounts[0]);
                $result['confirmed_count']++;
            }
        }

        return $result;
    }

    private function positions(object $book, string $today): array
    {
        $accounts = DB::table('treasury_accounts')->orderBy('code')->orderBy('id');
        if ($book->mode !== 'live') {
            $accounts->whereIn(
                'id',
                DB::table('treasury_bindings')->where('book_id', $book->id)->select('treasury_account_id'),
            );
        }
        $positions = [];
        foreach ($accounts->get() as $account) {
            $statement = DB::table('treasury_statements')
                ->where('book_id', $book->id)
                ->where('treasury_account_id', $account->id)
                ->where('status', 'closed')
                ->where('ends_on', '<=', $today)
                ->orderByDesc('ends_on')
                ->orderBy('id')
                ->first();
            $positions[] = [
                'treasury_account_id' => $account->id,
                'code' => $account->code,
                'name' => $account->name,
                'kind' => $account->kind,
                'active' => (bool) $account->active,
                'latest_closed_statement' => $statement
                    ? [
                        'id' => $statement->id,
                        'as_of' => $statement->ends_on,
                        'closing_balance' => $statement->closing_balance,
                        'sha256' => $statement->sha256,
                    ]
                    : null,
                'coverage' => app(TreasuryCoverage::class)->report($book, $account->id, $today),
                'available_funds' => null,
                'availability_verified' => false,
            ];
        }

        return $positions;
    }

    /** The caller owns the ReadSnapshot, including loading the book. No writes, provider calls or date simulation. */
    public function calculate(object $book, string $from, string $to): array
    {
        $now = CarbonImmutable::now('UTC');
        $today = $now->setTimezone('Africa/Douala')->toDateString();
        $live = $book->mode === 'live';
        $supplier = $live ? app(PayableForecast::class)->calculate($from, $to, null, $today) : null;
        $supplierTotals = $supplier['totals'] ?? [];
        $expense = $this->expenses($book, $from, $to, $today);
        $refunds = $live ? $this->refunds() : null;
        $collections = $live ? $this->collections() : null;
        $withdrawals = app(Withdrawals::class)->summary($book);
        $financing = app(Financings::class)->forecast($book, $from, $to, $today);
        $totals = [];
        foreach (['before_window', 'within_window', 'after_window', 'past_due'] as $key) {
            $totals[$key] = $this->add($supplierTotals[$key] ?? '0', $expense[$key], $financing['forecast'][$key]);
        }
        $totals['undated'] = $this->add(
            $supplierTotals['undated'] ?? '0',
            $supplierTotals['unrecognized_commitment'] ?? '0',
            $supplierTotals['consignment_sales_to_reconcile'] ?? '0',
            $refunds['to_fund'] ?? '0',
            $financing['forecast']['undated'],
        );
        $totals['known_outflows'] = $this->add(
            $totals['before_window'],
            $totals['within_window'],
            $totals['after_window'],
            $totals['undated'],
        );
        $days = [];
        foreach (
            [
                'supplier_payables' => $supplier['days'] ?? [],
                'expenses' => $expense['days'],
                'financing' => $financing['forecast']['days'],
            ] as $kind => $sourceDays
        ) {
            foreach ($sourceDays as $day) {
                $date = $day['due_on'];
                $days[$date] ??= [
                    'due_on' => $date,
                    'supplier_payables' => '0',
                    'expenses' => '0',
                    'financing' => '0',
                    'total' => '0',
                ];
                $days[$date][$kind] = $day['amount'];
                $days[$date]['total'] = $this->add($days[$date]['total'], $day['amount']);
            }
        }
        ksort($days);

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'timezone' => 'Africa/Douala',
            'from' => $from,
            'to' => $to,
            'calculated_at' => $now->toIso8601String(),
            'state_basis' => 'current_operational_snapshot',
            'scope' => $live ? 'known_operational_commitments' : 'book_commitments_only',
            'suppliers' => $supplier ? ['totals' => $supplier['totals'], 'days' => $supplier['days']] : null,
            'expenses' => $expense,
            'refunds' => $refunds,
            'collections' => $collections,
            'withdrawals' => $withdrawals,
            'balance_observations' => app(BalanceObservations::class)->summary($book),
            'financing' => $financing,
            'totals' => $totals,
            'days' => array_values($days),
            'cash_positions' => $this->positions($book, $today),
            'cash' => [
                ...array_fill_keys(
                    [
                        'available_funds',
                        'provider_held_funds',
                        'withdrawable_funds',
                        'pending_withdrawals',
                        'free_cash',
                    ],
                    null,
                ),
                'pending_withdrawals' => $withdrawals['totals']['pending_gross'],
            ],
            'coverage' => [
                'business_sources_included' => $live,
                'complete' => false,
                'missing' => ['verified_external_balances', 'unregistered_external_withdrawals', 'other_commitments'],
            ],
            'availability_verified' => false,
            'provisional' => true,
        ];
    }
}
