<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\BalanceObservations;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BalanceObservationsController extends OperationalController
{
    public function index(Request $http, string $book)
    {
        $d = $http->validate(['treasury_account_id' => ['nullable', 'uuid']]);

        return app(ReadSnapshot::class)->run(function () use ($http, $book, $d) {
            Records::row('accounting_books', $book, false);
            $q = DB::table('treasury_balance_observations')
                ->where('book_id', $book)
                ->orderByDesc('observed_at')
                ->orderBy('treasury_account_id')
                ->orderByDesc('sequence');
            if (isset($d['treasury_account_id'])) {
                $q->where('treasury_account_id', $d['treasury_account_id']);
            }

            return $this->page($q, $http, fn ($r) => app(BalanceObservations::class)->dto($r));
        });
    }

    public function show(string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(BalanceObservations::class)->dto(
                    app(AccountingBooks::class)->row(
                        'treasury_balance_observations',
                        $id,
                        Records::row('accounting_books', $book, false),
                    ),
                ),
            ],
        );
    }

    public function create(Request $http, string $book)
    {
        $d = $http->validate([
            'binding_id' => ['required', 'uuid'],
            'sequence' => ['required', 'integer', 'min:0'],
            'reference' => ['required', 'string', 'max:200'],
            'reported_balance' => ['required', 'string'],
            'available_funds' => ['present', 'nullable', 'string'],
            'withdrawable_funds' => ['present', 'nullable', 'string'],
            'observed_at' => ['required', 'date'],
            'expires_at' => ['required', 'date'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'treasury.balance_create',
            ['book_id' => $book, ...$d],
            fn ($v) => app(BalanceObservations::class)->create(
                Records::row('accounting_books', $book),
                $v,
                $http->user(),
            ),
            201,
        );
    }

    public function transition(Request $http, string $book, string $id, string $action)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'note' => ['required', 'string', 'max:2000'],
            ...$action === 'void' ? ['document_id' => ['required', 'uuid']] : [],
        ]);

        return $this->command(
            $http,
            'treasury.balance_'.$action,
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($v) => app(BalanceObservations::class)->transition(
                Records::row('accounting_books', $book),
                $id,
                $action,
                $v,
                $http->user(),
            ),
        );
    }

    public function summary(Request $http, string $book)
    {
        $http->validate(['as_of' => ['prohibited']]);

        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(BalanceObservations::class)->summary(Records::row('accounting_books', $book, false)),
            ],
        );
    }
}
