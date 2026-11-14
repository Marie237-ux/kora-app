<?php

namespace App\Console\Commands;

use App\Domain\Suppliers\SupplierSubscriptions;
use Illuminate\Console\Command;

final class SupplierSubscriptionsDaily extends Command
{
    protected $signature = 'supplier-subscriptions:daily';

    protected $description = 'Issue renewal payment requests the suppliers asked for and close ended periods; never debits anyone.';

    public function handle(SupplierSubscriptions $subscriptions): int
    {
        $this->info(json_encode($subscriptions->daily()));

        return self::SUCCESS;
    }
}
