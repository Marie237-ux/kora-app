<?php

namespace App\Console\Commands;

use App\Domain\Orders\OrderSupply;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireOrderSupply extends Command
{
    protected $signature = 'orders:expire-supply {--limit=100}';

    protected $description = 'Expire linked supplier confirmations and open customer choices; no supplier is contacted.';

    public function handle(OrderSupply $supply): int
    {
        $ids = DB::table('supplier_reservations')
            ->whereNotNull('order_line_id')
            ->whereIn('status', ['requested', 'confirmed', 'partly_confirmed'])
            ->where('expires_at', '<=', now())
            ->orderBy('expires_at')
            ->orderBy('id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(fn () => $supply->expire($id), 3);
        }
        $this->info('Supplier reservations checked: '.$ids->count());

        return self::SUCCESS;
    }
}
