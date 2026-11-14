<?php

namespace App\Domain\Loyalty;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** The caller locks the account. Balances come exclusively from append-only movements. */
final class LoyaltyLedger
{
    public function entry(
        string $account,
        ?string $grant,
        string $kind,
        int $points,
        int $pending,
        array $text,
        ?User $actor = null,
        ?string $refund = null,
        int $reservedDelta = 0,
        ?string $redemptionId = null,
    ): void {
        DB::table('loyalty_movements')->insert([
            'account_id' => $account,
            'grant_id' => $grant,
            'kind' => $kind,
            'points_delta' => $points,
            'pending_delta' => $pending,
            'reserved_delta' => $reservedDelta,
            'redemption_id' => $redemptionId,
            'explanation' => Records::json($text),
            'actor_id' => $actor?->id,
            'refund_id' => $refund,
            'created_at' => now(),
        ]);
    }

    public function grantBalance(string $id): int
    {
        return (int) DB::table('loyalty_movements')->where('grant_id', $id)->sum('points_delta');
    }

    public static function ratio(int $amount, int $part, int $whole): int
    {
        return $whole === 0 ? 0 : BigInteger::of($amount)->multipliedBy($part)->quotient($whole)->toInt();
    }

    public function reconcile(string $accountId): void
    {
        foreach (
            DB::table('loyalty_grants')
                ->where('account_id', $accountId)
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->orderBy('available_at')
                ->orderBy('id')
                ->get() as $grant
        ) {
            $points = (int) $grant->points - (int) $grant->reversed_points;
            $this->entry($accountId, $grant->id, 'acquired', $points, -$points, [
                'fr' => 'Points de la commande désormais acquis après le délai prévu.',
                'en' => 'Order points acquired after the configured validation period.',
            ]);
            DB::table('loyalty_grants')
                ->where('id', $grant->id)
                ->update(['status' => 'acquired', 'updated_at' => now()]);
            $this->absorbDebt($accountId, $grant->id);
        }
        foreach (
            DB::table('loyalty_grants')
                ->where('account_id', $accountId)
                ->where('status', 'acquired')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->orderBy('expires_at')
                ->orderBy('id')
                ->get() as $grant
        ) {
            $unused = max(0, $this->grantBalance($grant->id));
            $this->entry($accountId, $grant->id, 'expired', -$unused, 0, [
                'fr' => 'Expiration des points encore disponibles selon les conditions figées.',
                'en' => 'Remaining points expired under the frozen programme terms.',
            ]);
            DB::table('loyalty_grants')
                ->where('id', $grant->id)
                ->update(['status' => 'expired', 'updated_at' => now()]);
        }
    }

    /** A previous return/debit can be negative. New credits settle it without reviving it at expiry. */
    public function absorbDebt(string $accountId, string $creditGrant): void
    {
        $available = max(0, $this->grantBalance($creditGrant));
        $debts = DB::table('loyalty_movements')
            ->where('account_id', $accountId)
            ->groupBy('grant_id')
            ->selectRaw('grant_id, SUM(points_delta) AS balance')
            ->havingRaw('SUM(points_delta) < 0')
            ->get();
        foreach ($debts as $debt) {
            $amount = min($available, -(int) $debt->balance);
            if ($amount < 1) {
                break;
            }
            $text = [
                'fr' => 'Les nouveaux points compensent un ajustement antérieur ; aucun paiement client exigé.',
                'en' => 'New points offset a prior adjustment; no customer payment is required.',
            ];
            $this->entry($accountId, $debt->grant_id, 'debt_offset', $amount, 0, $text);
            $this->entry($accountId, $creditGrant, 'debt_offset', -$amount, 0, $text);
            $available -= $amount;
        }
    }

    public function settleDebts(string $accountId): void
    {
        foreach (
            DB::table('loyalty_grants')
                ->where('account_id', $accountId)
                ->where('status', 'acquired')
                ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('expires_at')
                ->orderBy('id')
                ->pluck('id') as $id
        ) {
            if ($this->grantBalance($id) > 0) {
                $this->absorbDebt($accountId, $id);
            }
        }
    }

