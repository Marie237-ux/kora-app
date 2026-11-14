<?php

namespace App\Domain\Shared;

use Illuminate\Support\Facades\DB;

final class ReadSnapshot
{
    public function run(callable $read): mixed
    {
        if (DB::transactionLevel() > 0) {
            return $read();
        }

        return DB::transaction(function () use ($read) {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }

            return $read();
        }, 3);
    }
}
