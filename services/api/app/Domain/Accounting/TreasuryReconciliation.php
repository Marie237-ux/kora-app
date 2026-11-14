<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Mutations share the book lock with posting, calendar closing, imports and reviews. */
final class TreasuryReconciliation
{
    private function balance(string $value): string
    {
        if (! preg_match('/^(0|-?[1-9][0-9]{0,35})$/D', $value)) {
            AccountingBooks::fail('balance', 'TREASURY_BALANCE');
        }

        return $value;
    }

    private function openDates(object $book, string $from, string $to): void
    {
        $first = app(AccountingCalendar::class)->period($book, $from);
        $last = app(AccountingCalendar::class)->period($book, $to);
        if (
            $first->year_id !== $last->year_id ||
            DB::table('accounting_periods')
                ->where('book_id', $book->id)
                ->where('starts_on', '<=', $to)
                ->where('ends_on', '>=', $from)
                ->where('status', 'closed')
                ->exists()
        ) {
            AccountingBooks::fail('dates', 'PERIOD_CLOSED');
        }
        $next = $from;
        foreach (
            DB::table('accounting_periods')
                ->where('year_id', $first->year_id)
                ->where('starts_on', '<=', $to)
                ->where('ends_on', '>=', $from)
                ->orderBy('starts_on')
                ->get() as $period
        ) {
            if ($period->starts_on > $next) {
                AccountingBooks::fail('dates', 'PERIOD_REQUIRED');
            }
            $next = CarbonImmutable::parse($period->ends_on)->addDay()->toDateString();
        }
        if ($next <= $to) {
            AccountingBooks::fail('dates', 'PERIOD_REQUIRED');
        }
    }

