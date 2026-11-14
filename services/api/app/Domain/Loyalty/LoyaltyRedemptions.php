<?php

namespace App\Domain\Loyalty;

use App\Domain\Orders\Orders;
use App\Domain\Procurement\Records;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Order -> account -> policy. Expiring holds never lock an order while holding its account. */
final class LoyaltyRedemptions
{
    private function movement(
        object $r,
        string $grant,
        string $kind,
        int $points,
        int $reserved,
        ?string $refund = null,
    ): void {
        $text = match ($kind) {
            'reserved' => [
                'fr' => 'Points réservés pour la commande en attente de paiement.',
                'en' => 'Points reserved for the order awaiting payment.',
            ],
            'used' => [
                'fr' => 'Points utilisés après vérification du paiement de la commande.',
                'en' => 'Points used after verified order payment.',
            ],
            'released' => [
                'fr' => 'Points libérés après expiration de la commande impayée.',
                'en' => 'Points released after the unpaid order expired.',
            ],
            'restored' => [
                'fr' => 'Points dépensés restitués pour les articles remboursés.',
                'en' => 'Spent points restored for refunded items.',
            ],
            default => [
                'fr' => 'Validité initiale écoulée : points libérés puis expirés.',
                'en' => 'Original validity elapsed: released points expired.',
            ],
        };
        app(LoyaltyLedger::class)->entry(
            $r->account_id,
            $grant,
            $kind,
            $points,
            0,
            $text,
            null,
            $refund,
            $reserved,
            $r->id,
        );
    }

    private function take(object $r, string $status): void
    {
        $left = (int) $r->points;
        $ledger = app(LoyaltyLedger::class);
        $grants = DB::table('loyalty_grants')
            ->where('account_id', $r->account_id)
            ->where('status', 'acquired')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('expires_at')
            ->orderBy('id')
            ->get();
        foreach ($grants as $grant) {
            $take = min($left, max(0, $ledger->grantBalance($grant->id)));
            if ($take > 0) {
                DB::table('loyalty_redemption_allocations')->insert([
                    'redemption_id' => $r->id,
                    'grant_id' => $grant->id,
                    'points' => $take,
                    'status' => $status,
                    'created_at' => now(),
                ]);
                $this->movement(
                    $r,
                    $grant->id,
                    $status === 'held' ? 'reserved' : 'used',
                    -$take,
                    $status === 'held' ? $take : 0,
                );
                $left -= $take;
            }
            if ($left === 0) {
                return;
            }
        }
        // Should only fail before payment: the account lock and balance check make paid reallocation exact.
        LoyaltyProgram::fail('points', 'POINTS_UNAVAILABLE');
    }

