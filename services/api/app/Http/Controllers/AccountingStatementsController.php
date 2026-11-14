<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingExports;
use App\Domain\Accounting\AccountingStatements;
use App\Domain\Accounting\OperationalMargins;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Statement layouts and statements, exports of posted data, and the operational contribution margin. */
final class AccountingStatementsController extends OperationalController
{
    private function period(Request $http): array
    {
        return $http->validate([
            'year_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
    }

    public function layouts(Request $http, string $book, AccountingStatements $statements)
    {
        $d = $http->validate(['kind' => ['nullable', 'in:'.implode(',', AccountingStatements::KINDS)]]);
        Records::row('accounting_books', $book, false);
        $q = DB::table('accounting_statement_layouts')
            ->where('book_id', $book)
            ->orderBy('kind')
            ->orderByDesc('version');
        if (isset($d['kind'])) {
            $q->where('kind', $d['kind']);
        }

        return $this->page($q, $http, fn ($l) => $statements->layoutDto($l));
    }

    public function saveLayout(Request $http, string $book, string $kind, AccountingStatements $statements)
    {
        $d = $http->validate([
            'label' => ['required', 'array:fr,en'],
            'label.fr' => ['required', 'string', 'max:150', 'not_regex:/[<>]/'],
            'label.en' => ['required', 'string', 'max:150', 'not_regex:/[<>]/'],
            'scope' => ['required', 'array', 'min:1', 'max:20'],
            'scope.*' => ['string', 'regex:/^\d{1,12}$/'],
            'lines' => ['required', 'array', 'min:1', 'max:200'],
            'lines.*' => ['array:code,label,type,accounts,sign,side,components'],
            'lines.*.code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,12}$/'],
            'lines.*.label' => ['required', 'array:fr,en'],
            'lines.*.label.fr' => ['required', 'string', 'max:200', 'not_regex:/[<>]/'],
            'lines.*.label.en' => ['required', 'string', 'max:200', 'not_regex:/[<>]/'],
            'lines.*.type' => ['required', 'in:accounts,total'],
            'lines.*.accounts' => ['nullable', 'array', 'max:40'],
            'lines.*.accounts.*' => ['string', 'regex:/^\d{1,12}$/'],
            'lines.*.sign' => ['required_if:lines.*.type,accounts', 'nullable', 'in:debit,credit'],
            'lines.*.side' => ['nullable', 'in:assets,liabilities'],
            'lines.*.components' => ['nullable', 'array', 'max:60'],
            'lines.*.components.*.code' => ['required', 'string', 'regex:/^[A-Z0-9_]{1,12}$/'],
            'lines.*.components.*.factor' => ['required', 'integer', 'in:1,-1'],
        ]);

        return $this->command(
            $http,
            'accounting.layout:'.$book.':'.$kind,
            $d,
            fn ($data) => $statements->saveLayout(Records::row('accounting_books', $book), $kind, $data, $http->user()),
            201,
        );
    }

    public function reviewLayout(Request $http, string $book, string $id, AccountingStatements $statements)
    {
        $d = $http->validate([
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->command(
            $http,
            'accounting.layout_review:'.$id,
            $d,
            fn ($data) => $statements->reviewLayout(Records::row('accounting_books', $book), $id, $data, $http->user()),
        );
    }

    public function statement(Request $http, string $book, string $kind, AccountingStatements $statements)
    {
        $d = $this->period($http);

        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $statements->compute(Records::row('accounting_books', $book, false), $kind, $d),
            ),
        ];
    }

    /** Sensitive export: recent identity confirmation, traced with its fingerprint. */
    public function export(Request $http, string $book, string $report, AccountingExports $exports)
    {
        $d = [...$this->period($http), ...$http->validate(['format' => ['required', 'in:csv,xlsx,pdf']])];

        return app(ReadSnapshot::class)->run(
            fn () => $exports->export(
                Records::row('accounting_books', $book, false),
                $report,
                $d['format'],
                $d,
                $http->user(),
            ),
        );
    }

    public function exports(Request $http, string $book)
    {
        Records::row('accounting_books', $book, false);

        return $this->page(
            DB::table('accounting_exports')->where('book_id', $book)->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($e) => [
                'id' => $e->id,
                'report' => $e->report,
                'format' => $e->format,
                'parameters' => json_decode($e->parameters, true),
                'rows' => (int) $e->rows,
                'bytes' => (int) $e->bytes,
                'sha256' => $e->sha256,
                'user_id' => (int) $e->user_id,
                'created_at' => Records::iso($e->created_at),
            ],
        );
    }

    public function margins(Request $http, OperationalMargins $margins)
    {
        $d = $http->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => [
                'required',
                'date_format:Y-m-d',
                'after_or_equal:from',
                'before_or_equal:'.now('Africa/Douala')->addDay()->toDateString(),
            ],
            'group' => ['required', 'in:'.implode(',', OperationalMargins::GROUPS)],
        ]);

        return ['data' => app(ReadSnapshot::class)->run(fn () => $margins->report($d))];
    }
}