    public function import(object $book, array $data, User $actor): array
    {
        $binding = app(TreasuryBindings::class)->usable($book, $data['binding_id']);
        $this->openDates($book, $data['starts_on'], $data['ends_on']);
        $opening = $this->balance($data['opening_balance']);
        $closing = $this->balance($data['closing_balance']);
        $document = app(PrivateDocuments::class)->requireClean($data['document_id'], null, ['accounting_evidence']);
        $previous = DB::table('treasury_statements')
            ->where('book_id', $book->id)
            ->where('treasury_account_id', $binding->treasury_account_id)
            ->where('status', '!=', 'void')
            ->orderByDesc('ends_on')
            ->first();
        if (
            $previous &&
            ($previous->status !== 'closed' ||
                CarbonImmutable::parse($previous->ends_on)->addDay()->toDateString() !== $data['starts_on'] ||
                $previous->closing_balance !== $opening)
        ) {
            AccountingBooks::fail('starts_on', 'TREASURY_CONTINUITY');
        }
        if (
            DB::table('treasury_statements')
                ->where('book_id', $book->id)
                ->where('treasury_account_id', $binding->treasury_account_id)
                ->where('reference', $data['reference'])
                ->where('status', '!=', 'void')
                ->exists()
        ) {
            AccountingBooks::fail('reference', 'TREASURY_DUPLICATE');
        }
        $net = BigInteger::zero();
        $lines = [];
        foreach ($data['lines'] as $line) {
            $amount = Money::parse($line['amount']);
            if ($amount === 0 || $line['booked_on'] < $data['starts_on'] || $line['booked_on'] > $data['ends_on']) {
                AccountingBooks::fail('lines', 'TREASURY_ROW');
            }
            $net = $net->plus($line['direction'] === 'in' ? $amount : -$amount);
            $lines[] = [...$line, 'amount' => (string) $amount];
        }
        if (! BigInteger::of($opening)->plus($net)->isEqualTo($closing)) {
            AccountingBooks::fail('closing_balance', 'TREASURY_TOTAL');
        }
        $id = Records::id();
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'binding' => app(TreasuryBindings::class)->dto($binding),
            'reference' => $data['reference'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'input_method' => 'manual_transcription',
            'lines' => $lines,
        ];
        DB::table('treasury_statements')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'binding_id' => $binding->id,
            'treasury_account_id' => $binding->treasury_account_id,
            'reference' => $data['reference'],
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'],
            'opening_balance' => $opening,
            'closing_balance' => $closing,
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
            'status' => 'importing',
        ]);
        foreach ($lines as $position => $line) {
            DB::table('treasury_statement_lines')->insert([
                'id' => Records::id(),
                'statement_id' => $id,
                'position' => $position + 1,
                ...$line,
            ]);
        }
        DB::table('treasury_statements')
            ->where('id', $id)
            ->update(['status' => 'open']);
        Records::audit($actor, 'treasury.statement_imported', $id, [
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
        ]);

        return $this->dto(Records::row('treasury_statements', $id, false));
    }

    public function ledger(object $statement)
    {
        $binding = Records::row('treasury_bindings', $statement->binding_id, false);

        return DB::table('accounting_entry_lines as l')
            ->join('accounting_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('e.book_id', $statement->book_id)
            ->where('e.status', 'posted')
            ->where('l.account_id', $binding->account_id)
            ->where(function ($q) use ($statement) {
                $q->whereBetween('e.entry_date', [$statement->starts_on, $statement->ends_on])->orWhereIn(
                    'l.id',
                    app(TreasuryOutstanding::class)->ids($statement, 'ledger'),
                );
            })
            ->select(['l.*', 'e.number', 'e.entry_date', 'e.reference', 'e.sha256 as entry_sha256']);
    }

    public function ledgerDto(object $row, object $statement): array
    {
        $binding = Records::row('treasury_bindings', $statement->binding_id, false);

        return [
            'id' => (string) $row->id,
            'entry_id' => $row->entry_id,
            'number' => $row->number,
            'entry_date' => $row->entry_date,
            'reference' => $row->reference,
            'entry_sha256' => $row->entry_sha256,
            'debit' => (string) $row->debit,
            'credit' => (string) $row->credit,
            'net_amount' => (string) $this->net($row, $binding),
            'match_id' => DB::table('treasury_match_ledger')
                ->where('ledger_line_id', $row->id)
                ->where('active_slot', 1)
                ->value('match_id'),
        ];
    }

    private function net(object $line, object $binding): BigInteger
    {
        $net = BigInteger::of($line->debit)->minus($line->credit);

        return $binding->inflow_side === 'credit' ? $net->negated() : $net;
    }

    public function mutable(object $book, object $statement, int $version): void
    {
        abort_unless((int) $statement->version === $version, 409);
        if ($statement->status !== 'open') {
            AccountingBooks::fail('status', 'TREASURY_STATE');
        }
        $this->openDates($book, $statement->starts_on, $statement->ends_on);
    }

    public function match(object $book, object $statement, array $data, User $actor): array
    {
        $this->mutable($book, $statement, $data['version']);
        app(TreasuryOutstanding::class)->guardMatching(
            $statement,
            $data['statement_line_ids'],
            $data['ledger_line_ids'],
        );
        $rows = app(TreasuryOutstanding::class)->rows($statement)->whereIn('id', $data['statement_line_ids'])->get();
        $ledger = $this->ledger($statement)->whereIn('l.id', $data['ledger_line_ids'])->get();
        if (
            $rows->count() !== count($data['statement_line_ids']) ||
            $ledger->count() !== count($data['ledger_line_ids'])
        ) {
            AccountingBooks::fail('lines', 'TREASURY_MATCH_SCOPE');
        }
        if (
            DB::table('treasury_match_rows')
                ->whereIn('statement_line_id', $data['statement_line_ids'])
                ->where('active_slot', 1)
                ->exists() ||
            DB::table('treasury_match_ledger')
                ->whereIn('ledger_line_id', $data['ledger_line_ids'])
                ->where('active_slot', 1)
                ->exists()
        ) {
            AccountingBooks::fail('lines', 'TREASURY_ALREADY_MATCHED');
        }
        $rowNet = BigInteger::zero();
        $ledgerNet = BigInteger::zero();
        $binding = Records::row('treasury_bindings', $statement->binding_id, false);
        foreach ($rows as $r) {
            $rowNet = $rowNet->plus($r->direction === 'in' ? $r->amount : '-'.$r->amount);
        }
        foreach ($ledger as $l) {
            $ledgerNet = $ledgerNet->plus($this->net($l, $binding));
        }
        if (! $rowNet->isEqualTo($ledgerNet)) {
            AccountingBooks::fail('lines', 'TREASURY_MATCH_TOTAL');
        }
        $id = Records::id();
        DB::table('treasury_matches')->insert([
            'id' => $id,
            'statement_id' => $statement->id,
            'net_amount' => (string) $rowNet,
            'note' => $data['note'],
            'created_by' => $actor->id,
            'created_at' => now(),
            'status' => 'assembling',
        ]);
        foreach ($rows as $row) {
            DB::table('treasury_match_rows')->insert(['match_id' => $id, 'statement_line_id' => $row->id]);
        }
        foreach ($ledger as $line) {
            DB::table('treasury_match_ledger')->insert(['match_id' => $id, 'ledger_line_id' => $line->id]);
        }
        DB::table('treasury_matches')
            ->where('id', $id)
            ->update(['status' => 'active']);
        $this->bump($statement);
        Records::audit($actor, 'treasury.matched', $id, ['note' => $data['note'], 'statement_id' => $statement->id]);

        return $this->matchDto(Records::row('treasury_matches', $id, false));
    }

    public function unmatch(object $book, object $statement, string $id, array $data, User $actor): array
    {
        $this->mutable($book, $statement, $data['version']);
        $match = Records::row('treasury_matches', $id, false);
        abort_unless($match->statement_id === $statement->id, 404);
        if ($match->status !== 'active') {
            AccountingBooks::fail('status', 'TREASURY_STATE');
        }
        DB::table('treasury_matches')
            ->where('id', $id)
            ->update(['status' => 'void']);
        foreach (['treasury_match_rows', 'treasury_match_ledger'] as $table) {
            DB::table($table)
                ->where('match_id', $id)
                ->update(['active_slot' => null]);
        }
        $this->bump($statement);
        Records::audit($actor, 'treasury.unmatched', $id, ['note' => $data['note'], 'statement_id' => $statement->id]);

        return $this->matchDto(Records::row('treasury_matches', $id, false));
    }

    private function bump(object $statement, array $changes = []): void
    {
        DB::table('treasury_statements')
            ->where('id', $statement->id)
            ->update(['version' => $statement->version + 1, ...$changes]);
    }

    public function transition(object $book, object $statement, string $action, array $data, User $actor): array
    {
        abort_unless((int) $statement->version === $data['version'], 409);
        $this->openDates($book, $statement->starts_on, $statement->ends_on);
        if ($statement->status !== ($action === 'reopen' ? 'closed' : 'open')) {
            AccountingBooks::fail('status', 'TREASURY_STATE');
        }
        $later = DB::table('treasury_statements')
            ->where('book_id', $book->id)
            ->where('treasury_account_id', $statement->treasury_account_id)
            ->where('starts_on', '>', $statement->ends_on)
            ->where('status', '!=', 'void');
        if (
            ($action === 'void' && $later->exists()) ||
            ($action === 'reopen' && (clone $later)->where('status', 'closed')->exists())
        ) {
            AccountingBooks::fail('status', 'TREASURY_SUCCESSOR');
        }
        if ($action === 'close') {
            $doc = app(PrivateDocuments::class)->requireClean($statement->document_id, null, ['accounting_evidence']);
            $snapshot = json_decode($statement->snapshot, true);
            if (! hash_equals($doc->sha256, $snapshot['document']['sha256'])) {
                AccountingBooks::fail('document_id', 'SOURCE_CHANGED');
            }
            $earlierOpen = DB::table('treasury_statements')
                ->where('book_id', $book->id)
                ->where('treasury_account_id', $statement->treasury_account_id)
                ->where('ends_on', '<', $statement->starts_on)
                ->where('status', 'open')
                ->exists();
            $totals = $this->totals($statement);
            $outstanding = app(TreasuryOutstanding::class)->forClosure($statement);
            if ($earlierOpen || $totals['unresolved_statement_lines'] || $totals['unresolved_ledger_lines']) {
                AccountingBooks::fail('status', 'TREASURY_UNRESOLVED');
            }
            app(TreasuryOutstanding::class)->saveClosure(
                $statement,
                $totals,
                $outstanding,
                $actor,
                app(TreasuryCoverage::class)->forStatement($statement, $totals),
            );
        }
        if ($action === 'reopen') {
            app(TreasuryOutstanding::class)->guardReopening($later);
        }
        if (
            $action === 'void' &&
            (DB::table('treasury_matches')
                ->where('statement_id', $statement->id)
                ->where('status', 'active')
                ->exists() ||
                app(TreasuryOutstanding::class)->active($statement)->exists())
        ) {
            AccountingBooks::fail('status', 'TREASURY_UNRESOLVED');
        }
        $this->bump($statement, [
            'status' => $action === 'close' ? 'closed' : ($action === 'void' ? 'void' : 'open'),
            ...$action === 'void' ? ['active_slot' => null] : [],
        ]);
        Records::audit($actor, 'treasury.statement_'.$action, $statement->id, [
            'note' => $data['note'],
            'version' => $statement->version + 1,
            'sha256' => $statement->sha256,
            ...$action === 'close' ? ['totals' => $this->totals($statement)] : [],
        ]);

        return $this->dto(Records::row('treasury_statements', $statement->id, false));
    }

    public function totals(object $statement): array
    {
        $service = app(TreasuryOutstanding::class);
        if ($statement->status === 'closed' && ($closure = $service->closure($statement))) {
            return json_decode($closure->snapshot, true)['totals'];
        }
        $rows = $service
            ->rows($statement)
            ->whereNotIn('id', DB::table('treasury_match_rows')->where('active_slot', 1)->select('statement_line_id'))
            ->get();
        $ledger = $this->ledger($statement)->whereNotIn(
            'l.id',
            DB::table('treasury_match_ledger')->where('active_slot', 1)->select('ledger_line_id'),
        );
        $rowNet = BigInteger::zero();
        $ledgerNet = BigInteger::zero();
        $binding = Records::row('treasury_bindings', $statement->binding_id, false);
        $ledgerCount = 0;
        $unresolvedRows = 0;
        $unresolvedLedger = 0;
        $outstandingRows = BigInteger::zero();
        $outstandingLedger = BigInteger::zero();
        $approved = $service->active($statement)->where('status', 'approved')->get();
        $rowIds = $approved->whereNotNull('statement_line_id')->pluck('statement_line_id')->all();
        $ledgerIds = $approved
            ->whereNotNull('ledger_line_id')
            ->pluck('ledger_line_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        foreach ($rows as $r) {
            $net = $r->direction === 'in' ? $r->amount : '-'.$r->amount;
            $rowNet = $rowNet->plus($net);
            if (in_array($r->id, $rowIds, true)) {
                $outstandingRows = $outstandingRows->plus($net);
            } else {
                $unresolvedRows++;
            }
        }
        foreach ($ledger->cursor() as $l) {
            $ledgerCount++;
            $ledgerNet = $ledgerNet->plus($this->net($l, $binding));
            if (in_array((string) $l->id, $ledgerIds, true)) {
                $outstandingLedger = $outstandingLedger->plus($this->net($l, $binding));
            } else {
                $unresolvedLedger++;
            }
        }

        return [
            'unmatched_statement_lines' => $rows->count(),
            'unmatched_ledger_lines' => $ledgerCount,
            'unmatched_statement_net' => (string) $rowNet,
            'unmatched_ledger_net' => (string) $ledgerNet,
            'unresolved_statement_lines' => $unresolvedRows,
            'unresolved_ledger_lines' => $unresolvedLedger,
            'outstanding_statement_net' => (string) $outstandingRows,
            'outstanding_ledger_net' => (string) $outstandingLedger,
            'timing_net_difference' => (string) $outstandingLedger->minus($outstandingRows),
            'pending_decisions' => $service->active($statement)->where('status', 'pending')->count(),
            'available_funds' => null,
            'availability_verified' => false,
            'scope' => 'registered_statement_movements',
            'provisional' => true,
        ];
    }

    public function dto(object $statement): array
    {
        return [
            'id' => $statement->id,
            'book_id' => $statement->book_id,
            'binding_id' => $statement->binding_id,
            'treasury_account_id' => $statement->treasury_account_id,
            'currency' => 'XAF',
            'reference' => $statement->reference,
            'starts_on' => $statement->starts_on,
            'ends_on' => $statement->ends_on,
            'opening_balance' => $statement->opening_balance,
            'closing_balance' => $statement->closing_balance,
            'document_id' => $statement->document_id,
            'snapshot' => json_decode($statement->snapshot, true),
            'sha256' => $statement->sha256,
            'status' => $statement->status,
            'version' => (int) $statement->version,
            'closure' => ($closure = app(TreasuryOutstanding::class)->closure($statement))
                ? app(TreasuryOutstanding::class)->closureDto($closure)
                : null,
            'reconciliation' => $this->totals($statement),
            'balance_reconciliation' => $closure ? json_decode($closure->snapshot, true)['balance'] ?? null : null,
        ];
    }

    public function rowDto(object $row): array
    {
        return [
            'id' => $row->id,
            'statement_id' => $row->statement_id,
            'position' => (int) $row->position,
            'external_id' => $row->external_id,
            'booked_on' => $row->booked_on,
            'reference' => $row->reference,
            'direction' => $row->direction,
            'amount' => (string) $row->amount,
            'match_id' => DB::table('treasury_match_rows')
                ->where('statement_line_id', $row->id)
                ->where('active_slot', 1)
                ->value('match_id'),
        ];
    }

    public function matchDto(object $row): array
    {
        return [
            'id' => $row->id,
            'statement_id' => $row->statement_id,
            'net_amount' => $row->net_amount,
            'status' => $row->status,
            'note' => $row->note,
            'created_by' => (int) $row->created_by,
            'created_at' => Records::iso($row->created_at),
            'statement_line_ids' => DB::table('treasury_match_rows')
                ->where('match_id', $row->id)
                ->orderBy('id')
                ->pluck('statement_line_id')
                ->all(),
            'ledger_line_ids' => DB::table('treasury_match_ledger')
                ->where('match_id', $row->id)
                ->orderBy('id')
                ->pluck('ledger_line_id')
                ->map(fn ($v) => (string) $v)
                ->all(),
        ];
    }

    public function guardPosting(object $book, object $entry): void
    {
        app(TreasuryCoverage::class)->guardPosting($book, $entry);
        if (
            DB::table('treasury_statements as s')
                ->join('treasury_bindings as b', 'b.id', '=', 's.binding_id')
                ->where('s.book_id', $book->id)
                ->where('s.status', 'closed')
                ->where('s.starts_on', '<=', $entry->entry_date)
                ->where('s.ends_on', '>=', $entry->entry_date)
                ->whereIn(
                    'b.account_id',
                    DB::table('accounting_entry_lines')->where('entry_id', $entry->id)->select('account_id'),
                )
                ->exists()
        ) {
            AccountingBooks::fail('entry_date', 'TREASURY_REOPEN_REQUIRED');
        }
    }

    public function guardCalendar(object $book, object $calendar): void
    {
        if (
            DB::table('treasury_statements')
                ->where('book_id', $book->id)
                ->where('status', 'open')
                ->where('starts_on', '<=', $calendar->ends_on)
                ->where('ends_on', '>=', $calendar->starts_on)
                ->exists()
        ) {
            AccountingBooks::fail('status', 'TREASURY_UNRESOLVED');
        }
    }
}
