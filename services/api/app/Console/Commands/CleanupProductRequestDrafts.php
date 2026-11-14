<?php

namespace App\Console\Commands;

use App\Domain\ProductRequests\DraftCleanup;
use Illuminate\Console\Command;

final class CleanupProductRequestDrafts extends Command
{
    protected $signature = 'product-requests:cleanup-drafts {--limit=100}';

    protected $description = 'Delete expired draft photos and drafts, redact session response snapshots, retry failed object deletions.';

    public function handle(DraftCleanup $cleanup): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('limit must be between 1 and 1000');

            return self::INVALID;
        }
        $counts = $cleanup->run($limit);
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['storage_failures'] ? self::FAILURE : self::SUCCESS;
    }
}
