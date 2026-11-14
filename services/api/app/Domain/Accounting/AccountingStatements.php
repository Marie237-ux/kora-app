<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Models\User;
use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/**
 * Income statement and balance sheet computed from posted entries only, through a layout the company writes and
 * its accountant examines (which account prefixes feed which line). Nothing is filled in to make a statement
 * look complete: accounts in scope that no line covers are listed, and the statement stays provisional while a
 * period is open, a known source is unposted, a bank line is unmatched or the layout/chart is not examined.
 */
final class AccountingStatements
{
    public const KINDS = ['income_statement', 'balance_sheet'];

    public function saveLayout(object $book, string $kind, array $data, User $actor): array
    {
        $lines = $this->validate($kind, $data);
        DB::table('accounting_books')->where('id', $book->id)->lockForUpdate()->first();
        $version =
            (int) DB::table('accounting_statement_layouts')
                ->where('book_id', $book->id)
                ->where('kind', $kind)
                ->max('version') + 1;
        $content = ['scope' => array_values(array_unique($data['scope'])), 'lines' => $lines];
        $id = Records::id();
        DB::table('accounting_statement_layouts')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'kind' => $kind,
            'version' => $version,
            'label' => Records::json($data['label']),
            'lines' => Records::json($content),
            'sha256' => hash('sha256', json_encode($content)),
            'chart_version' => $book->chart_version,
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'accounting.layout_saved', $id, ['kind' => $kind, 'version' => $version]);

