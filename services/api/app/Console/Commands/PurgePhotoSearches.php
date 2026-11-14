<?php

namespace App\Console\Commands;

use App\Domain\Search\PhotoSearch;
use Illuminate\Console\Command;

final class PurgePhotoSearches extends Command
{
    protected $signature = 'search:purge-photos {--limit=200}';

    protected $description = 'Delete expired photo searches and their private images.';

    public function handle(PhotoSearch $search): int
    {
        $this->info(json_encode(['purged' => $search->purge(max(1, min(1000, (int) $this->option('limit'))))]));

        return self::SUCCESS;
    }
}