    public function reserve(object $order, object $quote): void
    {
        $account = Records::row('loyalty_accounts', $quote->account_id);
        $this->releaseExpired($account->id);
        app(LoyaltyLedger::class)->reconcile($account->id);
        $policy = Records::row('loyalty_redemption_policy', '1');
        if (
            $account->status !== 'active' ||
            ! $policy->enabled ||
            (int) $policy->version !== (int) $quote->policy_version ||
            ! app(LoyaltyProgram::class)->current()['enabled']
        ) {
            LoyaltyProgram::fail('quote', 'REDEMPTION_QUOTE_INVALID');
        }
        if (app(LoyaltyAccounts::class)->balance($account->id) < $quote->points) {
            LoyaltyProgram::fail('points', 'POINTS_UNAVAILABLE');
        }
        app(LoyaltyRedemptionPolicy::class)->assertBudget($policy, (int) $quote->discount);
        $id = Records::id();
        DB::table('loyalty_redemptions')->insert([
            'id' => $id,
            'order_id' => $order->id,
            'quote_id' => $quote->id,
            'account_id' => $account->id,
            'points' => $quote->points,
            'discount' => $quote->discount,
            'rules_snapshot' => Records::json(json_decode($quote->snapshot, true)['rules']),
            'expires_at' => $order->payment_expires_at,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->take(Records::row('loyalty_redemptions', $id, false), 'held');
        Records::audit(null, 'loyalty.points_reserved', $order->id, ['points' => (string) $quote->points]);
    }

    private function release(object $r): void
    {
        if ($r->status !== 'held') {
            return;
        }
        $ledger = app(LoyaltyLedger::class);
        foreach (
            DB::table('loyalty_redemption_allocations')->where('redemption_id', $r->id)->where('status', 'held')->get() as $a
        ) {
            $this->movement($r, $a->grant_id, 'released', (int) $a->points, -(int) $a->points);
            $grant = Records::row('loyalty_grants', $a->grant_id, false);
            if ($grant->expires_at && ! CarbonImmutable::parse($grant->expires_at, 'UTC')->isFuture()) {
                $unused = max(0, $ledger->grantBalance($grant->id));
                if ($unused > 0) {
                    $this->movement($r, $a->grant_id, 'expired', -$unused, 0);
                }
            }
            DB::table('loyalty_redemption_allocations')
                ->where('id', $a->id)
                ->update(['status' => 'released']);
        }
        DB::table('loyalty_redemptions')
            ->where('id', $r->id)
            ->update(['status' => 'released', 'updated_at' => now()]);
        $ledger->settleDebts($r->account_id);
        Records::audit(null, 'loyalty.points_released', $r->order_id);
    }

    public function releaseExpired(string $accountId): void
    {
        foreach (
            DB::table('loyalty_redemptions')
                ->where('account_id', $accountId)
                ->where('status', 'held')
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->get() as $r
        ) {
            $this->release($r);
        }
    }

    public function releaseOrder(object $order): void
    {
        $r = DB::table('loyalty_redemptions')->where('order_id', $order->id)->first();
        if ($r) {
            Records::row('loyalty_accounts', $r->account_id);
            $this->release($r);
        }
    }

    public function expire(int $limit): int
    {
        $ids = DB::table('loyalty_redemptions')
            ->where('status', 'held')
            ->where('expires_at', '<=', now())
            ->select('account_id')
            ->distinct()
            ->orderBy('account_id')
            ->limit($limit)
            ->pluck('account_id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id) {
                Records::row('loyalty_accounts', $id);
                $this->releaseExpired($id);
                app(LoyaltyLedger::class)->reconcile($id);
            }, 3);
        }

        return $ids->count();
    }

    /** Called once for a verified sale, after inventory locks. Never adds a surcharge to the paid amount. */
    public function consume(object $order): void
    {
        $initial = DB::table('loyalty_redemptions')->where('order_id', $order->id)->first();
        if (
            ! $initial ||
            in_array($initial->status, ['consumed', 'review_required', 'covered', 'review_resolved'], true)
        ) {
            return;
        }
        $account = Records::row('loyalty_accounts', $initial->account_id);
        $this->releaseExpired($account->id);
        app(LoyaltyLedger::class)->reconcile($account->id);
        $r = Records::row('loyalty_redemptions', $initial->id, false);
        $consumed = true;
        if ($r->status === 'held') {
            foreach (
                DB::table('loyalty_redemption_allocations')
                    ->where('redemption_id', $r->id)
                    ->where('status', 'held')
                    ->get() as $a
            ) {
                $this->movement($r, $a->grant_id, 'used', 0, -(int) $a->points);
                DB::table('loyalty_redemption_allocations')
                    ->where('id', $a->id)
                    ->update(['status' => 'used']);
            }
        } elseif ($account->status === 'active' && app(LoyaltyAccounts::class)->balance($account->id) >= $r->points) {
            $this->take($r, 'used');
        } else {
            $consumed = false;
            $this->incident($r, 'loyalty_points_unavailable_after_payment');
        }
        $policy = Records::row('loyalty_redemption_policy', '1');
        $used = BigInteger::of($policy->used_budget)->plus($r->discount);
        DB::table('loyalty_redemption_policy')
            ->where('id', 1)
            ->update(['used_budget' => (string) $used, 'updated_at' => now()]);
        DB::table('loyalty_redemptions')
            ->where('id', $r->id)
            ->update([
                'status' => $consumed ? 'consumed' : 'review_required',
                'consumed_points' => $consumed ? $r->points : 0,
                'updated_at' => now(),
            ]);
        $rules = json_decode($policy->rules, true);
        if ($used->plus(app(LoyaltyRedemptionPolicy::class)->reserved())->compareTo($rules['budget_xaf']) > 0) {
            $this->incident($r, 'loyalty_budget_exceeded_after_payment');
        }
        Records::audit(null, 'loyalty.points_consumed', $order->id, [
            'consumed_points' => $consumed ? (string) $r->points : '0',
        ]);
    }

