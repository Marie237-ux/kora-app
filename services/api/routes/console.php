<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('inventory:expire')->everyMinute()->withoutOverlapping(5);
Schedule::command('payments:reconcile --limit=50')->everyMinute()->withoutOverlapping(5);
Schedule::command('orders:expire-supply --limit=100')->everyMinute()->withoutOverlapping(5);
Schedule::command('professional:expire-quotes --limit=100')->everyMinute()->withoutOverlapping(5);
Schedule::command('loyalty:reconcile --limit=100')->everyMinute()->withoutOverlapping(10);
Schedule::command('referrals:reconcile --limit=100')->everyMinute()->withoutOverlapping(10);
Schedule::command('alerts:check --limit=200')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('payables:scan-alerts')->everyMinute()->withoutOverlapping(10);
Schedule::command('outbox:process --limit=100')->everyMinute()->withoutOverlapping(10);
Schedule::command('accounting:sync-sources --limit=100')->everyMinute()->withoutOverlapping(10);
Schedule::command('product-requests:cleanup-drafts --limit=100')->hourly()->withoutOverlapping(30);
Schedule::command('search:cleanup-misses')->daily()->timezone('Africa/Douala')->withoutOverlapping(30);
Schedule::command('search:index-photos --limit=50')->everyFiveMinutes()->withoutOverlapping(15);
Schedule::command('search:purge-photos --limit=200')->hourly()->withoutOverlapping(30);
Schedule::command('supplier-subscriptions:daily')->dailyAt('06:00')->timezone('Africa/Douala')->withoutOverlapping(30);
