<?php

namespace App\Console\Commands;

use App\Domain\Reviews\Engagement;
use Illuminate\Console\Command;

final class CheckProductAlerts extends Command
{
    protected $signature = 'alerts:check {--limit=200}';

    protected $description = 'Notify customers once when a watched offer gets cheaper or available again; no external calls.';

    public function handle(Engagement $engagement): int
    {
        $this->info('Alerts triggered: '.$engagement->check(max(1, min(1000, (int) $this->option('limit')))));

        return self::SUCCESS;
    }
}
