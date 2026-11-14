<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\Withdrawals;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class WithdrawalsController extends OperationalController
{
    public function index(Request $http, string $book)
    {
        $d = $http->validate([
            'status' => ['nullable', 'in:pending,approved,cancelled,void'],
            'source_account_id' => ['nullable', 'uuid'],
            'destination_account_id' => ['nullable', 'uuid'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $book, $d) {
            Records::row('accounting_books', $book, false);
            $q = DB::table('withdrawals')->where('book_id', $book)->orderByDesc('requested_on')->orderBy('id');
            foreach (['status', 'source_account_id', 'destination_account_id'] as $key) {
                if (isset($d[$key])) {
                    $q->where($key, $d[$key]);
                }
            }

            return $this->page($q, $http, fn ($r) => app(Withdrawals::class)->dto($r));
        });
    }

    public function show(string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(Withdrawals::class)->dto(
                    app(AccountingBooks::class)->row(
                        'withdrawals',
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
            'source_binding_id' => ['required', 'uuid'],
            'destination_binding_id' => ['required', 'uuid'],
            'amount' => ['required', 'string'],
            'reference' => ['required', 'string', 'max:200'],
            'requested_on' => ['required', 'date_format:Y-m-d'],
            'requested_at' => ['required', 'date', 'before_or_equal:now'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
            'replaces_id' => ['nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'withdrawal.create',
            ['book_id' => $book, ...$d],
            fn ($v) => app(Withdrawals::class)->create(Records::row('accounting_books', $book), $v, $http->user()),
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
            ...$action !== 'approve'
                ? [
                    'document_id' => ['required', 'uuid'],
                    'accepted_remaining' => ['required', 'string', 'regex:/^(0|[1-9][0-9]*)$/'],
                ]
                : [],
        ]);

        return $this->command(
            $http,
            'withdrawal.'.$action,
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($v) => app(Withdrawals::class)->transition(
                Records::row('accounting_books', $book),
                $id,
                $action,
                $v,
                $http->user(),
            ),
        );
    }

    public function receipts(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            app(AccountingBooks::class)->row('withdrawals', $id, Records::row('accounting_books', $book, false));

            return $this->page(
                DB::table('withdrawal_receipts')->where('withdrawal_id', $id)->orderBy('posted_on')->orderBy('id'),
                $http,
                fn ($r) => app(Withdrawals::class)->receiptDto($r),
            );
        });
    }

    public function record(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'source_binding_id' => ['required', 'uuid'],
            'destination_binding_id' => ['required', 'uuid'],
            'entry_id' => ['required', 'uuid'],
            'source_line_id' => ['required', 'integer', 'min:1'],
            'destination_line_id' => ['required', 'integer', 'min:1'],
            'accepted_gross' => ['required', 'string'],
            'accepted_net' => ['required', 'string'],
            'accepted_fee' => ['required', 'string'],
            'received_at' => ['required', 'date', 'before_or_equal:now'],
            'reference' => ['required', 'string', 'max:200'],
            'document_id' => ['required', 'uuid'],
            'note' => ['required', 'string', 'max:2000'],
            'replaces_id' => ['nullable', 'uuid'],
        ]);

        return $this->command(
            $http,
            'withdrawal.receipt',
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($v) => app(Withdrawals::class)->record(Records::row('accounting_books', $book), $id, $v, $http->user()),
            201,
        );
    }

    public function reverse(Request $http, string $book, string $id, string $receipt)
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
            'withdrawal.receipt_reverse',
            ['book_id' => $book, 'id' => $id, 'receipt' => $receipt, ...$d],
            fn ($v) => app(Withdrawals::class)->reverse(
                Records::row('accounting_books', $book),
                $id,
                $receipt,
                $v,
                $http->user(),
            ),
        );
    }

    public function summary(Request $http, string $book)
    {
        $http->validate(['as_of' => ['prohibited']]);

        return app(ReadSnapshot::class)->run(
            fn () => ['data' => app(Withdrawals::class)->summary(Records::row('accounting_books', $book, false))],
        );
    }
}
