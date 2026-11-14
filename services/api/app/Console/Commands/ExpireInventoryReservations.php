<?php

namespace App\Console\Commands;

use App\Domain\Inventory\Inventory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireInventoryReservations extends Command
{
    protected $signature = 'inventory:expire';

    protected $description = 'Release expired warehouse holds atomically; no external supplier or payment action.';

    public function handle(Inventory $inventory): int
    {
        $count = 0;
        DB::table('inventory_reservations')
            ->where('status', 'active')
            ->whereNull('order_line_id')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($inventory, &$count) {
                foreach ($rows as $row) {
                    DB::transaction(fn () => $inventory->release($row->id, null, true), 3);
                    $count++;
                }
            });
        $this->info('Expired reservations processed: '.$count);

        return self::SUCCESS;
    }
}
