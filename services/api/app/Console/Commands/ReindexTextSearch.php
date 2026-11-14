<?php

namespace App\Console\Commands;

use App\Domain\Search\TextIndexer;
use Illuminate\Console\Command;

final class ReindexTextSearch extends Command
{
    protected $signature = 'search:reindex-text';

    protected $description = 'Rebuild the local bilingual text index from current published catalogue fields; no provider calls.';

    public function handle(TextIndexer $indexer): int
    {
        $this->info(json_encode(['offers_checked' => $indexer->rebuild()]));

        return self::SUCCESS;
    }
}
