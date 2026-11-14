<?php

namespace App\Console\Commands;

use App\Domain\Loyalty\LoyaltyLedger;
use App\Domain\Loyalty\LoyaltyRedemptions;
use App\Domain\Procurement\Records;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcileLoyalty extends Command
{
    protected $signature = 'loyalty:reconcile {--limit=100}';

    protected $description = 'Reward verified delivered orders, mature pending points and expire unused credits; no external calls.';

    public function handle(LoyaltyLedger $ledger): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        app(LoyaltyRedemptions::class)->expire($limit);
        $orders = DB::table('orders AS o')
            ->leftJoin('loyalty_grants AS g', 'g.order_id', '=', 'o.id')
            ->whereNotNull('o.loyalty_snapshot')
            ->whereNotNull('o.paid_at')
            ->whereNotNull('o.delivered_at')
            ->where('o.status', 'paid')
            ->where('o.delivered_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('g.id')->orWhere('g.status', 'funding_required'))
            ->orderByRaw('CASE WHEN g.id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('o.delivered_at')
            ->orderBy('o.id')
            ->limit($limit)
            ->pluck('o.id');
        foreach ($orders as $id) {
            DB::transaction(fn () => $ledger->delivered(Records::row('orders', $id)), 3);
        }
        $accounts = DB::table('loyalty_grants')
            ->where(function ($q) {
                $q->where(fn ($p) => $p->where('status', 'pending')->where('available_at', '<=', now()))->orWhere(
                    fn ($p) => $p
                        ->where('status', 'acquired')
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '<=', now()),
                );
            })
            ->select('account_id')
            ->distinct()
            ->orderBy('account_id')
            ->limit($limit)
            ->pluck('account_id');
        foreach ($accounts as $id) {
            DB::transaction(function () use ($id, $ledger) {
                Records::row('loyalty_accounts', $id);
                $ledger->reconcile($id);
            }, 3);
        }
        $this->info('Loyalty orders checked: '.$orders->count().'; accounts reconciled: '.$accounts->count());

        return self::SUCCESS;
    }
}
