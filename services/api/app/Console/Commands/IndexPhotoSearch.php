<?php

namespace App\Console\Commands;

use App\Domain\Search\PhotoIndexer;
use Illuminate\Console\Command;

final class IndexPhotoSearch extends Command
{
    protected $signature = 'search:index-photos {--limit=50} {--all : Queue every offer again (full reindexing)}';

    protected $description = 'Compute missing or outdated offer vectors for photo search; stops when AI is not configured or over budget.';

    public function handle(PhotoIndexer $indexer): int
    {
        if ($this->option('all')) {
            $this->info(json_encode(['queued' => $indexer->reindexAll()]));
        }
        $this->info(json_encode($indexer->process(max(1, min(500, (int) $this->option('limit'))))));

        return self::SUCCESS;
    }
}
