<?php

namespace App\Domain\Loyalty;

use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

final class LoyaltyRedemptionPolicy
{
    public function reserved(): int
    {
        return (int) DB::table('loyalty_redemptions')
            ->where('status', 'held')
            ->where('expires_at', '>', now())
            ->sum('discount');
    }

    public function current(bool $internal = false): array
    {
        $p = Records::row('loyalty_redemption_policy', '1', false);
        $rules = $p->rules ? json_decode($p->rules, true) : null;
        if (! $internal && $rules) {
            unset($rules['budget_xaf'], $rules['loss_reason']);
        }

        return [
            'version' => (int) $p->version,
            'enabled' => (bool) $p->enabled,
            'rules' => $rules,
            ...$internal ? ['used_budget' => $p->used_budget, 'reserved_budget' => (string) $this->reserved()] : [],
        ];
    }

    public function save(array $data, User $actor): array
    {
        $p = Records::row('loyalty_redemption_policy', '1');
        abort_unless((int) $p->version === $data['version'], 409);
        $rules = $data['rules'];
        foreach (['value_per_unit_xaf', 'budget_xaf', 'minimum_payable_xaf'] as $key) {
            $amount = Money::parse($rules[$key]);
            if ($key !== 'budget_xaf' && $amount < 1) {
                LoyaltyProgram::fail('rules.'.$key, 'REDEMPTION_RULES_INVALID');
            }
        }
        $rules['points_per_unit'] = (int) $rules['points_per_unit'];
        $rules['max_discount_basis_points'] = (int) $rules['max_discount_basis_points'];
        $rules['restore_valid_days'] =
            $rules['restore_valid_days'] === null ? null : (int) $rules['restore_valid_days'];
        $rules['combine_delivery_promotions'] = (bool) $rules['combine_delivery_promotions'];
        $rules['restore_points_on_refund'] = (bool) $rules['restore_points_on_refund'];
        $rules['allow_negative_margin'] = (bool) $rules['allow_negative_margin'];
        if (
            $rules['allow_negative_margin'] &&
            (empty(trim($rules['loss_reason'] ?? '')) ||
                ! in_array('pricing.loss_override', $actor->permissions(), true))
        ) {
            LoyaltyProgram::fail('rules.loss_reason', 'REDEMPTION_LOSS_NOT_APPROVED');
        }
        if (BigInteger::of($p->used_budget)->plus($this->reserved())->compareTo($rules['budget_xaf']) > 0) {
            LoyaltyProgram::fail('rules.budget_xaf', 'REDEMPTION_BUDGET_COMMITTED');
        }
        $version = (int) $p->version + 1;
        DB::table('loyalty_redemption_revisions')->insert([
            'id' => Records::id(),
            'version' => $version,
            'enabled' => $data['enabled'],
            'rules' => Records::json($rules),
            'sha256' => app(CommandBus::class)->fingerprint($rules),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
        DB::table('loyalty_redemption_policy')
            ->where('id', 1)
            ->update([
                'version' => $version,
                'enabled' => $data['enabled'],
                'rules' => Records::json($rules),
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'loyalty.redemption_policy_updated', '1', ['version' => $version]);

        return $this->current(true);
    }

    public function assertBudget(object $policy, int $discount): void
    {
        $rules = json_decode($policy->rules, true);
        if (
            BigInteger::of($policy->used_budget)
                ->plus($this->reserved())
                ->plus($discount)
                ->compareTo($rules['budget_xaf']) > 0
        ) {
            LoyaltyProgram::fail('points', 'REDEMPTION_BUDGET_REQUIRED');
        }
    }
}
