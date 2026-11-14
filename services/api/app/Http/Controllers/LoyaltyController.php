<?php

namespace App\Http\Controllers;

use App\Domain\Loyalty\LoyaltyAccounts;
use App\Domain\Loyalty\LoyaltyLedger;
use App\Domain\Loyalty\LoyaltyProgram;
use App\Domain\Loyalty\LoyaltyReturns;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LoyaltyController extends OperationalController
{
    public function show(Request $http, LoyaltyAccounts $accounts, LoyaltyProgram $program)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(function () use ($http, $accounts, $program) {
                $p = $program->current();
                if ($p['rules']) {
                    unset($p['rules']['budget_points']);
                }
                $account = $accounts->owned($http->user());

                return ['program' => $p, 'card' => $account ? $accounts->dto($account) : null];
            }),
        ];
    }

    public function enroll(Request $http, LoyaltyAccounts $accounts)
    {
        return $this->command($http, 'loyalty.enroll', [], fn () => $accounts->enroll($http->user()), 201);
    }

    public function rotate(Request $http, LoyaltyAccounts $accounts)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command($http, 'loyalty.rotate', $d, function ($data) use ($http, $accounts) {
            $account = $accounts->owned($http->user(), true);
            abort_unless($account, 404);

            return $accounts->rotate($account, $data['version'], $http->user());
        });
    }

    public function movements(Request $http, LoyaltyAccounts $accounts)
    {
        $account = $accounts->owned($http->user());
        abort_unless($account, 404);

        return $this->page(
            DB::table('loyalty_movements')->where('account_id', $account->id)->orderByDesc('id'),
            $http,
            fn ($m) => $accounts->movement($m),
        );
    }

    public function grants(Request $http, LoyaltyAccounts $accounts, LoyaltyLedger $ledger)
    {
        $account = $accounts->owned($http->user());
        abort_unless($account, 404);

        return $this->page(
            DB::table('loyalty_grants')->where('account_id', $account->id)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($g) => $ledger->grantDto($g),
        );
    }

    public function consult(string $token, LoyaltyAccounts $accounts, LoyaltyProgram $program)
    {
        $data = app(ReadSnapshot::class)->run(function () use ($token, $accounts, $program) {
            $account = DB::table('loyalty_accounts')->where('qr_hash', hash('sha256', $token))->first();
            abort_unless($account, 404);
            $tier = $accounts->progression($account)['tier'];

            return [
                'brand' => 'KORA',
                'card_suffix' => substr($account->card_number, -4),
                'status' => $account->status,
                'program_enabled' => $program->current()['enabled'],
                'tier' => $tier ? ['key' => $tier['key'], 'label' => $tier['label']] : null,
                'capabilities' => ['consult_only'],
            ];
        });

        return response()
            ->json(['data' => $data])
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function settings(LoyaltyProgram $program)
    {
        return ['data' => $program->current(true)];
    }

    public function saveSettings(Request $http, LoyaltyProgram $program)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'rules' => [
                'required',
                'array:earn_step_xaf,earn_points,rounding,max_points_per_order,excluded_category_ids,validation_delay_days,points_valid_days,budget_points,tier_window_days,tiers,conditions,include_professional',
            ],
            'rules.earn_step_xaf' => ['required', 'string'],
            'rules.earn_points' => ['required', 'integer', 'between:1,1000000'],
            'rules.rounding' => ['required', 'in:floor,nearest'],
            'rules.max_points_per_order' => ['required', 'integer', 'between:1,1000000000'],
            'rules.excluded_category_ids' => ['present', 'array', 'max:500'],
            'rules.excluded_category_ids.*' => ['uuid', 'distinct', 'exists:categories,id'],
            'rules.validation_delay_days' => ['required', 'integer', 'between:0,365'],
            'rules.points_valid_days' => ['present', 'nullable', 'integer', 'between:1,3650'],
            'rules.budget_points' => ['required', 'integer', 'between:0,1000000000000'],
            'rules.tier_window_days' => ['required', 'integer', 'between:1,3650'],
            'rules.include_professional' => ['required', 'boolean'],
            'rules.tiers' => ['required', 'array', 'min:1', 'max:20'],
            'rules.tiers.*' => ['array:key,label,threshold_xaf'],
            'rules.tiers.*.key' => ['required', 'string', 'regex:/^[a-z0-9_-]{1,40}$/', 'distinct'],
            'rules.tiers.*.label' => ['required', 'array:fr,en'],
            'rules.tiers.*.label.fr' => ['required', 'string', 'max:100'],
            'rules.tiers.*.label.en' => ['required', 'string', 'max:100'],
            'rules.tiers.*.threshold_xaf' => ['required', 'string'],
            'rules.conditions' => ['required', 'array:fr,en'],
            'rules.conditions.fr' => ['required', 'string', 'max:5000'],
            'rules.conditions.en' => ['required', 'string', 'max:5000'],
        ]);

        return $this->command($http, 'loyalty.settings', $d, fn ($data) => $program->save($data, $http->user()));
    }

    public function revisions(Request $http)
    {
        return $this->page(
            DB::table('loyalty_program_revisions')->orderByDesc('version'),
            $http,
            fn ($r) => [
                'version' => (int) $r->version,
                'enabled' => (bool) $r->enabled,
                'rules' => json_decode($r->rules, true),
                'sha256' => $r->sha256,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function manageShow(string $id, LoyaltyAccounts $accounts)
    {
        return ['data' => $accounts->dto(Records::row('loyalty_accounts', $id, false), false)];
    }

    public function manageMovements(Request $http, string $id, LoyaltyAccounts $accounts)
    {
        Records::row('loyalty_accounts', $id, false);

        return $this->page(
            DB::table('loyalty_movements')->where('account_id', $id)->orderByDesc('id'),
            $http,
            fn ($m) => $accounts->movement($m),
        );
    }

    public function adjust(Request $http, string $id, LoyaltyLedger $ledger)
    {
        $d = $http->validate([
            'points' => ['required', 'integer', 'between:-1000000000,1000000000', 'not_in:0'],
            'explanation' => ['required', 'array:fr,en'],
            'explanation.fr' => ['required', 'string', 'max:1000'],
            'explanation.en' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command(
            $http,
            'loyalty.adjust:'.$id,
            $d,
            fn ($data) => $ledger->adjust(Records::row('loyalty_accounts', $id), $data, $http->user()),
        );
    }

    public function status(Request $http, string $id, LoyaltyAccounts $accounts)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'in:active,suspended'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command($http, 'loyalty.status:'.$id, $d, function ($data) use ($http, $id, $accounts) {
            $account = Records::row('loyalty_accounts', $id);
            abort_unless((int) $account->version === $data['version'], 409);
            DB::table('loyalty_accounts')
                ->where('id', $id)
                ->update([
                    'status' => $data['status'],
                    'version' => $account->version + 1,
                    'updated_at' => now(),
                ]);
            Records::audit($http->user(), 'loyalty.status_changed', $id, $data);

            return $accounts->dto(Records::row('loyalty_accounts', $id, false), false);
        });
    }

    public function allocateRefund(Request $http, string $id, LoyaltyReturns $returns)
    {
        $d = $http->validate([
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:order_line_id,quantity'],
            'lines.*.order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'integer', 'between:1,1000000'],
            'explanation' => ['required', 'array:fr,en'],
            'explanation.fr' => ['required', 'string', 'max:1000'],
            'explanation.en' => ['required', 'string', 'max:1000'],
        ]);

        return $this->command($http, 'loyalty.refund:'.$id, $d, function ($data) use ($http, $id, $returns) {
            $initial = Records::row('customer_refunds', $id, false);
            $order = Records::row('orders', $initial->order_id);

            return $returns->allocate(
                $order,
                Records::row('customer_refunds', $id),
                $data['lines'],
                $data['explanation'],
                $http->user(),
            );
        });
    }
}