        return $this->layoutDto(Records::row('accounting_statement_layouts', $id, false));
    }

    public function reviewLayout(object $book, string $id, array $data, User $actor): array
    {
        $layout = app(AccountingBooks::class)->row('accounting_statement_layouts', $id, $book);
        abort_unless(hash_equals($layout->sha256, $data['sha256']), 409);
        if ($layout->reviewed_at) {
            AccountingBooks::fail('layout', 'ALREADY_REVIEWED');
        }
        DB::table('accounting_statement_layouts')
            ->where('id', $id)
            ->update(['reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_note' => $data['note']]);
        Records::audit($actor, 'accounting.layout_reviewed', $id, ['note' => $data['note']]);

        return $this->layoutDto(Records::row('accounting_statement_layouts', $id, false));
    }

    /** Line codes, account prefixes without overlap between lines, totals of earlier lines only. */
    private function validate(string $kind, array $data): array
    {
        $codes = [];
        $prefixes = [];
        $lines = [];
        foreach ($data['lines'] as $i => $line) {
            $field = "lines.$i";
            if (isset($codes[$line['code']])) {
                AccountingBooks::fail($field.'.code', 'LAYOUT_CODE_DUPLICATE');
            }
            if ($line['type'] === 'accounts') {
                if ($kind === 'balance_sheet' && ! in_array($line['side'] ?? null, ['assets', 'liabilities'], true)) {
                    AccountingBooks::fail($field.'.side', 'LAYOUT_SIDE_REQUIRED');
                }
                $own = array_values(array_unique($line['accounts'] ?? []));
                if (! $own) {
                    AccountingBooks::fail($field.'.accounts', 'LAYOUT_ACCOUNTS_REQUIRED');
                }
                foreach ($own as $prefix) {
                    foreach ($prefixes as $other => $owner) {
                        if (str_starts_with($prefix, (string) $other) || str_starts_with((string) $other, $prefix)) {
                            // An account counted in two lines would be counted twice in a total.
                            AccountingBooks::fail($field.'.accounts', 'LAYOUT_ACCOUNT_OVERLAP');
                        }
                    }
                }
                foreach ($own as $prefix) {
                    $prefixes[$prefix] = $line['code'];
                }
                $lines[] = [
                    'code' => $line['code'],
                    'label' => $line['label'],
                    'type' => 'accounts',
                    'accounts' => $own,
                    'sign' => $line['sign'],
                    'side' => $kind === 'balance_sheet' ? $line['side'] : null,
                ];
            } else {
                $components = $line['components'] ?? [];
                if (! $components) {
                    AccountingBooks::fail($field.'.components', 'LAYOUT_COMPONENTS_REQUIRED');
                }
                foreach ($components as $component) {
                    if (! isset($codes[$component['code']])) {
                        AccountingBooks::fail($field.'.components', 'LAYOUT_COMPONENT_UNKNOWN');
                    }
                }
                $lines[] = [
                    'code' => $line['code'],
                    'label' => $line['label'],
                    'type' => 'total',
                    'components' => array_map(
                        fn ($c) => ['code' => $c['code'], 'factor' => (int) $c['factor']],
                        $components,
                    ),
                ];
            }
            $codes[$line['code']] = true;
        }

        return $lines;
    }

    public function layout(object $book, string $kind): ?object
    {
        return DB::table('accounting_statement_layouts')
            ->where('book_id', $book->id)
            ->where('kind', $kind)
            ->orderByRaw('CASE WHEN reviewed_at IS NULL THEN 1 ELSE 0 END')
            ->orderByDesc('version')
            ->first();
    }

    public function compute(object $book, string $kind, array $data): array
    {
        $layout = $this->layout($book, $kind);
        if (! $layout) {
            AccountingBooks::fail('layout', 'LAYOUT_MISSING');
        }
        $year = app(AccountingBooks::class)->row('accounting_years', $data['year_id'], $book);
        if ($data['from'] < $year->starts_on || $data['to'] > $year->ends_on) {
            AccountingBooks::fail('dates', 'CALENDAR_INVALID');
        }
        $content = json_decode($layout->lines, true);
        // Income statement: movements of the period. Balance sheet: position at the end date, from the start of
        // the chosen year (no automatic carry-over from an earlier year, as in the trial balance).
        $from = $kind === 'income_statement' ? $data['from'] : $year->starts_on;
        $balances = [];
        $labels = [];
        $query = DB::table('accounting_entry_lines AS l')
            ->join('accounting_entries AS e', 'e.id', '=', 'l.entry_id')
            ->where('e.book_id', $book->id)
            ->where('e.year_id', $year->id)
            ->where('e.status', 'posted')
            ->whereBetween('e.entry_date', [$from, $data['to']])
            ->orderBy('l.entry_id')
            ->orderBy('l.position')
            ->select(['l.account_snapshot', 'l.debit', 'l.credit']);
        foreach ($query->cursor() as $line) {
            $account = json_decode($line->account_snapshot, true);
            $code = (string) $account['code'];
            $labels[$code] = $account['label'] ?? null;
            $balances[$code] = ($balances[$code] ?? BigInteger::zero())
                ->plus(BigInteger::of((string) $line->debit))
                ->minus(BigInteger::of((string) $line->credit));
        }
        $amounts = [];
        $covered = [];
        $rows = [];
        $sides = ['assets' => BigInteger::zero(), 'liabilities' => BigInteger::zero()];
        foreach ($content['lines'] as $line) {
            $amount = BigInteger::zero();
            if ($line['type'] === 'accounts') {
                foreach ($balances as $code => $balance) {
                    foreach ($line['accounts'] as $prefix) {
                        if (str_starts_with($code, $prefix)) {
                            $amount = $amount->plus($balance);
                            $covered[$code] = true;

                            break;
                        }
                    }
                }
                // Shown positive on its natural side: debit for charges/assets, credit for products/liabilities.
                if ($line['sign'] === 'credit') {
                    $amount = $amount->negated();
                }
                if ($line['side'] ?? null) {
                    $sides[$line['side']] = $sides[$line['side']]->plus($amount);
                }
            } else {
                foreach ($line['components'] as $component) {
                    $amount = $amount->plus($amounts[$component['code']]->multipliedBy($component['factor']));
                }
            }
            $amounts[$line['code']] = $amount;
            $rows[] = [
                'code' => $line['code'],
                'label' => $line['label'],
                'type' => $line['type'],
                'amount' => (string) $amount,
            ];
        }
        $unassigned = [];
        foreach ($balances as $code => $balance) {
            $inScope = (bool) array_filter($content['scope'], fn ($p) => str_starts_with((string) $code, $p));
            if ($inScope && ! isset($covered[$code]) && ! $balance->isZero()) {
                $unassigned[] = ['code' => (string) $code, 'label' => $labels[$code], 'balance' => (string) $balance];
            }
        }
        $reasons = $this->openings($book, $layout, $year, $data);

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'kind' => $kind,
            'currency' => 'XAF',
            'year_id' => $year->id,
            'from' => $from,
            'to' => $data['to'],
            'layout' => [
                'id' => $layout->id,
                'version' => (int) $layout->version,
                'label' => json_decode($layout->label, true),
                'reviewed' => $layout->reviewed_at !== null,
            ],
            'lines' => $rows,
            'sides' => $kind === 'balance_sheet'
                    ? [
                        'assets' => (string) $sides['assets'],
                        'liabilities' => (string) $sides['liabilities'],
                        // Not hidden: before the year is closed, the period result sits outside equity lines.
                        'difference' => (string) $sides['assets']->minus($sides['liabilities']),
                    ]
                    : null,
            'unassigned_accounts' => $unassigned,
            'status' => $reasons ? 'provisional' : 'definitive',
            'provisional_reasons' => $reasons,
            'regulatory_certification' => false,
        ];
    }

    /** Everything that keeps a statement provisional, stated rather than smoothed over. */
    private function openings(object $book, object $layout, object $year, array $data): array
    {
        $reasons = [];
        if (! $layout->reviewed_at) {
            $reasons[] = ['code' => 'layout_not_reviewed'];
        }
        if ((int) $book->reviewed_chart_version !== (int) $book->chart_version) {
            $reasons[] = ['code' => 'chart_not_reviewed'];
        }
        $open = DB::table('accounting_periods')
            ->where('book_id', $book->id)
            ->where('year_id', $year->id)
            ->where('starts_on', '<=', $data['to'])
            ->where('ends_on', '>=', $data['from'])
            ->where('status', '!=', 'closed')
            ->count();
        $covered = DB::table('accounting_periods')
            ->where('book_id', $book->id)
            ->where('year_id', $year->id)
            ->where('starts_on', '<=', $data['to'])
            ->where('ends_on', '>=', $data['from'])
            ->exists();
        if ($open || ! $covered) {
            $reasons[] = ['code' => 'periods_open', 'count' => $open];
        }
        $unposted = DB::table('accounting_sources')
            ->where('book_id', $book->id)
            ->whereNull('posted_entry_id')
            ->whereBetween('event_date', [$data['from'], $data['to']])
            ->count();
        if ($unposted) {
            $reasons[] = ['code' => 'unposted_sources', 'count' => $unposted];
        }
        $unmatched = DB::table('treasury_statement_lines AS sl')
            ->join('treasury_statements AS s', 's.id', '=', 'sl.statement_id')
            ->where('s.book_id', $book->id)
            ->whereBetween('sl.booked_on', [$data['from'], $data['to']])
            ->whereNotExists(
                fn ($q) => $q
                    ->selectRaw('1')
                    ->from('treasury_match_rows AS r')
                    ->whereColumn('r.statement_line_id', 'sl.id')
                    ->whereNotNull('r.active_slot'),
            )
            ->count();
        if ($unmatched) {
            $reasons[] = ['code' => 'unreconciled_statement_lines', 'count' => $unmatched];
        }

        return $reasons;
    }

    public function layoutDto(object $l): array
    {
        $content = json_decode($l->lines, true);

        return [
            'id' => $l->id,
            'kind' => $l->kind,
            'version' => (int) $l->version,
            'label' => json_decode($l->label, true),
            'scope' => $content['scope'],
            'lines' => $content['lines'],
            'sha256' => $l->sha256,
            'chart_version' => (int) $l->chart_version,
            'reviewed_at' => Records::iso($l->reviewed_at),
            'review_note' => $l->review_note,
            'created_at' => Records::iso($l->created_at),
        ];
    }
}
