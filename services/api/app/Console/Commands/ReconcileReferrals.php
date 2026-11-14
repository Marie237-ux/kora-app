<?php

namespace App\Console\Commands;

use App\Domain\Referrals\Referrals;
use Illuminate\Console\Command;

final class ReconcileReferrals extends Command
{
    protected $signature = 'referrals:reconcile {--limit=100}';

    protected $description = 'Record referral rewards on delivered purchases and settle those whose delay is over; no external calls.';

    public function handle(Referrals $referrals): int
    {
        $result = $referrals->reconcile(max(1, min(500, (int) $this->option('limit'))));
        $this->info('Referral rewards recorded: '.$result['created'].'; settled: '.$result['settled']);

        return self::SUCCESS;
    }
}