    private function incident(object $r, string $kind): void
    {
        DB::table('payment_incidents')->insertOrIgnore([
            'id' => Records::id(),
            'order_id' => $r->order_id,
            'kind' => $kind,
            'details' => Records::json(['points' => (string) $r->points, 'discount' => (string) $r->discount]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(Orders::class)->event($r->order_id, $kind, null);
    }

    public function reviewResolved(object $incident, string $outcome): void
    {
        if ($incident->kind === 'loyalty_points_unavailable_after_payment') {
            // The accepted cash price stays unchanged; a review never invents a point debit.
            DB::table('loyalty_redemptions')
                ->where('order_id', $incident->order_id)
                ->where('status', 'review_required')
                ->update(['status' => $outcome === 'dismissed' ? 'covered' : 'review_resolved', 'updated_at' => now()]);
        }
    }

    public function restore(object $order, object $refund, array $claims): int
    {
        $r = DB::table('loyalty_redemptions')->where('order_id', $order->id)->first();
        if (! $r || $r->status !== 'consumed') {
            return 0;
        }
        $rules = json_decode($r->rules_snapshot, true);
        if (! $rules['restore_points_on_refund']) {
            return 0;
        }
        $points = array_sum(array_column($claims, 'points'));
        if ($points < 1) {
            return 0;
        }
        abort_if($points > $r->consumed_points - $r->restored_points, 409);
        $snapshot = json_decode($order->loyalty_snapshot, true);
        $id = Records::id();
        DB::table('loyalty_grants')->insert([
            'id' => $id,
            'account_id' => $r->account_id,
            'redemption_id' => $r->id,
            'status' => 'acquired',
            'points' => $points,
            'program_version' => $snapshot['version'],
            'rules_snapshot' => Records::json($snapshot['rules']),
            'available_at' => now(),
            'expires_at' => $rules['restore_valid_days'] === null ? null : now()->addDays($rules['restore_valid_days']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->movement($r, $id, 'restored', $points, 0, $refund->id);
        DB::table('loyalty_redemptions')->where('id', $r->id)->increment('restored_points', $points);
        foreach ($claims as $claim) {
            DB::table('loyalty_refund_lines')
                ->where('id', $claim['id'])
                ->update(['restored_points' => $claim['points']]);
        }
        app(LoyaltyLedger::class)->absorbDebt($r->account_id, $id);

        return $points;
    }

    public function view(string $orderId): ?array
    {
        $r = DB::table('loyalty_redemptions')->where('order_id', $orderId)->first();
        if (! $r) {
            return null;
        }
        $rules = json_decode($r->rules_snapshot, true);
        unset($rules['budget_xaf'], $rules['loss_reason']);

        return [
            'status' => $r->status,
            'points' => (string) $r->points,
            'discount' => (string) $r->discount,
            'consumed_points' => (string) $r->consumed_points,
            'restored_points' => (string) $r->restored_points,
            'rules' => $rules,
        ];
    }
}
