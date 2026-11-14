<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\TreasuryBindings;
use App\Domain\Accounting\TreasuryOutstanding;
use App\Domain\Accounting\TreasuryReconciliation;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TreasuryReconciliationController extends OperationalController
{
    private function mutate(
        Request $http,
        string $book,
        string $operation,
        array $data,
        callable $work,
        int $status = 200,
    ) {
        return $this->command(
            $http,
            'treasury.'.$operation,
            ['book_id' => $book, ...$data],
            fn ($d) => $work(Records::row('accounting_books', $book), $d),
            $status,
        );
    }

    public function bindings(Request $http, string $book)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book) {
            Records::row('accounting_books', $book, false);

            return $this->page(
                DB::table('treasury_bindings')
                    ->where('book_id', $book)
                    ->orderBy('treasury_account_id')
                    ->orderByDesc('version'),
                $http,
                fn ($b) => app(TreasuryBindings::class)->dto($b),
            );
        });
    }

    public function createBinding(Request $http, string $book)
    {
        $d = $http->validate([
            'treasury_account_id' => ['required', 'uuid'],
            'account_id' => ['required', 'uuid'],
            'version' => ['required', 'integer', 'min:0'],
            'inflow_side' => ['required', 'in:debit,credit'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'binding_create',
            $d,
            fn ($b, $data) => app(TreasuryBindings::class)->create($b, $data, $http->user()),
            201,
        );
    }

    public function reviewBinding(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'binding_review',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(TreasuryBindings::class)->review($b, $id, $data, $http->user()),
        );
    }

    public function statements(Request $http, string $book)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book) {
            Records::row('accounting_books', $book, false);
            $d = $http->validate([
                'treasury_account_id' => ['nullable', 'uuid'],
                'status' => ['nullable', 'in:open,closed,void'],
            ]);

            return $this->page(
                DB::table('treasury_statements')
                    ->where('book_id', $book)
                    ->when(
                        isset($d['treasury_account_id']),
                        fn ($q) => $q->where('treasury_account_id', $d['treasury_account_id']),
                    )
                    ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))
                    ->orderByDesc('starts_on')
                    ->orderBy('id'),
                $http,
                fn ($s) => app(TreasuryReconciliation::class)->dto($s),
            );
        });
    }

    public function import(Request $http, string $book)
    {
        $d = $http->validate([
            'binding_id' => ['required', 'uuid'],
            'reference' => ['required', 'string', 'max:120'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'opening_balance' => ['required', 'string', 'max:37'],
            'closing_balance' => ['required', 'string', 'max:37'],
            'document_id' => ['required', 'uuid'],
            'lines' => ['present', 'array', 'max:1000'],
            'lines.*' => ['array:external_id,booked_on,reference,direction,amount'],
            'lines.*.external_id' => ['required', 'string', 'max:120', 'distinct:strict'],
            'lines.*.booked_on' => ['required', 'date_format:Y-m-d'],
            'lines.*.reference' => ['required', 'string', 'max:200'],
            'lines.*.direction' => ['required', 'in:in,out'],
            'lines.*.amount' => ['required', 'string', 'max:19'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'statement_import',
            $d,
            fn ($b, $data) => app(TreasuryReconciliation::class)->import($b, $data, $http->user()),
            201,
        );
    }

    private function statement(string $book, string $id): object
    {
        return app(AccountingBooks::class)->row(
            'treasury_statements',
            $id,
            Records::row('accounting_books', $book, false),
        );
    }

    public function show(string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(
            fn () => ['data' => app(TreasuryReconciliation::class)->dto($this->statement($book, $id))],
        );
    }

    public function rows(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $this->statement($book, $id);

            return $this->page(
                app(TreasuryOutstanding::class)
                    ->rows($this->statement($book, $id))
                    ->orderBy('statement_id')
                    ->orderBy('position'),
                $http,
                fn ($r) => app(TreasuryReconciliation::class)->rowDto($r),
            );
        });
    }

    public function ledger(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $s = $this->statement($book, $id);
            $service = app(TreasuryReconciliation::class);

            return $this->page(
                $service->ledger($s)->orderBy('e.entry_date')->orderBy('l.id'),
                $http,
                fn ($l) => $service->ledgerDto($l, $s),
            );
        });
    }

    public function matches(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $this->statement($book, $id);

            return $this->page(
                DB::table('treasury_matches')->where('statement_id', $id)->orderBy('created_at')->orderBy('id'),
                $http,
                fn ($m) => app(TreasuryReconciliation::class)->matchDto($m),
            );
        });
    }

    private function reasonRules(): array
    {
        return ['version' => ['required', 'integer', 'min:1'], 'note' => ['required', 'string', 'max:2000']];
    }

    public function outstanding(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $this->statement($book, $id);

            return $this->page(
                DB::table('treasury_outstanding')->where('statement_id', $id)->orderBy('created_at')->orderBy('id'),
                $http,
                fn ($r) => app(TreasuryOutstanding::class)->dto($r),
            );
        });
    }

    public function closures(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $this->statement($book, $id);

            return $this->page(
                DB::table('treasury_closures')->where('statement_id', $id)->orderByDesc('version'),
                $http,
                fn ($r) => app(TreasuryOutstanding::class)->closureDto($r),
            );
        });
    }

    public function createOutstanding(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            ...$this->reasonRules(),
            'side' => ['required', 'in:ledger,statement'],
            'line_id' => [
                'required',
                'string',
                $http->input('side') === 'ledger' ? 'regex:/^[1-9][0-9]{0,17}$/' : 'uuid',
            ],
            'document_id' => ['required', 'uuid'],
            'expected_on' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'outstanding_create',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(TreasuryOutstanding::class)->create(
                $b,
                app(AccountingBooks::class)->row('treasury_statements', $id, $b),
                $data,
                $http->user(),
            ),
            201,
        );
    }

    public function transitionOutstanding(Request $http, string $book, string $id, string $item, string $action)
    {
        $d = $http->validate([
            ...$this->reasonRules(),
            ...$action === 'review' ? ['sha256' => ['required', 'regex:/^[a-f0-9]{64}$/']] : [],
        ]);

        return $this->mutate(
            $http,
            $book,
            'outstanding_'.$action,
            ['id' => $id, 'item_id' => $item, ...$d],
            fn ($b, $data) => app(TreasuryOutstanding::class)->transition(
                $b,
                app(AccountingBooks::class)->row('treasury_statements', $id, $b),
                $item,
                $action,
                $data,
                $http->user(),
            ),
        );
    }

    public function match(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            ...$this->reasonRules(),
            'statement_line_ids' => ['required', 'array', 'min:1', 'max:200'],
            'statement_line_ids.*' => ['required', 'uuid', 'distinct'],
            'ledger_line_ids' => ['required', 'array', 'min:1', 'max:500'],
            'ledger_line_ids.*' => ['required', 'string', 'regex:/^[1-9][0-9]{0,17}$/', 'distinct:strict'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'match',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(TreasuryReconciliation::class)->match(
                $b,
                app(AccountingBooks::class)->row('treasury_statements', $id, $b),
                $data,
                $http->user(),
            ),
            201,
        );
    }

    public function unmatch(Request $http, string $book, string $id, string $match)
    {
        $d = $http->validate($this->reasonRules());

        return $this->mutate(
            $http,
            $book,
            'unmatch',
            ['id' => $id, 'match_id' => $match, ...$d],
            fn ($b, $data) => app(TreasuryReconciliation::class)->unmatch(
                $b,
                app(AccountingBooks::class)->row('treasury_statements', $id, $b),
                $match,
                $data,
                $http->user(),
            ),
        );
    }

    public function transition(Request $http, string $book, string $id, string $action)
    {
        $d = $http->validate($this->reasonRules());

        return $this->mutate(
            $http,
            $book,
            'statement_'.$action,
            ['id' => $id, ...$d],
            fn ($b, $data) => app(TreasuryReconciliation::class)->transition(
                $b,
                app(AccountingBooks::class)->row('treasury_statements', $id, $b),
                $action,
                $data,
                $http->user(),
            ),
        );
    }
}
