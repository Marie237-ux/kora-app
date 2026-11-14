<?php

namespace App\Console\Commands;

use App\Domain\Professional\ProfessionalQuotes;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ExpireProfessionalQuotes extends Command
{
    protected $signature = 'professional:expire-quotes {--limit=100}';

    protected $description = 'Expire issued or accepted quotations with elapsed validity; never charge or notify externally.';

    public function handle(ProfessionalQuotes $quotes): int
    {
        $ids = DB::table('professional_quotes AS q')
            ->join('professional_quote_revisions AS r', 'r.id', '=', 'q.current_revision_id')
            ->whereIn('q.status', ['issued', 'accepted'])
            ->whereNull('q.order_id')
            ->where('r.valid_until', '<=', now())
            ->orderBy('r.valid_until')
            ->orderBy('q.id')
            ->limit(max(1, min(500, (int) $this->option('limit'))))
            ->pluck('q.id');
        foreach ($ids as $id) {
            DB::transaction(fn () => $quotes->expire($id), 3);
        }
        $this->info('Professional quotations checked: '.$ids->count());

        return self::SUCCESS;
    }
}
