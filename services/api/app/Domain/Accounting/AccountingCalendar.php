<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class AccountingCalendar
{
    public function create(object $book, ?string $yearId, array $data, User $actor): array
    {
        $table = $yearId ? 'accounting_periods' : 'accounting_years';
        if ($yearId) {
            $year = app(AccountingBooks::class)->row('accounting_years', $yearId, $book);
            if (
                $year->status !== 'open' ||
                $data['starts_on'] < $year->starts_on ||
                $data['ends_on'] > $year->ends_on
            ) {
                AccountingBooks::fail('dates', 'CALENDAR_INVALID');
            }
        }
        $overlap = DB::table($table)
            ->where('book_id', $book->id)
            ->where('starts_on', '<=', $data['ends_on'])
            ->where('ends_on', '>=', $data['starts_on']);
        if ($overlap->exists()) {
            AccountingBooks::fail('dates', 'CALENDAR_OVERLAP');
        }
        $id = Records::id();
        DB::table($table)->insert([
            'id' => $id,
            'book_id' => $book->id,
            ...$yearId ? ['year_id' => $yearId] : [],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'label' => Records::json($data['label']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'accounting.calendar_created', $id, ['kind' => $yearId ? 'period' : 'year']);

        return $this->dto(Records::row($table, $id, false));
    }

    public function period(object $book, string $date): object
    {
        $period = DB::table('accounting_periods')
            ->where('book_id', $book->id)
            ->where('starts_on', '<=', $date)
            ->where('ends_on', '>=', $date)
            ->first();
        if (! $period) {
            AccountingBooks::fail('entry_date', 'PERIOD_REQUIRED');
        }
        $year = Records::row('accounting_years', $period->year_id, false);
        if ($period->status !== 'open' || $year->status !== 'open') {
            AccountingBooks::fail('entry_date', 'PERIOD_CLOSED');
        }

        return $period;
    }

    public function transition(object $book, string $id, bool $year, bool $reopen, array $data, User $actor): array
    {
        $table = $year ? 'accounting_years' : 'accounting_periods';
        $row = app(AccountingBooks::class)->row($table, $id, $book);
        abort_unless((int) $row->version === $data['version'], 409);
        if ($row->status !== ($reopen ? 'closed' : 'open')) {
            AccountingBooks::fail('status', 'CALENDAR_STATE_INVALID');
        }
        if ($reopen && ! $year && Records::row('accounting_years', $row->year_id, false)->status !== 'open') {
            AccountingBooks::fail('year', 'PERIOD_CLOSED');
        }
        $treasuryCoverage = [];
        if (! $reopen) {
            app(TreasuryReconciliation::class)->guardCalendar($book, $row);
            $treasuryCoverage = app(TreasuryCoverage::class)->guardCalendar($book, $row);
            // Once configured, disabling the worker cannot erase the obligation to account for known facts.
            if ($book->capture_from && $book->capture_from <= $row->ends_on) {
                $coverage = app(AccountingSourceCatalog::class)->coverage(
                    $book,
                    max($book->capture_from, $row->starts_on),
                    $row->ends_on,
                );
                if ($coverage['unresolved'] > 0 || $coverage['capture_issues'] > 0) {
                    AccountingBooks::fail('status', 'CAPTURE_UNRESOLVED');
                }
            }
            $drafts = DB::table('accounting_entries')
                ->where('book_id', $book->id)
                ->whereBetween('entry_date', [$row->starts_on, $row->ends_on])
                ->where('status', 'draft')
                ->exists();
            $sources = DB::table('accounting_sources')
                ->where('book_id', $book->id)
                ->whereBetween('event_date', [$row->starts_on, $row->ends_on])
                ->whereNull('posted_entry_id')
                ->exists();
            if ($drafts || $sources) {
                AccountingBooks::fail('status', 'UNPOSTED_WORK');
            }
            if ($year) {
                $periods = DB::table('accounting_periods')->where('year_id', $id)->orderBy('starts_on')->get();
                $next = $row->starts_on;
                foreach ($periods as $p) {
                    if ($p->status !== 'closed' || $p->starts_on !== $next) {
                        AccountingBooks::fail('periods', 'YEAR_NOT_READY');
                    }
                    $next = CarbonImmutable::parse($p->ends_on)->addDay()->toDateString();
                }
                if ($periods->isEmpty() || $next !== CarbonImmutable::parse($row->ends_on)->addDay()->toDateString()) {
                    AccountingBooks::fail('periods', 'YEAR_NOT_READY');
                }
            }
        }
        DB::table($table)
            ->where('id', $id)
            ->update(['status' => $reopen ? 'open' : 'closed', 'version' => $row->version + 1, 'updated_at' => now()]);
        Records::audit($actor, $reopen ? 'accounting.reopened' : 'accounting.closed', $id, [
            'kind' => $year ? 'year' : 'period',
            'note' => $data['note'],
            'treasury_coverage' => $treasuryCoverage,
        ]);

        return $this->dto(Records::row($table, $id, false));
    }

    public function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'year_id' => $r->year_id ?? null,
            'label' => json_decode($r->label, true),
            'starts_on' => $r->starts_on,
            'ends_on' => $r->ends_on,
            'status' => $r->status,
            'version' => (int) $r->version,
        ];
    }
}
