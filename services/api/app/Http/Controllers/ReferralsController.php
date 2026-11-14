<?php

namespace App\Http\Controllers;

use App\Domain\Referrals\Referrals;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ReferralsController extends OperationalController
{
    public function mine(Request $http, Referrals $referrals)
    {
        return ['data' => $referrals->mine($http->user())];
    }

    public function join(Request $http, Referrals $referrals)
    {
        $data = $http->validate(['code' => ['required', 'string', 'max:16']]);

        return $this->command(
            $http,
            'referral.join',
            $data,
            fn ($d) => $referrals->join($http->user(), $d['code']),
            201,
        );
    }

    public function requestPayout(Request $http, Referrals $referrals)
    {
        $data = $http->validate([
            'amount' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'channel' => ['required', 'in:mobile_money,bank_transfer,cash'],
            'destination' => ['required', 'string', 'max:40'],
        ]);

        return $this->command(
            $http,
            'referral.payout_request',
            $data,
            fn ($d) => $referrals->requestPayout($http->user(), $d),
            201,
        );
    }

    public function programs(Request $http, Referrals $referrals)
    {
        return $this->page(
            DB::table('referral_programs')->orderByDesc('version'),
            $http,
            fn ($p) => $referrals->programDto($p),
        );
    }

    public function createProgram(Request $http, Referrals $referrals)
    {
        $data = $http->validate([
            'rate_bp' => ['required', 'integer', 'min:1', 'max:10000'],
            'purchase_cap' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'purchases' => ['required', 'integer', 'min:1', 'max:5'],
            'payout_threshold' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'validation_days' => ['required', 'integer', 'min:0', 'max:365'],
            'budget' => ['required', 'integer', 'min:1', 'max:1000000000000'],
            'terms' => ['required', 'array:fr,en'],
            'terms.fr' => ['required', 'string', 'max:4000'],
            'terms.en' => ['required', 'string', 'max:4000'],
        ]);

        return $this->command(
            $http,
            'referral.program_create',
            $data,
            fn ($d) => $referrals->createProgram($d, $http->user()),
            201,
        );
    }

    public function activate(Request $http, string $id, Referrals $referrals)
    {
        return $this->command(
            $http,
            'referral.program_activate:'.$id,
            [],
            fn () => $referrals->activate($id, $http->user()),
        );
    }

    public function referrals(Request $http, Referrals $referrals)
    {
        $d = $http->validate(['status' => ['nullable', 'in:active,flagged,blocked']]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $referrals) {
            $q = DB::table('referrals')->orderBy('created_at')->orderBy('id');
            if (isset($d['status'])) {
                $q->where('status', $d['status']);
            }

            return $this->page($q, $http, fn ($r) => $referrals->referralDto($r));
        });
    }

    public function review(Request $http, string $id, Referrals $referrals)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'in:approve,block'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'referral.review:'.$id,
            $data,
            fn ($d) => $referrals->review($id, $d, $http->user()),
        );
    }

    public function payouts(Request $http, Referrals $referrals)
    {
        $d = $http->validate(['status' => ['nullable', 'in:requested,approved,paid,rejected,cancelled']]);
        $q = DB::table('referral_payouts')->orderBy('created_at')->orderBy('id');
        if (isset($d['status'])) {
            $q->where('status', $d['status']);
        }

        return $this->page(
            $q,
            $http,
            fn ($p) => [
                ...$referrals->payoutDto($p, false),
                'balance' => (string) $referrals->balance((int) $p->user_id),
            ],
        );
    }

    public function approvePayout(Request $http, string $id, Referrals $referrals)
    {
        return $this->reviewPayout($http, $id, $referrals, true);
    }

    public function rejectPayout(Request $http, string $id, Referrals $referrals)
    {
        return $this->reviewPayout($http, $id, $referrals, false);
    }

    private function reviewPayout(Request $http, string $id, Referrals $referrals, bool $approve)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'referral.payout_'.($approve ? 'approve' : 'reject').':'.$id,
            $data,
            fn ($d) => $referrals->reviewPayout($id, $d, $http->user(), $approve),
        );
    }

    public function paid(Request $http, string $id, Referrals $referrals)
    {
        $data = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'evidence_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:150'],
            'paid_at' => ['required', 'date', 'before_or_equal:now', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
        ]);

        return $this->command(
            $http,
            'referral.payout_paid:'.$id,
            $data,
            fn ($d) => $referrals->paid($id, $d, $http->user()),
        );
    }
}
