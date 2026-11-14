<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\ExpensePlans;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ExpensePlansController extends OperationalController
{
    public function index(Request $http, string $book)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:pending,approved,cancelled'],
            'treasury_account_id' => ['nullable', 'uuid'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $book, $d) {
            Records::row('accounting_books', $book, false);

            return $this->page(
                DB::table('expense_plans')
                    ->where('book_id', $book)
                    ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))
                    ->when(
                        isset($d['treasury_account_id']),
                        fn ($q) => $q->where('treasury_account_id', $d['treasury_account_id']),
                    )
                    ->orderBy('due_on')
                    ->orderBy('id'),
                $http,
                fn ($p) => app(ExpensePlans::class)->dto($p),
            );
        });
    }

    public function show(string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(ExpensePlans::class)->dto(
                    app(AccountingBooks::class)->row(
                        'expense_plans',
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
            'amount' => ['required', 'string'],
            'category' => ['required', 'in:provider_fee,delivery_cost,operating_expense'],
            'description' => ['required', 'array:fr,en'],
            'description.fr' => ['required', 'string', 'max:2000'],
            'description.en' => ['required', 'string', 'max:2000'],
            'payee' => ['required', 'string', 'max:200'],
            'reference' => ['required', 'string', 'max:200'],
            'planned_on' => ['required', 'date_format:Y-m-d'],
            'due_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:planned_on'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'expense.create',
            ['book_id' => $book, ...$d],
            fn ($data) => app(ExpensePlans::class)->create(
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
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'date' => ['required', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:2000'],
            ...$action === 'cancel'
                ? ['accepted_remaining' => ['required', 'string', 'regex:/^(0|[1-9][0-9]*)$/']]
                : [],
        ]);

        return $this->command(
            $http,
            'expense.'.$action,
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($data) => app(ExpensePlans::class)->transition(
                Records::row('accounting_books', $book),
                $id,
                $action,
                $data,
                $http->user(),
            ),
        );
    }

    public function payments(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            app(AccountingBooks::class)->row('expense_plans', $id, Records::row('accounting_books', $book, false));

            return $this->page(
                DB::table('expense_payments')->where('plan_id', $id)->orderBy('posted_on')->orderBy('id'),
                $http,
                fn ($p) => app(ExpensePlans::class)->paymentDto($p),
            );
        });
    }

    public function record(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'binding_id' => ['required', 'uuid'],
            'entry_id' => ['required', 'uuid'],
            'ledger_line_id' => ['required', 'integer', 'min:1'],
            'accepted_amount' => ['required', 'string'],
            'document_id' => ['required', 'uuid'],
            'paid_at' => ['required', 'date', 'before_or_equal:now'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'expense.record',
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($data) => app(ExpensePlans::class)->recordPayment(
                Records::row('accounting_books', $book),
                $id,
                $data,
                $http->user(),
            ),
            201,
        );
    }

    public function reverse(Request $http, string $book, string $id, string $payment)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'journal_id' => ['required', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'description' => ['required', 'array:fr,en'],
            'description.fr' => ['required', 'string', 'max:2000'],
            'description.en' => ['required', 'string', 'max:2000'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'expense.reverse',
            ['book_id' => $book, 'id' => $id, 'payment' => $payment, ...$d],
            fn ($data) => app(ExpensePlans::class)->reversePayment(
                Records::row('accounting_books', $book),
                $id,
                $payment,
                $data,
                $http->user(),
            ),
        );
    }

    public function report(Request $http, string $book)
    {
        $d = $http->validate([
            'as_of' => ['required', 'date_format:Y-m-d'],
            'treasury_account_id' => ['nullable', 'uuid'],
        ]);

        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(ExpensePlans::class)->report(
                    Records::row('accounting_books', $book, false),
                    $d['as_of'],
                    $d['treasury_account_id'] ?? null,
                ),
            ],
        );
    }
}
