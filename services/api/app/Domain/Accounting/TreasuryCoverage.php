<?php

namespace App\Domain\Accounting;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Explicit mandates and journal-derived balances; no opening amount or liquidity is invented. */
final class TreasuryCoverage
{
    public function current(string $book, string $treasury): ?object
    {
        return DB::table('treasury_coverage_policies')
            ->where('book_id', $book)
            ->where('treasury_account_id', $treasury)
            ->where('current_slot', 1)
            ->first();
    }

    private function ledger(object $statement, string $date, bool $inclusive): array
    {
        $binding = Records::row('treasury_bindings', $statement->binding_id, false);
        $balance = BigInteger::zero();
        $hash = hash_init('sha256');
        $count = 0;
        $lines = DB::table('accounting_entry_lines as l')
            ->join('accounting_entries as e', 'e.id', '=', 'l.entry_id')
            ->where('e.book_id', $statement->book_id)
            ->where('l.account_id', $binding->account_id)
            ->where('e.status', 'posted')
            ->where('e.entry_date', $inclusive ? '<=' : '<', $date)
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('l.position')
            ->select(['l.*', 'e.entry_date', 'e.sha256 as entry_sha256']);
        foreach ($lines->cursor() as $line) {
            $net = BigInteger::of($line->debit)->minus($line->credit);
            $balance = $balance->plus($binding->inflow_side === 'debit' ? $net : $net->negated());
            hash_update(
                $hash,
                Records::json([
                    (string) $line->id,
                    $line->entry_id,
                    $line->entry_sha256,
                    $line->entry_date,
                    (string) $line->debit,
                    (string) $line->credit,
                ])."\n",
            );
            $count++;
        }

        return ['balance' => (string) $balance, 'line_count' => $count, 'sha256' => hash_final($hash)];
    }

    private function opening(object $statement): array
    {
        $outstanding = app(TreasuryOutstanding::class);
        $outstanding->requirePreviousClosed($statement);
        $previous = $outstanding->previous($statement);
        $previousClosure = $previous ? $outstanding->closure($previous) : null;
        $ledger = $this->ledger($statement, $statement->starts_on, false);
        $ledgerPending = BigInteger::zero();
        $statementPending = BigInteger::zero();
        foreach ($outstanding->incoming($statement) as $item) {
            if ($item['side'] === 'ledger') {
                $ledgerPending = $ledgerPending->plus($item['net_amount']);
            } else {
                $statementPending = $statementPending->plus($item['net_amount']);
            }
        }
        $adjusted = BigInteger::of($statement->opening_balance)->plus($ledgerPending)->minus($statementPending);

        return [
            'from' => $statement->starts_on,
            'statement_id' => $statement->id,
            'statement_sha256' => $statement->sha256,
            'statement_balance' => $statement->opening_balance,
            'ledger' => $ledger,
            'outstanding_ledger_net' => (string) $ledgerPending,
            'outstanding_statement_net' => (string) $statementPending,
            'adjusted_statement_balance' => (string) $adjusted,
            'difference' => (string) BigInteger::of($ledger['balance'])->minus($adjusted),
            'previous_closure' => $previousClosure
                ? [
                    'id' => $previousClosure->id,
                    'sha256' => $previousClosure->sha256,
                    'version' => (int) $previousClosure->version,
                ]
                : null,
        ];
    }