    public function adjust(object $account, array $data, User $actor): array
    {
        app(LoyaltyRedemptions::class)->releaseExpired($account->id);
        $this->reconcile($account->id);
        $points = (int) $data['points'];
        $balance = app(LoyaltyAccounts::class)->balance($account->id);
        if ($balance + $points < -1_000_000_000_000) {
            LoyaltyProgram::fail('points', 'ADJUSTMENT_LIMIT');
        }
        if ($points > 0) {
            $program = Records::row('loyalty_program', '1');
            $rules = $program->rules ? json_decode($program->rules, true) : null;
            if (! $rules || $points > $rules['budget_points'] - (int) $program->committed_points) {
                LoyaltyProgram::fail('points', 'BUDGET_REQUIRED');
            }
            $id = Records::id();
            DB::table('loyalty_grants')->insert([
                'id' => $id,
                'account_id' => $account->id,
                'status' => 'acquired',
                'points' => $points,
                'program_version' => $program->version,
                'rules_snapshot' => Records::json($rules),
                'available_at' => now(),
                'expires_at' => $rules['points_valid_days'] === null ? null : now()->addDays($rules['points_valid_days']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('loyalty_program')->where('id', 1)->increment('committed_points', $points);
            $this->entry($account->id, $id, 'adjustment', $points, 0, $data['explanation'], $actor);
            $this->absorbDebt($account->id, $id);
        } else {
            $remaining = -$points;
            foreach (
                DB::table('loyalty_grants')
                    ->where('account_id', $account->id)
                    ->where('status', 'acquired')
                    ->orderByRaw('CASE WHEN expires_at IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('expires_at')
                    ->orderBy('id')
                    ->get() as $grant
            ) {
                $take = min($remaining, max(0, $this->grantBalance($grant->id)));
                if ($take > 0) {
                    $this->entry($account->id, $grant->id, 'adjustment', -$take, 0, $data['explanation'], $actor);
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }
            if ($remaining > 0) {
                $this->entry($account->id, null, 'adjustment', -$remaining, 0, $data['explanation'], $actor);
            }
        }
        Records::audit($actor, 'loyalty.adjusted', $account->id, [
            'points' => $points,
            'explanation' => $data['explanation'],
        ]);

        return app(LoyaltyAccounts::class)->dto(Records::row('loyalty_accounts', $account->id, false), false);
    }

    /** Called only with the order lock. Payment and a trusted fulfilment timestamp are both required. */
    public function delivered(object $order): ?array
    {
        if (
            ! $order->paid_at ||
            ! $order->delivered_at ||
            $order->status !== 'paid' ||
            ! $order->loyalty_snapshot ||
            CarbonImmutable::parse($order->delivered_at, 'UTC')->isFuture()
        ) {
            return null;
        }
        $snapshot = json_decode($order->loyalty_snapshot, true);
        // Cancelled, undelivered units awaiting their cash refund must not earn an initial reward.
        if (
            DB::table('order_supply_cases')
                ->where('order_id', $order->id)
                ->where('status', 'refund_requested')
                ->exists()
        ) {
            return null;
        }
        if (
            DB::table('payment_incidents')
                ->where('order_id', $order->id)
                ->where('status', 'open')
                ->whereIn('kind', ['loyalty_points_unavailable_after_payment', 'loyalty_budget_exceeded_after_payment'])
                ->exists()
        ) {
            return null;
        }
        $account = Records::row('loyalty_accounts', $snapshot['account_id']);
        app(LoyaltyRedemptions::class)->releaseExpired($account->id);
        $existing = DB::table('loyalty_grants')->where('order_id', $order->id)->first();
        if ($existing && $existing->status !== 'funding_required') {
            return $this->grantDto($existing);
        }
        $rules = $snapshot['rules'];
        $eligible = 0;
        $returned = 0;
        foreach (DB::table('order_lines')->where('order_id', $order->id)->get() as $line) {
            $terms = json_decode($line->snapshot, true);
            if (
                ! isset($terms['category_id']) ||
                in_array($terms['category_id'], $rules['excluded_category_ids'], true)
            ) {
                continue;
            }
            $eligible = Money::sum([$eligible, (int) $line->line_total]);
            $returned = Money::sum([
                $returned,
                (int) DB::table('loyalty_refund_lines')
                    ->where('order_line_id', $line->id)
                    ->where('status', 'paid')
                    ->sum('amount'),
            ]);
        }
        // Pre-award returns change the paid base before full-step rounding and per-order caps.
        $awardBase = $eligible - $returned;
        $points = app(LoyaltyProgram::class)->points($awardBase, $rules);
        $reverse = 0;
        $program = Records::row('loyalty_program', '1');
        $currentRules = json_decode($program->rules, true);
        $funded = $points <= $currentRules['budget_points'] - (int) $program->committed_points;
        $availableAt = CarbonImmutable::parse($order->delivered_at, 'UTC')->addDays($rules['validation_delay_days']);
        $id = $existing?->id ?? Records::id();
        DB::table('loyalty_grants')->updateOrInsert(
            ['id' => $id],
            [
                'account_id' => $account->id,
                'order_id' => $order->id,
                'status' => $funded ? 'pending' : 'funding_required',
                'program_version' => $snapshot['version'],
                'points' => $points,
                'reversed_points' => $reverse,
                'eligible_amount' => $eligible,
                'returned_amount' => $returned,
                'award_base_amount' => $awardBase,
                'pre_award_returned_amount' => $returned,
                'rules_snapshot' => Records::json($rules),
                'delivered_at' => $order->delivered_at,
                'available_at' => $availableAt,
                'expires_at' => $rules['points_valid_days'] === null ? null : $availableAt->addDays($rules['points_valid_days']),
                'created_at' => $existing?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        if ($funded) {
            DB::table('loyalty_program')->where('id', 1)->increment('committed_points', $points);
            $this->entry($account->id, $id, 'pending', 0, $points - $reverse, [
                'fr' => 'Gain sur les articles admissibles payés et livrés, hors livraison et retours attribués.',
                'en' => 'Reward for eligible paid and delivered items, excluding delivery and allocated returns.',
            ]);
            $this->reconcile($account->id);
        }
        if ($funded || ! $existing) {
            Records::audit(null, $funded ? 'loyalty.delivery_rewarded' : 'loyalty.funding_required', $order->id);
        }

        return $this->grantDto(Records::row('loyalty_grants', $id, false));
    }

    public function grantDto(object $g): array
    {
        $rules = json_decode($g->rules_snapshot, true);
        unset($rules['budget_points']);
        $restoration = null;
        if ($g->redemption_id) {
            $r = Records::row('loyalty_redemptions', $g->redemption_id, false);
            $redemptionRules = json_decode($r->rules_snapshot, true);
            $restoration = [
                'order_id' => $r->order_id,
                'conditions' => $redemptionRules['conditions'],
                'points_valid_days' => $redemptionRules['restore_valid_days'],
            ];
        }

        return [
            'id' => $g->id,
            'order_id' => $g->order_id,
            'redemption_id' => $g->redemption_id,
            'source' => $g->redemption_id ? 'restored' : ($g->order_id ? 'order' : 'adjustment'),
            'restoration' => $restoration,
            'status' => $g->status,
            'points' => (string) $g->points,
            'program_version' => (int) $g->program_version,
            'rules' => $rules,
            'reversed_points' => (string) $g->reversed_points,
            'eligible_amount' => (string) $g->eligible_amount,
            'returned_amount' => (string) $g->returned_amount,
            'award_base_amount' => (string) $g->award_base_amount,
            'pre_award_returned_amount' => (string) $g->pre_award_returned_amount,
            'available_at' => Records::iso($g->available_at),
            'expires_at' => Records::iso($g->expires_at),
        ];
    }
}
