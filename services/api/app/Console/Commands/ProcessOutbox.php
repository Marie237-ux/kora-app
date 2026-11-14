<?php

namespace App\Console\Commands;

use App\Domain\Shared\OutboxProcessor;
use Illuminate\Console\Command;

final class ProcessOutbox extends Command
{
    protected $signature = 'outbox:process {--limit=100 : Maximum supported events per run}';

    protected $description = 'Process supported local DB effects with atomic receipts and bounded retries; no external sending.';

    public function handle(OutboxProcessor $processor): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('limit must be between 1 and 1000');

            return self::INVALID;
        }
        $counts = [
            'delivered' => 0,
            'suppressed' => 0,
            'projected' => 0,
            'failed' => 0,
            'skipped' => 0,
            'unsupported' => 0,
        ];
        foreach ($processor->eligible()->orderBy('created_at')->orderBy('id')->limit($limit)->pluck('id') as $id) {
            $counts[$processor->process($id)]++;
        }
        $this->info(json_encode($counts, JSON_THROW_ON_ERROR));

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
