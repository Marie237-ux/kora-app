<?php

namespace App\Domain\Loyalty;

use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LoyaltyProgram
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('loyalty.'.$code)]);
    }

    public function current(bool $internal = false): array
    {
        $p = Records::row('loyalty_program', '1', false);

        return [
            'version' => (int) $p->version,
            'enabled' => (bool) $p->enabled,
            'rules' => $p->rules ? json_decode($p->rules, true) : null,
            ...$internal ? ['committed_points' => (string) $p->committed_points] : [],
        ];
    }

    public function save(array $data, User $actor): array
    {
        $p = Records::row('loyalty_program', '1');
        abort_unless((int) $p->version === $data['version'], 409);
        $rules = $data['rules'];
        foreach (
            ['earn_points', 'max_points_per_order', 'validation_delay_days', 'budget_points', 'tier_window_days'] as $key
        ) {
            $rules[$key] = (int) $rules[$key];
        }
        $rules['points_valid_days'] = $rules['points_valid_days'] === null ? null : (int) $rules['points_valid_days'];
        $rules['include_professional'] = (bool) $rules['include_professional'];
        Money::parse($rules['earn_step_xaf']);
        foreach ($rules['tiers'] as $tier) {
            Money::parse($tier['threshold_xaf']);
        }
        usort($rules['tiers'], fn ($a, $b) => Money::parse($a['threshold_xaf']) <=> Money::parse($b['threshold_xaf']));
        if (
            Money::parse($rules['earn_step_xaf']) < 1 ||
            (int) $rules['tiers'][0]['threshold_xaf'] !== 0 ||
            count(array_unique(array_column($rules['tiers'], 'threshold_xaf'))) !== count($rules['tiers'])
        ) {
            self::fail('rules', 'RULES_INVALID');
        }
        if ($rules['budget_points'] < $p->committed_points) {
            self::fail('rules.budget_points', 'BUDGET_COMMITTED');
        }
        $version = $p->version + 1;
        DB::table('loyalty_program_revisions')->insert([
            'id' => Records::id(),
            'version' => $version,
            'enabled' => $data['enabled'],
            'rules' => Records::json($rules),
            'sha256' => app(CommandBus::class)->fingerprint($rules),
            'actor_id' => $actor->id,
            'created_at' => now(),
        ]);
        DB::table('loyalty_program')
            ->where('id', 1)
            ->update([
                'version' => $version,
                'enabled' => $data['enabled'],
                'rules' => Records::json($rules),
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'loyalty.program_updated', '1', ['version' => $version, 'enabled' => $data['enabled']]);

        return $this->current(true);
    }

    public function capture(User $actor, bool $professional): ?array
    {
        $program = $this->current();
        if (
            ! $program['enabled'] ||
            ! $program['rules'] ||
            ($professional && ! $program['rules']['include_professional'])
        ) {
            return null;
        }
        $account = DB::table('loyalty_accounts')->where('user_id', $actor->id)->where('status', 'active')->first();

        return $account ? [...$program, 'account_id' => $account->id] : null;
    }

    public function points(int $eligible, array $rules): int
    {
        $step = Money::parse($rules['earn_step_xaf']);
        $count = intdiv($eligible, $step);
        if ($rules['rounding'] === 'nearest' && $eligible % $step >= intdiv($step, 2) + ($step % 2)) {
            $count++;
        }

        return $count > intdiv($rules['max_points_per_order'], $rules['earn_points'])
            ? $rules['max_points_per_order']
            : min($rules['max_points_per_order'], $count * $rules['earn_points']);
    }
}
