<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\TreasuryForecast;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Domain\Shared\ReadSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

final class TreasuryForecastController extends Controller
{
    public function show(Request $http, string $book)
    {
        $d = $http->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'as_of' => ['prohibited'],
            'supplier_id' => ['prohibited'],
            'treasury_account_id' => ['prohibited'],
        ]);
        if (CarbonImmutable::parse($d['from'], 'Africa/Douala')->addDays(366)->toDateString() < $d['to']) {
            Money::fail('to', 'FORECAST_RANGE_INVALID');
        }

        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(TreasuryForecast::class)->calculate(
                    Records::row('accounting_books', $book, false),
                    $d['from'],
                    $d['to'],
                ),
            ],
        );
    }
}