    public function create(object $book, array $data, User $actor): array
    {
        $statement = app(AccountingBooks::class)->row('treasury_statements', $data['statement_id'], $book);
        if ($statement->status === 'void') {
            AccountingBooks::fail('statement_id', 'TREASURY_STATE');
        }
        $last =
            (int) (DB::table('treasury_coverage_policies')
                ->where('book_id', $book->id)
                ->where('treasury_account_id', $statement->treasury_account_id)
                ->max('version') ?? 0);
        abort_unless($last === $data['version'], 409);
        $current = $this->current($book->id, $statement->treasury_account_id);
        if ($current && $current->from_date !== $statement->starts_on) {
            AccountingBooks::fail('statement_id', 'TREASURY_COVERAGE_FROM_FIXED');
        }
        $document = app(PrivateDocuments::class)->requireClean($data['document_id'], null, ['accounting_evidence']);
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'binding' => app(TreasuryBindings::class)->dto(
                Records::row('treasury_bindings', $statement->binding_id, false),
            ),
            'from' => $statement->starts_on,
            'opening' => $this->opening($statement),
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'note' => $data['note'],
            'version' => $last + 1,
        ];
        $id = Records::id();
        DB::table('treasury_coverage_policies')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'treasury_account_id' => $statement->treasury_account_id,
            'binding_id' => $statement->binding_id,
            'statement_id' => $statement->id,
            'from_date' => $statement->starts_on,
            'version' => $last + 1,
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'treasury.coverage_created', $id, [
            'note' => $data['note'],
            'from' => $statement->starts_on,
        ]);

        return $this->dto(Records::row('treasury_coverage_policies', $id, false));
    }

    public function openingState(object $policy): array
    {
        $statement = Records::row('treasury_statements', $policy->statement_id, false);
        $snapshot = json_decode($policy->snapshot, true);
        $document = Records::row('private_documents', $policy->document_id, false);
        $previous = app(TreasuryOutstanding::class)->previous($statement);
        if ($statement->status === 'void' || ($previous && $previous->status !== 'closed')) {
            return ['valid' => false, 'reason' => 'anchor_unavailable', 'current' => null];
        }
        $opening = $this->opening($statement);
        if ($document->scan_status !== 'clean' || ! hash_equals($document->sha256, $snapshot['document']['sha256'])) {
            return ['valid' => false, 'reason' => 'evidence_changed', 'current' => $opening];
        }
        if (
            ! hash_equals(
                app(CommandBus::class)->fingerprint($opening),
                app(CommandBus::class)->fingerprint($snapshot['opening']),
            )
        ) {
            return ['valid' => false, 'reason' => 'opening_changed', 'current' => $opening];
        }

        return [
            'valid' => $opening['difference'] === '0',
            'reason' => $opening['difference'] === '0' ? null : 'opening_difference',
            'current' => $opening,
        ];
    }

    public function transition(object $book, string $id, string $action, array $data, User $actor): array
    {
        $policy = app(AccountingBooks::class)->row('treasury_coverage_policies', $id, $book);
        if ($policy->status !== 'pending') {
            AccountingBooks::fail('status', 'TREASURY_STATE');
        }
        abort_unless((int) $policy->version === $data['version'], 409);
        if ($action === 'review') {
            $last = DB::table('treasury_coverage_policies')
                ->where('book_id', $book->id)
                ->where('treasury_account_id', $policy->treasury_account_id)
                ->max('version');
            if (
                (int) $last !== (int) $policy->version ||
                ! hash_equals($policy->sha256, $data['sha256']) ||
                ($book->mode === 'live' && (int) $policy->created_by === $actor->id)
            ) {
                AccountingBooks::fail('sha256', 'TREASURY_COVERAGE_REVIEW');
            }
            $current = $this->current($book->id, $policy->treasury_account_id);
            if ($current && $current->from_date !== $policy->from_date) {
                AccountingBooks::fail('from', 'TREASURY_COVERAGE_FROM_FIXED');
            }
            foreach (['accounting_years', 'accounting_periods'] as $table) {
                if (
                    DB::table($table)
                        ->where('book_id', $book->id)
                        ->where('ends_on', '>=', $policy->from_date)
                        ->where('status', 'closed')
                        ->exists()
                ) {
                    AccountingBooks::fail('dates', 'PERIOD_CLOSED');
                }
            }
            if ($this->closed($policy)->exists()) {
                AccountingBooks::fail('status', 'TREASURY_COVERAGE_REOPEN');
            }
            if (! $this->openingState($policy)['valid']) {
                AccountingBooks::fail('opening', 'TREASURY_COVERAGE_OPENING');
            }
            if ($current) {
                DB::table('treasury_coverage_policies')
                    ->where('id', $current->id)
                    ->update(['current_slot' => null]);
            }
            DB::table('treasury_coverage_policies')
                ->where('id', $id)
                ->update([
                    'status' => 'approved',
                    'current_slot' => 1,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ]);
        } else {
            DB::table('treasury_coverage_policies')
                ->where('id', $id)
                ->update(['status' => 'void']);
        }
        Records::audit($actor, 'treasury.coverage_'.$action, $id, [
            'note' => $data['note'],
            'sha256' => $policy->sha256,
        ]);

        return $this->dto(Records::row('treasury_coverage_policies', $id, false));
    }

    private function closed(object $policy)
    {
        return DB::table('treasury_statements')
            ->where('book_id', $policy->book_id)
            ->where('treasury_account_id', $policy->treasury_account_id)
            ->where('ends_on', '>=', $policy->from_date)
            ->where('status', 'closed');
    }

    public function forStatement(object $statement, array $totals): ?array
    {
        $policy = $this->current($statement->book_id, $statement->treasury_account_id);
        if (! $policy || $statement->ends_on < $policy->from_date) {
            return null;
        }
        if ($statement->starts_on < $policy->from_date || ! $this->openingState($policy)['valid']) {
            AccountingBooks::fail('opening', 'TREASURY_COVERAGE_OPENING');
        }
        $ledger = $this->ledger($statement, $statement->ends_on, true);
        $adjusted = BigInteger::of($statement->closing_balance)
            ->plus($totals['outstanding_ledger_net'])
            ->minus($totals['outstanding_statement_net']);
        $difference = BigInteger::of($ledger['balance'])->minus($adjusted);
        if (! $difference->isZero()) {
            AccountingBooks::fail('balance', 'TREASURY_COVERAGE_BALANCE');
        }

        return [
            'policy_id' => $policy->id,
            'policy_sha256' => $policy->sha256,
            'as_of' => $statement->ends_on,
            'ledger' => $ledger,
            'statement_balance' => $statement->closing_balance,
            'outstanding_ledger_net' => $totals['outstanding_ledger_net'],
            'outstanding_statement_net' => $totals['outstanding_statement_net'],
            'adjusted_statement_balance' => (string) $adjusted,
            'difference' => (string) $difference,
            'available_funds' => null,
            'availability_verified' => false,
            'provisional' => true,
        ];
    }

    public function report(object $book, string $treasury, string $to): array
    {
        Records::row('treasury_accounts', $treasury, false);
        $policy = $this->current($book->id, $treasury);
        $report = [
            'book_id' => $book->id,
            'treasury_account_id' => $treasury,
            'to' => $to,
            'configured' => $policy !== null,
            'from' => $policy?->from_date,
            'policy_id' => $policy?->id,
            'scope' => 'configured_treasury_account',
            'applicable' => $policy !== null && $policy->from_date <= $to,
            'complete' => false,
            'opening' => null,
            'gaps' => [],
            'unclosed' => [],
            'unverified_closures' => [],
            'verified_closures' => [],
            'closed_statements' => 0,
            'available_funds' => null,
            'availability_verified' => false,
            'provisional' => true,
        ];
        if (! $report['applicable']) {
            return $report;
        }
        $report['opening'] = $this->openingState($policy);
        $cursor = $policy->from_date;
        $statements = DB::table('treasury_statements')
            ->where('book_id', $book->id)
            ->where('treasury_account_id', $treasury)
            ->where('status', '!=', 'void')
            ->where('ends_on', '>=', $policy->from_date)
            ->where('starts_on', '<=', $to)
            ->orderBy('starts_on')
            ->get();
        foreach ($statements as $s) {
            if ($s->starts_on > $cursor) {
                $report['gaps'][] = [
                    'from' => $cursor,
                    'to' => CarbonImmutable::parse($s->starts_on)->subDay()->toDateString(),
                ];
            }
            if ($s->status !== 'closed') {
                $report['unclosed'][] = $s->id;
            } else {
                $closure = app(TreasuryOutstanding::class)->closure($s);
                $balance = $closure ? json_decode($closure->snapshot, true)['balance'] ?? null : null;
                if (! $balance || $balance['policy_id'] !== $policy->id || $balance['difference'] !== '0') {
                    $report['unverified_closures'][] = $s->id;
                } else {
                    $report['verified_closures'][] = [
                        'statement_id' => $s->id,
                        'statement_sha256' => $s->sha256,
                        'closure_id' => $closure->id,
                        'closure_sha256' => $closure->sha256,
                        'version' => (int) $closure->version,
                        'from' => $s->starts_on,
                        'to' => $s->ends_on,
                    ];
                }
                $report['closed_statements']++;
            }
            $cursor = max($cursor, CarbonImmutable::parse($s->ends_on)->addDay()->toDateString());
        }
        if ($cursor <= $to) {
            $report['gaps'][] = ['from' => $cursor, 'to' => $to];
        }
        $report['complete'] =
            $report['opening']['valid'] &&
            $report['gaps'] === [] &&
            $report['unclosed'] === [] &&
            $report['unverified_closures'] === [];

        return $report;
    }

    public function guardCalendar(object $book, object $calendar): array
    {
        $reports = [];
        foreach (
            DB::table('treasury_coverage_policies')
                ->where('book_id', $book->id)
                ->where('current_slot', 1)
                ->where('from_date', '<=', $calendar->ends_on)
                ->get() as $policy
        ) {
            $report = $this->report($book, $policy->treasury_account_id, $calendar->ends_on);
            if (! $report['complete']) {
                AccountingBooks::fail('status', 'TREASURY_COVERAGE_INCOMPLETE');
            }
            $reports[] = ['policy_id' => $policy->id, 'policy_sha256' => $policy->sha256, 'report' => $report];
        }

        return $reports;
    }

    public function guardPosting(object $book, object $entry): void
    {
        $policies = DB::table('treasury_coverage_policies as p')
            ->join('treasury_bindings as b', 'b.id', '=', 'p.binding_id')
            ->where('p.book_id', $book->id)
            ->where('p.current_slot', 1)
            ->where('p.from_date', '>', $entry->entry_date)
            ->whereIn(
                'b.account_id',
                DB::table('accounting_entry_lines')->where('entry_id', $entry->id)->select('account_id'),
            )
            ->select('p.*')
            ->get();
        foreach ($policies as $policy) {
            if ($this->closed($policy)->exists()) {
                AccountingBooks::fail('entry_date', 'TREASURY_COVERAGE_REOPEN');
            }
        }
    }

    public function dto(object $row): array
    {
        return [
            'id' => $row->id,
            'book_id' => $row->book_id,
            'treasury_account_id' => $row->treasury_account_id,
            'statement_id' => $row->statement_id,
            'binding_id' => $row->binding_id,
            'from' => $row->from_date,
            'version' => (int) $row->version,
            'snapshot' => json_decode($row->snapshot, true),
            'sha256' => $row->sha256,
            'status' => $row->status,
            'current' => $row->current_slot !== null,
            'created_by' => (int) $row->created_by,
            'reviewed_by' => $row->reviewed_by === null ? null : (int) $row->reviewed_by,
            'reviewed_at' => $row->reviewed_at ? Records::iso($row->reviewed_at) : null,
        ];
    }
}
