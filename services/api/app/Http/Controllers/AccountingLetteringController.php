<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\AccountingLettering;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AccountingLetteringController extends OperationalController
{
    public function index(Request $http, string $book)
    {
        $d = $http->validate([
            'account_id' => ['nullable', 'uuid'],
            'party_id' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,void'],
        ]);

        return app(ReadSnapshot::class)->run(function () use ($http, $book, $d) {
            $b = Records::row('accounting_books', $book, false);
            if (isset($d['account_id'])) {
                app(AccountingBooks::class)->row('accounting_accounts', $d['account_id'], $b);
            }

            return $this->page(
                DB::table('accounting_letterings')
                    ->where('book_id', $book)
                    ->whereIn('status', ['active', 'void'])
                    ->when(isset($d['account_id']), fn ($q) => $q->where('account_id', $d['account_id']))
                    ->when(isset($d['party_id']), fn ($q) => $q->where('party_key', $d['party_id']))
                    ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))
                    ->orderByDesc('matched_on')
                    ->orderBy('id'),
                $http,
                fn ($g) => app(AccountingLettering::class)->dto($g),
            );
        });
    }

    public function show(string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($book, $id) {
            $g = app(AccountingBooks::class)->row(
                'accounting_letterings',
                $id,
                Records::row('accounting_books', $book, false),
            );
            abort_unless($g->status !== 'building', 404);

            return ['data' => app(AccountingLettering::class)->dto($g)];
        });
    }

    public function create(Request $http, string $book)
    {
        $d = $http->validate([
            'matched_on' => ['required', 'date_format:Y-m-d'],
            'document_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:200'],
            'note' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:200'],
            'lines.*' => ['array:ledger_line_id,amount'],
            'lines.*.ledger_line_id' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.amount' => ['required', 'string'],
        ]);

        return $this->command(
            $http,
            'accounting.lettering_create',
            ['book_id' => $book, ...$d],
            fn ($data) => app(AccountingLettering::class)->create(
                Records::row('accounting_books', $book),
                $data,
                $http->user(),
            ),
            201,
        );
    }

    public function void(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'voided_on' => ['required', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'accounting.lettering_void',
            ['book_id' => $book, 'id' => $id, ...$d],
            fn ($data) => app(AccountingLettering::class)->void(
                Records::row('accounting_books', $book),
                $id,
                $data,
                $http->user(),
            ),
        );
    }

    public function items(Request $http, string $book)
    {
        $d = $http->validate([
            'account_id' => ['required', 'uuid'],
            'party_id' => ['nullable', 'string', 'max:100'],
            'as_of' => ['required', 'date_format:Y-m-d'],
            'status' => ['nullable', 'in:open,all'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return app(ReadSnapshot::class)->run(
            fn () => [
                'data' => app(AccountingLettering::class)->report(Records::row('accounting_books', $book, false), $d),
            ],
        );
    }
}
