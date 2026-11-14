<?php

namespace App\Console\Commands;

use App\Domain\Accounting\AccountingCapture;
use App\Domain\Accounting\AccountingSourceCatalog;
use App\Domain\Procurement\Records;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SyncAccountingSources extends Command
{
    protected $signature = 'accounting:sync-sources {--book= : Optional configured book UUID} {--limit=100 : Maximum captures and historical events per run}';

    protected $description = 'Inventory supported verified facts and capture their sources per enabled book; never post entries or call external services.';

    public function handle(AccountingCapture $capture, AccountingSourceCatalog $catalog): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $bookId = $this->option('book');
        if ($limit === false || $limit < 1 || $limit > 1000 || ($bookId && ! Str::isUuid($bookId))) {
            $this->error('limit must be between 1 and 1000; book must be a UUID.');

            return self::INVALID;
        }
        if ($bookId && ! DB::table('accounting_books')->where('id', $bookId)->exists()) {
            $this->error('Unknown book.');

            return self::INVALID;
        }
        $counts = [
            'backfilled' => 0,
            'captured' => 0,
            'excluded' => 0,
            'review_required' => 0,
            'failed' => 0,
            'retry_pending' => 0,
            'skipped' => 0,
            'unsupported' => 0,
        ];
        $books = DB::table('accounting_books')
            ->where('capture_enabled', true)
            ->when($bookId, fn ($q) => $q->where('id', $bookId))
            ->orderBy('id')
            ->get();
        $processed = 0;
        foreach ($books as $book) {
            if ($processed >= $limit) {
                break;
            }
            $counts['backfilled'] += DB::transaction(function () use ($book, $catalog, $counts, $limit) {
                $fresh = Records::row('accounting_books', $book->id);

                return $fresh->capture_enabled ? $catalog->backfill($fresh, $limit - $counts['backfilled']) : 0;
            }, 3);
            foreach (
                $capture
                    ->eligible($book)
                    ->limit($limit - $processed)
                    ->pluck('e.id') as $eventId
            ) {
                $counts[$capture->process($book->id, $eventId)]++;
                $processed++;
            }
        }
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] + $counts['review_required'] + $counts['retry_pending'] > 0
            ? self::FAILURE
            : self::SUCCESS;
    }
}
