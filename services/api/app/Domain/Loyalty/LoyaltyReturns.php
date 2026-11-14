<?php

namespace App\Domain\Loyalty;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Return attribution is distinct from sending money. A negative points balance never rejects a cash refund. */
final class LoyaltyReturns
{
    public function allocate(object $order, object $refund, array $lines, array $explanation, ?User $actor): array
    {
        if (! $order->loyalty_snapshot) {
            LoyaltyProgram::fail('order', 'NO_PROGRAM_SNAPSHOT');
        }
        if ($refund->status !== 'paid' || $refund->order_id !== $order->id) {
            LoyaltyProgram::fail('refund', 'PAID_REFUND_REQUIRED');
        }
        $total = 0;
        $eligible = 0;
        $snapshot = json_decode($order->loyalty_snapshot, true);
        $account = Records::row('loyalty_accounts', $snapshot['account_id']);
        app(LoyaltyRedemptions::class)->releaseExpired($account->id);
        $ledger = app(LoyaltyLedger::class);
        // Resolve elapsed validity before a return, even when the scheduler has not run yet.
        $ledger->reconcile($account->id);
        $attribution = app(LoyaltyRefundAttribution::class)->allocate($order, $refund, $lines, $explanation, $actor);
        $total = $attribution['total'];
        foreach ($attribution['claims'] as $claim) {
            $line = $claim['line'];
            $terms = json_decode($line->snapshot, true);
            if (
                isset($terms['category_id']) &&
                ! in_array($terms['category_id'], $snapshot['rules']['excluded_category_ids'], true)
            ) {
                $eligible = Money::sum([$eligible, $claim['amount']]);
            }
        }
        $grant = DB::table('loyalty_grants')->where('order_id', $order->id)->first();
        if ($grant) {
            $returned = (int) $grant->returned_amount + $eligible;
            $reverse = LoyaltyLedger::ratio(
                (int) $grant->points,
                max(0, $returned - (int) $grant->pre_award_returned_amount),
                (int) $grant->award_base_amount,
            );
            $delta = $reverse - (int) $grant->reversed_points;
            if ($grant->status === 'pending') {
                $ledger->entry($account->id, $grant->id, 'cancelled', 0, -$delta, $explanation, $actor, $refund->id);
            } elseif ($grant->status !== 'funding_required') {
                // Already expired credits are not clawed back a second time.
                $expired = -(int) DB::table('loyalty_movements')
                    ->where('grant_id', $grant->id)
                    ->where('kind', 'expired')
                    ->sum('points_delta');
                $alreadyRemoved =
                    -(int) DB::table('loyalty_movements')
                        ->where('grant_id', $grant->id)
                        ->where('kind', 'returned')
                        ->sum('points_delta') -
                    (int) DB::table('loyalty_movements')
                        ->where('grant_id', $grant->id)
                        ->where('kind', 'cancelled')
                        ->sum('pending_delta');
                $take = max(0, $reverse - $expired - $alreadyRemoved);
                $ledger->entry($account->id, $grant->id, 'returned', -$take, 0, $explanation, $actor, $refund->id);
            }
            DB::table('loyalty_grants')
                ->where('id', $grant->id)
                ->update([
                    'returned_amount' => $returned,
                    'reversed_points' => $reverse,
                    'updated_at' => now(),
                ]);
            $ledger->settleDebts($account->id);
        }
        $restored = app(LoyaltyRedemptions::class)->restore($order, $refund, $attribution['claims']);
        Records::audit($actor, 'loyalty.return_allocated', $refund->id, ['eligible_amount' => (string) $eligible]);

        return [
            'refund_id' => $refund->id,
            'items_amount' => (string) $total,
            'eligible_amount' => (string) $eligible,
            'restored_points' => (string) $restored,
        ];
    }

    /** Existing supplier-rupture refunds already have exact affected quantities; no monetary guessing. */
    public function refundPaid(object $refund): void
    {
        $order = Records::row('orders', $refund->order_id, false);
        if (! $order->loyalty_snapshot) {
            return;
        }
        $case = DB::table('order_supply_cases')->where('refund_id', $refund->id)->first();
        if (
            ! $case ||
            DB::table('loyalty_refund_lines')->where('refund_id', $refund->id)->where('status', 'paid')->exists()
        ) {
            return;
        }
        $this->allocate(
            $order,
            $refund,
            [['order_line_id' => $case->order_line_id, 'quantity' => (int) $case->missing_quantity]],
            [
                'fr' => 'Reprise du gain des unités indisponibles dont le remboursement a été vérifié.',
                'en' => 'Reward reversed for unavailable units with a verified refund.',
            ],
            null,
        );
    }
}
