<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\TreasuryCoverage;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TreasuryCoverageController extends OperationalController
{
    public function policies(Request $http, string $book)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book) {
            Records::row('accounting_books', $book, false);
            $d = $http->validate(['treasury_account_id' => ['nullable', 'uuid']]);

            return $this->page(
                DB::table('treasury_coverage_policies')
                    ->where('book_id', $book)
                    ->when(
                        isset($d['treasury_account_id']),
                        fn ($q) => $q->where('treasury_account_id', $d['treasury_account_id']),
                    )
                    ->orderBy('treasury_account_id')
                    ->orderByDesc('version'),
                $http,
                fn ($p) => app(TreasuryCoverage::class)->dto($p),
            );
        });
    }

    public function create(Request $http, string $book)
    {
        $d = $http->validate([
            'statement_id' => ['required', 'uuid'],
            'document_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:0'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'treasury.coverage_create',
            ['book_id' => $book, ...$d],
            fn ($data) => app(TreasuryCoverage::class)->create(
                Records::row('accounting_books', $book),
                $data,
                $http->user(),
            ),
            201,
        );
    }

    public function transition(Request $http, string $book, string $id, string $action)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
            ...$action === 'review' ? ['sha256' => ['required', 'regex:/^[a-f0-9]{64}$/']] : [],
        ]);

        return $this->command(
            $http,
            'treasury.coverage_'.$action,
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($data) => app(TreasuryCoverage::class)->transition(
                Records::row('accounting_books', $book),
                $id,
                $action,
                $data,
                $http->user(),
            ),
        );
    }

    public function report(Request $http, string $book)
    {
        $d = $http->validate([
            'treasury_account_id' => ['required', 'uuid'],
            'to' => ['required', 'date_format:Y-m-d'],
        ]);

        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(TreasuryCoverage::class)->report(
                    Records::row('accounting_books', $book, false),
                    $d['treasury_account_id'],
                    $d['to'],
                ),
            ],
        );
    }
}
