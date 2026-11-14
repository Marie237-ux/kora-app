<?php

namespace App\Http\Controllers;

use App\Domain\Accounting\AccountingBooks;
use App\Domain\Accounting\AccountingCalendar;
use App\Domain\Accounting\AccountingCapture;
use App\Domain\Accounting\AccountingEntries;
use App\Domain\Accounting\AccountingReports;
use App\Domain\Accounting\AccountingSourceCatalog;
use App\Domain\Accounting\AccountingSourceRegistry;
use App\Domain\Accounting\AccountingSources;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class AccountingController extends OperationalController
{
    private function label(string $key = 'label'): array
    {
        return [
            $key => ['required', 'array:fr,en'],
            $key.'.fr' => ['required', 'string', 'max:2000'],
            $key.'.en' => ['required', 'string', 'max:2000'],
        ];
    }

    private function mutate(Request $http, string $book, string $name, array $data, callable $work, int $status = 200)
    {
        return $this->command(
            $http,
            'accounting.'.$name,
            ['book_id' => $book, ...$data],
            fn ($d) => $work(Records::row('accounting_books', $book), $d),
            $status,
        );
    }

    private function read(string $book, callable $work): array
    {
        return ['data' => app(ReadSnapshot::class)->run(fn () => $work(Records::row('accounting_books', $book, false)))];
    }

    public function books(Request $http)
    {
        return app(ReadSnapshot::class)->run(
            fn () => $this->page(
                DB::table('accounting_books')->orderBy('created_at')->orderBy('id'),
                $http,
                fn ($b) => app(AccountingBooks::class)->dto($b),
            ),
        );
    }

    public function createBook(Request $http)
    {
        $d = $http->validate(['mode' => ['required', 'in:demo,live'], ...$this->label()]);

        return $this->command(
            $http,
            'accounting.book_create',
            $d,
            fn ($data) => app(AccountingBooks::class)->create($data, $http->user()),
            201,
        );
    }

    public function show(string $book)
    {
        return $this->read($book, fn ($b) => app(AccountingBooks::class)->dto($b));
    }

    public function chart(string $book)
    {
        return $this->read($book, fn ($b) => app(AccountingBooks::class)->chart($b));
    }

    public function importChart(Request $http, string $book)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:0'],
            'reference' => ['required', 'string', 'max:200'],
            'accounts' => ['required', 'array', 'min:1', 'max:5000'],
            'accounts.*' => ['array:code,parent_code,label,kind,auxiliary_type,postable,active'],
            'accounts.*.code' => ['required', 'string', 'regex:/^[0-9]{1,20}$/', 'distinct:strict'],
            'accounts.*.parent_code' => ['present', 'nullable', 'string', 'regex:/^[0-9]{1,20}$/'],
            ...$this->label('accounts.*.label'),
            'accounts.*.kind' => ['required', 'in:asset,liability,equity,income,expense,other'],
            'accounts.*.auxiliary_type' => ['required', 'in:none,customer,supplier'],
            'accounts.*.postable' => ['required', 'boolean'],
            'accounts.*.active' => ['required', 'boolean'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'chart_import',
            $d,
            fn ($b, $data) => app(AccountingBooks::class)->importChart($b, $data, $http->user()),
        );
    }

    public function reviewChart(Request $http, string $book)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'chart_review',
            $d,
            fn ($b, $data) => app(AccountingBooks::class)->reviewChart($b, $data, $http->user()),
        );
    }

    public function journals(string $book)
    {
        return $this->read(
            $book,
            fn ($b) => DB::table('accounting_journals')
                ->where('book_id', $b->id)
                ->orderBy('code')
                ->get()
                ->map(fn ($j) => app(AccountingBooks::class)->journalDto($j)),
        );
    }

    public function saveJournal(Request $http, string $book, ?string $id = null)
    {
        $d = $http->validate([
            ...$this->label(),
            'active' => ['required', 'boolean'],
            ...$id
                ? ['version' => ['required', 'integer', 'min:1']]
                : ['code' => ['required', 'string', 'regex:/^[A-Z][A-Z0-9]{0,11}$/']],
        ]);

        return $this->mutate(
            $http,
            $book,
            'journal_save',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingBooks::class)->journal($b, $id, $data, $http->user()),
            $id ? 200 : 201,
        );
    }

    public function years(string $book)
    {
        return $this->read(
            $book,
            fn ($b) => DB::table('accounting_years')
                ->where('book_id', $b->id)
                ->orderBy('starts_on')
                ->get()
                ->map(fn ($r) => app(AccountingCalendar::class)->dto($r)),
        );
    }

    public function periods(string $book, string $year)
    {
        return $this->read($book, function ($b) use ($year) {
            app(AccountingBooks::class)->row('accounting_years', $year, $b);

            return DB::table('accounting_periods')
                ->where('year_id', $year)
                ->orderBy('starts_on')
                ->get()
                ->map(fn ($r) => app(AccountingCalendar::class)->dto($r));
        });
    }

    public function createCalendar(Request $http, string $book, ?string $year = null)
    {
        $d = $http->validate([
            ...$this->label(),
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'calendar_create',
            ['year_id' => $year, ...$d],
            fn ($b, $data) => app(AccountingCalendar::class)->create($b, $year, $data, $http->user()),
            201,
        );
    }

    public function transition(Request $http, string $book, string $kind, string $id, string $action)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'calendar_'.$action,
            ['kind' => $kind, 'id' => $id, ...$d],
            fn ($b, $data) => app(AccountingCalendar::class)->transition(
                $b,
                $id,
                $kind === 'years',
                $action === 'reopen',
                $data,
                $http->user(),
            ),
        );
    }

    private function entryRules(): array
    {
        return [
            'journal_id' => ['required', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            ...$this->label('description'),
            'reference' => ['required', 'string', 'max:200'],
            'document_ids' => ['present', 'array', 'max:50'],
            'document_ids.*' => ['required', 'uuid', 'distinct'],
            'lines' => ['required', 'array', 'min:1', 'max:1000'],
            'lines.*' => ['array:account_id,debit,credit,party'],
            'lines.*.account_id' => ['required', 'uuid'],
            'lines.*.debit' => ['required', 'string'],
            'lines.*.credit' => ['required', 'string'],
            'lines.*.party' => ['nullable', 'array:type,id'],
            'lines.*.party.type' => ['required_with:lines.*.party', 'in:customer,supplier'],
            'lines.*.party.id' => ['required_with:lines.*.party', 'string', 'max:100'],
        ];
    }

    public function entries(Request $http, string $book)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book) {
            Records::row('accounting_books', $book, false);
            $d = $http->validate(['status' => ['nullable', 'in:draft,posted,cancelled']]);

            return $this->page(
                DB::table('accounting_entries')
                    ->where('book_id', $book)
                    ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))
                    ->orderByDesc('entry_date')
                    ->orderBy('id'),
                $http,
                fn ($e) => app(AccountingEntries::class)->dto($e),
            );
        });
    }

    public function entry(string $book, string $id)
    {
        return $this->read(
            $book,
            fn ($b) => app(AccountingEntries::class)->dto(
                app(AccountingBooks::class)->row('accounting_entries', $id, $b),
            ),
        );
    }

    public function saveEntry(Request $http, string $book, ?string $id = null)
    {
        $d = $http->validate([...$this->entryRules(), ...$id ? ['version' => ['required', 'integer', 'min:1']] : []]);

        return $this->mutate(
            $http,
            $book,
            'entry_save',
            ['id' => $id, ...$d],
            function ($b, $data) use ($http, $id) {
                $service = app(AccountingEntries::class);

                return $id
                    ? $service->update(
                        $b,
                        app(AccountingBooks::class)->row('accounting_entries', $id, $b),
                        $data,
                        $http->user(),
                    )
                    : $service->create($b, $data, $http->user());
            },
            $id ? 200 : 201,
        );
    }

    public function post(Request $http, string $book, string $id)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->mutate(
            $http,
            $book,
            'entry_post',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingEntries::class)->post(
                $b,
                app(AccountingBooks::class)->row('accounting_entries', $id, $b),
                $data['version'],
                $http->user(),
            ),
        );
    }

    public function cancel(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'entry_cancel',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingEntries::class)->cancel(
                app(AccountingBooks::class)->row('accounting_entries', $id, $b),
                $data,
                $http->user(),
            ),
        );
    }

    public function reverse(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'journal_id' => ['required', 'uuid'],
            'entry_date' => ['required', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:2000'],
            ...$this->label('description'),
        ]);

        return $this->mutate(
            $http,
            $book,
            'entry_reverse',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingEntries::class)->reverse(
                $b,
                app(AccountingBooks::class)->row('accounting_entries', $id, $b),
                $data,
                $http->user(),
            ),
            201,
        );
    }

    public function revisions(Request $http, string $book, ?string $id = null)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $b = Records::row('accounting_books', $book, false);
            if ($id) {
                app(AccountingBooks::class)->row('accounting_entries', $id, $b);
            }
            $q = $id
                ? DB::table('accounting_entry_revisions')->where('entry_id', $id)
                : DB::table('accounting_chart_revisions')->where('book_id', $book);

            return $this->page($q->orderByDesc('version'), $http, function ($r) {
                $r->snapshot = json_decode($r->snapshot, true);

                return (array) $r;
            });
        });
    }

    public function sourceTypes()
    {
        return [
            'data' => [
                'capture' => 'manual_or_configured_events',
                'automatic_posting' => false,
                'types' => collect(AccountingSourceRegistry::TYPES)->map(
                    fn ($t) => ['type' => $t, 'measures' => app(AccountingSourceRegistry::class)->measures($t)],
                ),
            ],
        ];
    }

    public function mappings(string $book)
    {
        return $this->read(
            $book,
            fn ($b) => DB::table('accounting_mappings')
                ->where('book_id', $b->id)
                ->orderBy('source_type')
                ->orderByDesc('version')
                ->get()
                ->map(fn ($m) => app(AccountingSources::class)->mappingDto($m)),
        );
    }

    public function saveMapping(Request $http, string $book)
    {
        $d = $http->validate([
            'source_type' => ['required', Rule::in(AccountingSourceRegistry::TYPES)],
            'version' => ['required', 'integer', 'min:0'],
            'journal_id' => ['required', 'uuid'],
            'enabled' => ['required', 'boolean'],
            'note' => ['required', 'string', 'max:2000'],
            'lines' => ['required', 'array', 'min:2', 'max:50'],
            'lines.*' => ['array:measure,side,account_id'],
            'lines.*.measure' => ['required', 'string'],
            'lines.*.side' => ['required', 'in:debit,credit'],
            'lines.*.account_id' => ['required', 'uuid'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'mapping_save',
            $d,
            fn ($b, $data) => app(AccountingSources::class)->mapping($b, $data, $http->user()),
            201,
        );
    }

    public function reviewMapping(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'sha256' => ['required', 'regex:/^[a-f0-9]{64}$/'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'mapping_review',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingSources::class)->review($b, $id, $data, $http->user()),
        );
    }

    public function sources(Request $http, string $book)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book) {
            Records::row('accounting_books', $book, false);

            return $this->page(
                DB::table('accounting_sources')->where('book_id', $book)->orderByDesc('created_at')->orderBy('id'),
                $http,
                fn ($s) => app(AccountingSources::class)->dto($s),
            );
        });
    }

    public function capture(Request $http, string $book)
    {
        $d = $http->validate([
            'source_type' => ['required', Rule::in(AccountingSourceRegistry::TYPES)],
            'resource_id' => ['required', 'uuid'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'source_capture',
            $d,
            fn ($b, $data) => app(AccountingSources::class)->capture(
                $b,
                $data['source_type'],
                $data['resource_id'],
                $http->user(),
            ),
            201,
        );
    }

    public function prepare(Request $http, string $book, string $id)
    {
        $d = $http->validate([
            'entry_version' => ['nullable', 'integer', 'min:1'],
            'entry_date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'source_prepare',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingSources::class)->prepare(
                $b,
                app(AccountingBooks::class)->row('accounting_sources', $id, $b),
                $data,
                $http->user(),
            ),
        );
    }

    public function captureSettings(string $book)
    {
        return $this->read($book, fn ($b) => app(AccountingCapture::class)->settings($b));
    }

    public function configureCapture(Request $http, string $book)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'from' => ['present', 'nullable', 'date_format:Y-m-d'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        return $this->mutate(
            $http,
            $book,
            'capture_configure',
            $d,
            fn ($b, $data) => app(AccountingCapture::class)->configure($b, $data, $http->user()),
        );
    }

    public function captureReceipts(Request $http, string $book)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book) {
            Records::row('accounting_books', $book, false);
            $d = $http->validate([
                'status' => ['nullable', 'in:captured,excluded,review_required,retry_pending,failed'],
            ]);

            return $this->page(
                DB::table('accounting_capture_receipts')
                    ->where('book_id', $book)
                    ->when(isset($d['status']), fn ($q) => $q->where('status', $d['status']))
                    ->orderByDesc('updated_at')
                    ->orderBy('id'),
                $http,
                fn ($r) => app(AccountingCapture::class)->dto($r),
            );
        });
    }

    public function captureAttempts(Request $http, string $book, string $id)
    {
        return app(ReadSnapshot::class)->run(function () use ($http, $book, $id) {
            $b = Records::row('accounting_books', $book, false);
            app(AccountingBooks::class)->row('accounting_capture_receipts', $id, $b);

            return $this->page(
                DB::table('accounting_capture_attempts')->where('receipt_id', $id)->orderByDesc('attempt'),
                $http,
                fn ($r) => [
                    'attempt' => (int) $r->attempt,
                    'status' => $r->status,
                    'reason' => $r->reason,
                    'policy_version' => (int) $r->policy_version,
                    'source_id' => $r->source_id,
                    'created_at' => Records::iso($r->created_at),
                ],
            );
        });
    }

    public function retryCapture(Request $http, string $book, string $id)
    {
        $d = $http->validate(['note' => ['required', 'string', 'max:2000']]);

        return $this->mutate(
            $http,
            $book,
            'capture_retry',
            ['id' => $id, ...$d],
            fn ($b, $data) => app(AccountingCapture::class)->retry(
                $b,
                app(AccountingBooks::class)->row('accounting_capture_receipts', $id, $b),
                $data['note'],
                $http->user(),
            ),
        );
    }

    public function coverage(Request $http, string $book)
    {
        $d = $http->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return $this->read($book, fn ($b) => app(AccountingSourceCatalog::class)->coverage($b, $d['from'], $d['to']));
    }

    public function report(Request $http, string $book, string $report)
    {
        $d = $http->validate([
            'year_id' => ['required', 'uuid'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'account_id' => ['nullable', 'uuid'],
            'party_id' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return $this->read($book, fn ($b) => app(AccountingReports::class)->run($b, $d, $report === 'ledger'));
    }
}
