<?php

namespace App\Console\Commands;

use App\Domain\Orders\Orders;
use App\Domain\Payments\Payments;
use App\Domain\Procurement\Records;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--limit=50}';

    protected $description = 'Re-check pending payments whose provider transaction is known, then expire unpaid orders; no charge is created.';

    /** Bounded: each attempt is re-checked at most every two minutes and at most 60 times in total. */
    public function handle(Payments $payments, Orders $orders): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $checked = 0;
        $attempts = DB::table('payment_attempts')
            ->where('status', 'pending')
            ->whereNotNull('provider_transaction_id')
            ->where('checks', '<', 60)
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinutes(2)))
            ->orderBy('updated_at')
            ->limit($limit)
            ->get();
        foreach ($attempts as $attempt) {
            // Network call outside any transaction; verify() opens its own transaction afterwards.
            $payments->verify($attempt->id, $attempt->provider_transaction_id);
            $checked++;
        }
        $expired = 0;
        foreach (
            DB::table('orders')
                ->where('status', 'awaiting_payment')
                ->where('payment_expires_at', '<=', now())
                ->orderBy('payment_expires_at')
                ->limit($limit)
                ->pluck('id') as $id
        ) {
            DB::transaction(fn () => $orders->expire(Records::row('orders', $id)), 3);
            $expired++;
        }
        $this->info('Payments checked: '.$checked.'; orders expired: '.$expired);

        return self::SUCCESS;
    }
}
