<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\Financings;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class FinancingsController extends OperationalController
{
    public function index(Request $http, string $book)
    {
        $d = $http->validate([
            'kind' => ['nullable', 'in:founder_advance,capital_contribution,loan'],
            'status' => ['nullable', 'in:pending,approved,cancelled'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $book, $d) {
            Records::row('accounting_books', $book, false);
            $q = DB::table('financings')->where('book_id', $book)->orderByDesc('effective_on')->orderBy('id');
            foreach (['kind', 'status'] as $key) {
                if (isset($d[$key])) {
                    $q->where($key, $d[$key]);
                }
            }

            return $this->page($q, $http, fn ($f) => app(Financings::class)->dto($f));
        });
    }

    public function show(string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(Financings::class)->dto(
                    app(AccountingBooks::class)->row('financings', $id, Records::row('accounting_books', $book, false)),
                ),
            ],
        );
    }

    public function create(Request $http, string $book)
    {
        $d = $http->validate([
            'kind' => ['required', 'in:founder_advance,capital_contribution,loan'],
            'reference' => ['required', 'string', 'max:200'],
            'counterparty' => ['required', 'string', 'max:200'],
            'account_id' => ['required', 'uuid'],
            'principal' => ['required', 'string'],
            'effective_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['present', 'nullable', 'date_format:Y-m-d'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'financing.create',
            ['book_id' => $book, ...$d],
            fn ($v) => app(Financings::class)->create(Records::row('accounting_books', $book), $v, $http->user()),
            201,
        );
    }

    public function transition(Request $http, string $book, string $id, string $action)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:2000'],
            ...$action === 'cancel'
                ? [
                    'document_id' => ['required', 'uuid'],
                    'accepted_undrawn' => ['required', 'string', 'regex:/^(0|[1-9][0-9]*)$/'],
                ]
                : [],
        ]);

        return $this->command(
            $http,
            'financing.'.$action,
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($v) => app(Financings::class)->transition(
                Records::row('accounting_books', $book),
                $id,
                $action,
                $v,
                $http->user(),
            ),
        );
    }

    public function movements(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            app(AccountingBooks::class)->row('financings', $id, Records::row('accounting_books', $book, false));

            return $this->page(
                DB::table('financing_movements')->where('financing_id', $id)->orderBy('posted_on')->orderBy('id'),
                $http,
                fn ($m) => app(Financings::class)->movementDto($m),
            );
        });
    }

    public function record(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'direction' => ['required', 'in:funding,repayment'],
            'binding_id' => ['required', 'uuid'],
            'entry_id' => ['required', 'uuid'],
            'cash_line_id' => ['required', 'integer', 'min:1'],
            'accepted_amount' => ['required', 'string'],
            'occurred_at' => ['required', 'date', 'before_or_equal:now'],
            'reference' => ['required', 'string', 'max:200'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
            'replaces_id' => ['nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'financing.record',
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($v) => app(Financings::class)->record(Records::row('accounting_books', $book), $id, $v, $http->user()),
            201,
        );
    }

    public function reverse(Request $http, string $book, string $id, string $movement)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'journal_id' => ['required', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'array:fr,en'],
            'description.fr' => ['required', 'string', 'max:2000'],
            'description.en' => ['required', 'string', 'max:2000'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'financing.reverse',
            ['book_id' => $book, 'id' => $id, 'movement' => $movement, ...$d],
            fn ($v) => app(Financings::class)->reverse(
                Records::row('accounting_books', $book),
                $id,
                $movement,
                $v,
                $http->user(),
            ),
        );
    }

    public function summary(Request $http, string $book)
    {
        $http->validate(['as_of' => ['prohibited']]);

        return app(ReadSnapshot::class)->run(
            fn () => ['data' => app(Financings::class)->summary(Records::row('accounting_books', $book, false))],
        );
    }
}
