<?php

namespace App\Console\Commands;

use App\Domain\Procurement\PayableAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ScanPayableAlerts extends Command
{
    protected $signature = 'payables:scan-alerts';

    protected $description = 'Refresh supplier debt alerts and queue configured internal reminders; no external messages.';

    public function handle(PayableAlerts $alerts): int
    {
        $suppliers = 0;
        $queued = 0;
        DB::table('suppliers')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($alerts, &$suppliers, &$queued) {
                foreach ($rows as $row) {
                    $result = DB::transaction(fn () => $alerts->syncSupplier($row->id), 3);
                    $suppliers++;
                    $queued += $result['queued'];
                }
            });
        $this->info('Suppliers checked: '.$suppliers.'; internal reminders queued: '.$queued);

        return self::SUCCESS;
    }
}
