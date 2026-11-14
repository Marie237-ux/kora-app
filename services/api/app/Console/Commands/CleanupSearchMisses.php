<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class CleanupSearchMisses extends Command
{
    protected $signature = 'search:cleanup-misses';

    protected $description = 'Remove expired daily search-miss aggregates; no raw search history is stored.';

    public function handle(): int
    {
        $days = max(1, min(90, (int) config('kora.search_miss_days', 30)));
        $count = DB::table('search_misses')
            ->where('day', '<', now('Africa/Douala')->subDays($days)->toDateString())
            ->delete();
        $this->info(json_encode(['deleted' => $count]));

        return self::SUCCESS;
    }
}
