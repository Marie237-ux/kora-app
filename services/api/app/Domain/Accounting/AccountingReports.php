<?php

namespace App\Domain\Accounting;

use Brick\Math\BigInteger;
use Illuminate\Support\Facades\DB;

/** Read inside ReadSnapshot. Amounts are decimal strings; report totals may exceed one-entry limits. */
final class AccountingReports
{
    public function run(object $book, array $data, bool $ledger): array
    {
        $year = app(AccountingBooks::class)->row('accounting_years', $data['year_id'], $book);
        if ($data['from'] < $year->starts_on || $data['to'] > $year->ends_on) {
            AccountingBooks::fail('dates', 'CALENDAR_INVALID');
        }
        if (isset($data['account_id'])) {
            app(AccountingBooks::class)->row('accounting_accounts', $data['account_id'], $book);
        }
        $query = DB::table('accounting_entry_lines AS l')
            ->join('accounting_entries AS e', 'e.id', '=', 'l.entry_id')
            ->where('e.book_id', $book->id)
            ->where('e.year_id', $year->id)
            ->where('e.status', 'posted')
            ->where('e.entry_date', '<=', $data['to'])
            ->when(isset($data['account_id']), fn ($q) => $q->where('l.account_id', $data['account_id']))
            ->orderBy('e.entry_date')
            ->orderBy('e.posted_at')
            ->orderBy('e.number')
            ->orderBy('e.id')
            ->orderBy('l.position')
            ->select('l.*', 'e.number', 'e.entry_date', 'e.reference', 'e.journal_id');
        $accounts = [];
        $rows = [];
        $opening = BigInteger::zero();
        $running = BigInteger::zero();
        $debit = BigInteger::zero();
        $credit = BigInteger::zero();
        $count = 0;
        $page = (int) ($data['page'] ?? 1);
        foreach ($query->cursor() as $line) {
            $party = $line->party ? json_decode($line->party, true) : null;
            if (isset($data['party_id']) && ($party['id'] ?? null) !== $data['party_id']) {
                continue;
            }
            $d = BigInteger::of((string) $line->debit);
            $c = BigInteger::of((string) $line->credit);
            $net = $d->minus($c);
            $accounts[$line->account_id] ??= [
                'account' => json_decode($line->account_snapshot, true),
                'opening' => BigInteger::zero(),
                'debit' => BigInteger::zero(),
                'credit' => BigInteger::zero(),
            ];
            $a = &$accounts[$line->account_id];
            if ($line->entry_date < $data['from']) {
                $opening = $opening->plus($net);
                $running = $running->plus($net);
                $a['opening'] = $a['opening']->plus($net);
            } else {
                $debit = $debit->plus($d);
                $credit = $credit->plus($c);
                $running = $running->plus($net);
                $a['debit'] = $a['debit']->plus($d);
                $a['credit'] = $a['credit']->plus($c);
                if ($ledger && $count >= ($page - 1) * 24 && $count < $page * 24) {
                    $rows[] = [
                        'entry_id' => $line->entry_id,
                        'position' => (int) $line->position,
                        'number' => $line->number,
                        'entry_date' => $line->entry_date,
                        'journal_id' => $line->journal_id,
                        'reference' => $line->reference,
                        'account' => json_decode($line->account_snapshot, true),
                        'party' => $party,
                        'debit' => (string) $d,
                        'credit' => (string) $c,
                        'running_balance' => (string) $running,
                    ];
                }
                $count++;
            }
            unset($a);
        }
        if (! $ledger) {
            uasort($accounts, fn ($a, $b) => strcmp($a['account']['code'], $b['account']['code']));
            foreach ($accounts as $a) {
                $rows[] = [
                    'account' => $a['account'],
                    'opening_balance' => (string) $a['opening'],
                    'debit' => (string) $a['debit'],
                    'credit' => (string) $a['credit'],
                    'closing_balance' => (string) $a['opening']->plus($a['debit'])->minus($a['credit']),
                ];
            }
        }

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'provisional' => true,
            'year_id' => $year->id,
            'from' => $data['from'],
            'to' => $data['to'],
            'opening_scope' => 'within_selected_year',
            'account_id' => $data['account_id'] ?? null,
            'party_id' => $data['party_id'] ?? null,
            'rows' => $rows,
            'opening_balance' => (string) $opening,
            'debit' => (string) $debit,
            'credit' => (string) $credit,
            'closing_balance' => (string) $opening->plus($debit)->minus($credit),
            'meta' => $ledger
                ? ['page' => $page, 'last_page' => max(1, (int) ceil($count / 24)), 'total' => $count]
                : null,
            'known_unposted_sources' => DB::table('accounting_sources')
                ->where('book_id', $book->id)
                ->whereNull('posted_entry_id')
                ->count(),
            'integration_coverage' => $book->capture_from ? 'configured_supported_families' : 'explicit_capture_only',
            'capture_coverage' => $book->capture_from && $book->capture_from <= $data['to']
                    ? app(AccountingSourceCatalog::class)->coverage(
                        $book,
                        max($book->capture_from, $data['from']),
                        $data['to'],
                    )
                    : null,
            'regulatory_certification' => false,
        ];
    }
}
