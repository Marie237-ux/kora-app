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

/** Book-locked manual provider withdrawal records. Never requests a transfer or certifies liquidity. */
final class Withdrawals
{
    private function evidence(string $id): object
    {
        return app(PrivateDocuments::class)->requireClean($id, null, ['accounting_evidence']);
    }

    private function dates(object $book, string $date, ?object $request = null): void
    {
        app(AccountingCalendar::class)->period($book, $date);
        if ($date > CarbonImmutable::now('Africa/Douala')->toDateString()) {
            AccountingBooks::fail('date', 'WITHDRAWAL_DATE');
        }
        if ($request) {
            $last = max($request->requested_on, $request->approved_on ?? '', $request->closed_on ?? '');
            foreach (DB::table('withdrawal_receipts')->where('withdrawal_id', $request->id)->get() as $r) {
                $last = max($last, $r->posted_on, $r->reversed_on ?? '');
            }
            if ($date < $last) {
                AccountingBooks::fail('date', 'WITHDRAWAL_DATE');
            }
        }
    }

    private function bindings(object $book, string $source, string $destination, ?object $request = null): array
    {
        $s = app(TreasuryBindings::class)->usable($book, $source);
        $d = app(TreasuryBindings::class)->usable($book, $destination);
        if (
            $s->treasury_account_id === $d->treasury_account_id ||
            $s->account_id === $d->account_id ||
            $s->inflow_side !== $d->inflow_side ||
            Records::row('treasury_accounts', $s->treasury_account_id, false)->kind !== 'monetbil' ||
            Records::row('treasury_accounts', $d->treasury_account_id, false)->kind === 'monetbil' ||
            ($request &&
                ($s->treasury_account_id !== $request->source_account_id ||
                    $d->treasury_account_id !== $request->destination_account_id))
        ) {
            AccountingBooks::fail('binding_id', 'WITHDRAWAL_BINDINGS');
        }

        return [$s, $d];
    }

    private function predecessor(
        string $table,
        object $book,
        string $source,
        string $reference,
        ?string $accepted,
    ): ?object {
        $last = DB::table($table)
            ->where('book_id', $book->id)
            ->where('source_account_id', $source)
            ->where('reference', $reference)
            ->orderByDesc('revision')
            ->first();
        if (
            (! $last && $accepted) ||
            ($last &&
                ($accepted !== $last->id ||
                    $last->reference_slot !== null ||
                    ! in_array($last->status, ['void', 'reversed'], true)))
        ) {
            AccountingBooks::fail('reference', 'WITHDRAWAL_REFERENCE');
        }

        return $last;
    }

    public function create(object $book, array $data, User $actor): array
    {
        [$s, $d] = $this->bindings($book, $data['source_binding_id'], $data['destination_binding_id']);
        $this->dates($book, $data['requested_on']);
        $at = CarbonImmutable::parse($data['requested_at'])->utc();
        if ($at->setTimezone('Africa/Douala')->toDateString() > $data['requested_on']) {
            AccountingBooks::fail('requested_at', 'WITHDRAWAL_DATE');
        }
        $amount = Money::parse($data['amount']);
        if ($amount < 1) {
            AccountingBooks::fail('amount', 'WITHDRAWAL_AMOUNT');
        }
        $reference = trim($data['reference']);
        $previous = $this->predecessor(
            'withdrawals',
            $book,
            $s->treasury_account_id,
            $reference,
            $data['replaces_id'] ?? null,
        );
        if ($previous && $data['requested_on'] < $previous->closed_on) {
            AccountingBooks::fail('requested_on', 'WITHDRAWAL_DATE');
        }
        $document = $this->evidence($data['document_id']);
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'source' => app(TreasuryBindings::class)->dto($s),
            'destination' => app(TreasuryBindings::class)->dto($d),
            'reference' => $reference,
            'amount' => (string) $amount,
            'requested_on' => $data['requested_on'],
            'requested_at' => $at->toIso8601String(),
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'note' => $data['note'],
            'replaces' => $previous ? ['id' => $previous->id, 'sha256' => $previous->sha256] : null,
            'verification' => 'manual_request_evidence',
        ];
        $id = Records::id();
        DB::table('withdrawals')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'source_account_id' => $s->treasury_account_id,
            'destination_account_id' => $d->treasury_account_id,
            'source_binding_id' => $s->id,
            'destination_binding_id' => $d->id,
            'reference' => $reference,
            'revision' => ($previous?->revision ?? 0) + 1,
            'replaces_id' => $previous?->id,
            'amount' => $amount,
            'requested_on' => $data['requested_on'],
            'requested_at' => $at,
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'withdrawal.created', $id, ['sha256' => app(CommandBus::class)->fingerprint($snapshot)]);

        return $this->dto(Records::row('withdrawals', $id, false));
    }

    public function transition(object $book, string $id, string $action, array $data, User $actor): array
    {
        $r = app(AccountingBooks::class)->row('withdrawals', $id, $book);
        abort_unless((int) $r->version === $data['version'], 409);
        if (
            ! hash_equals($r->sha256, $data['sha256']) ||
            ! in_array($r->status, ['pending', 'approved'], true) ||
            ($book->mode === 'live' && (int) $r->created_by === $actor->id)
        ) {
            AccountingBooks::fail('status', 'WITHDRAWAL_REVIEW');
        }
        $this->dates($book, $data['date'], $r);
        if ($action === 'approve') {
            if ($r->status !== 'pending') {
                AccountingBooks::fail('status', 'WITHDRAWAL_STATE');
            }
            $this->bindings($book, $r->source_binding_id, $r->destination_binding_id, $r);
            $document = $this->evidence($r->document_id);
            if (! hash_equals($document->sha256, json_decode($r->snapshot, true)['document']['sha256'])) {
                AccountingBooks::fail('document_id', 'SOURCE_CHANGED');
            }
            $changes = [
                'status' => 'approved',
                'approved_on' => $data['date'],
                'approved_by' => $actor->id,
                'approved_at' => now(),
                'approval_note' => $data['note'],
            ];
        } else {
            $balance = $this->balance($r);
            abort_unless($data['accepted_remaining'] === $balance['remaining_gross'], 409);
            if (
                ($action === 'cancel' && $balance['remaining_gross'] === '0') ||
                ($action === 'void' && $balance['received_gross'] !== '0')
            ) {
                AccountingBooks::fail('amount', 'WITHDRAWAL_AMOUNT');
            }
            $document = $this->evidence($data['document_id']);
            $changes = [
                'status' => $action === 'cancel' ? 'cancelled' : 'void',
                'reference_slot' => $action === 'void' ? null : 1,
                'closed_on' => $data['date'],
                'closed_by' => $actor->id,
                'closed_at' => now(),
                'close_note' => $data['note'],
                'close_document_id' => $data['document_id'],
                'close_document_sha256' => $document->sha256,
            ];
        }
        DB::table('withdrawals')
            ->where('id', $id)
            ->update([...$changes, 'version' => $r->version + 1]);
        Records::audit($actor, 'withdrawal.'.$action, $id, ['date' => $data['date'], 'note' => $data['note']]);

        return $this->dto(Records::row('withdrawals', $id, false));
    }

    public function record(object $book, string $id, array $data, User $actor): array
    {
        $r = app(AccountingBooks::class)->row('withdrawals', $id, $book);
        abort_unless((int) $r->version === $data['version'], 409);
        if (! in_array($r->status, ['approved', 'cancelled'], true)) {
            AccountingBooks::fail('status', 'WITHDRAWAL_STATE');
        }
        [$s, $d] = $this->bindings($book, $data['source_binding_id'], $data['destination_binding_id'], $r);
        $entry = app(AccountingBooks::class)->row('accounting_entries', $data['entry_id'], $book);
        $lines = DB::table('accounting_entry_lines')->where('entry_id', $entry->id)->get();
        $source = $lines->firstWhere('id', $data['source_line_id']);
        $destination = $lines->firstWhere('id', $data['destination_line_id']);
        $in = $s->inflow_side;
        $out = $in === 'debit' ? 'credit' : 'debit';
        $treasuryAccounts = DB::table('treasury_bindings')->where('book_id', $book->id)->pluck('account_id')->all();
        if (
            ! $source ||
            ! $destination ||
            $source->id === $destination->id ||
            $source->account_id !== $s->account_id ||
            $destination->account_id !== $d->account_id ||
            $entry->status !== 'posted' ||
            $entry->source_id ||
            $entry->reversal_of ||
            DB::table('accounting_entries')->where('reversal_of', $entry->id)->exists() ||
            DB::table('expense_payments')->where('entry_id', $entry->id)->exists() ||
            DB::table('withdrawal_receipts')->where('entry_id', $entry->id)->exists() ||
            $source->{$in} != 0 ||
            $destination->{$out} != 0 ||
            $source->{$out} < 1 ||
            $destination->{$in} < 1
        ) {
            AccountingBooks::fail('entry_id', 'WITHDRAWAL_POSTING');
        }
        $gross = (string) $source->{$out};
        $net = (string) $destination->{$in};
        $fee = BigInteger::of($gross)->minus($net);
        $other = BigInteger::zero();
        foreach ($lines as $line) {
            if (in_array($line->id, [$source->id, $destination->id], true)) {
                continue;
            }
            if (in_array($line->account_id, $treasuryAccounts, true) || $line->{$out} != 0) {
                AccountingBooks::fail('entry_id', 'WITHDRAWAL_POSTING');
            }
            $other = $other->plus((string) $line->{$in});
        }
        if (
            $fee->isNegative() ||
            ! $fee->isEqualTo($other) ||
            $data['accepted_gross'] !== $gross ||
            $data['accepted_net'] !== $net ||
            $data['accepted_fee'] !== (string) $fee ||
            BigInteger::of($gross)->isGreaterThan($this->balance($r)['remaining_gross'])
        ) {
            AccountingBooks::fail('amount', 'WITHDRAWAL_AMOUNT');
        }
        if ($book->mode === 'live' && ((int) $r->created_by === $actor->id || (int) $entry->posted_by === $actor->id)) {
            AccountingBooks::fail('actor', 'WITHDRAWAL_REVIEW');
        }
        $this->dates($book, $entry->entry_date, $r);
        $at = CarbonImmutable::parse($data['received_at'])->utc();
        if (
            $at->lessThan(CarbonImmutable::parse($r->requested_at, 'UTC')) ||
            $at->setTimezone('Africa/Douala')->toDateString() > $entry->entry_date
        ) {
            AccountingBooks::fail('received_at', 'WITHDRAWAL_DATE');
        }
        $reference = trim($data['reference']);
        $previous = $this->predecessor(
            'withdrawal_receipts',
            $book,
            $r->source_account_id,
            $reference,
            $data['replaces_id'] ?? null,
        );
        if (
            $r->status === 'cancelled' &&
            (! $previous ||
                $previous->withdrawal_id !== $id ||
                BigInteger::of($gross)->isGreaterThan((string) $previous->gross))
        ) {
            AccountingBooks::fail('status', 'WITHDRAWAL_STATE');
        }
        if ($previous && $entry->entry_date < $previous->reversed_on) {
            AccountingBooks::fail('entry_date', 'WITHDRAWAL_DATE');
        }
        $document = $this->evidence($data['document_id']);
        $snapshot = [
            'withdrawal_id' => $id,
            'withdrawal_sha256' => $r->sha256,
            'book_id' => $book->id,
            'source' => app(TreasuryBindings::class)->dto($s),
            'destination' => app(TreasuryBindings::class)->dto($d),
            'entry_id' => $entry->id,
            'entry_sha256' => $entry->sha256,
            'entry_number' => $entry->number,
            'source_line_id' => (int) $source->id,
            'destination_line_id' => (int) $destination->id,
            'gross' => $gross,
            'net' => $net,
            'fee' => (string) $fee,
            'posted_on' => $entry->entry_date,
            'received_at' => $at->toIso8601String(),
            'reference' => $reference,
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'note' => $data['note'],
            'replaces' => $previous ? ['id' => $previous->id, 'sha256' => $previous->sha256] : null,
            'verification' => 'reviewed_manual_transfer_posting',
        ];
        $receipt = Records::id();
        DB::table('withdrawal_receipts')->insert([
            'id' => $receipt,
            'withdrawal_id' => $id,
            'book_id' => $book->id,
            'source_account_id' => $r->source_account_id,
            'source_binding_id' => $s->id,
            'destination_binding_id' => $d->id,
            'entry_id' => $entry->id,
            'source_line_id' => $source->id,
            'destination_line_id' => $destination->id,
            'gross' => $gross,
            'net' => $net,
            'fee' => (string) $fee,
            'posted_on' => $entry->entry_date,
            'received_at' => $at,
            'reference' => $reference,
            'revision' => ($previous?->revision ?? 0) + 1,
            'replaces_id' => $previous?->id,
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'withdrawal.received', $receipt, ['withdrawal_id' => $id, 'entry_id' => $entry->id]);

        return $this->receiptDto(Records::row('withdrawal_receipts', $receipt, false));
    }

    private function match(string $book, string $treasury, int $line): ?object
    {
        return DB::table('treasury_match_ledger as l')
            ->join('treasury_matches as m', 'm.id', '=', 'l.match_id')
            ->join('treasury_statements as s', 's.id', '=', 'm.statement_id')
            ->where('l.ledger_line_id', $line)
            ->where('l.active_slot', 1)
            ->where('m.status', 'active')
            ->where('s.book_id', $book)
            ->where('s.treasury_account_id', $treasury)
            ->where('s.status', '!=', 'void')
            ->select('m.id as match_id', 's.id as statement_id', 's.status', 's.ends_on')
            ->first();
    }

    public function reconciliation(object $receipt): array
    {
        $request = Records::row('withdrawals', $receipt->withdrawal_id, false);
        $source = $this->match($receipt->book_id, $request->source_account_id, (int) $receipt->source_line_id);
        $destination = $this->match(
            $receipt->book_id,
            $request->destination_account_id,
            (int) $receipt->destination_line_id,
        );

        return [
            'source' => $source ? (array) $source : null,
            'destination' => $destination ? (array) $destination : null,
            'both_closed' => $receipt->status === 'recorded' && $source?->status === 'closed' && $destination?->status === 'closed',
            'external_availability_verified' => false,
        ];
    }

    public function reverse(object $book, string $id, string $receipt, array $data, User $actor): array
    {
        $r = app(AccountingBooks::class)->row('withdrawals', $id, $book);
        $p = app(AccountingBooks::class)->row('withdrawal_receipts', $receipt, $book);
        abort_unless($p->withdrawal_id === $id, 404);
        abort_unless((int) $p->version === $data['version'], 409);
        if ($p->status !== 'recorded' || ! hash_equals($p->sha256, $data['sha256'])) {
            AccountingBooks::fail('status', 'WITHDRAWAL_STATE');
        }
        $this->dates($book, $data['entry_date'], $r);
        $state = $this->reconciliation($p);
        if ($state['source'] || $state['destination']) {
            AccountingBooks::fail('receipt', 'WITHDRAWAL_UNMATCH_FIRST');
        }
        $document = $this->evidence($data['document_id']);
        DB::table('withdrawal_receipts')
            ->where('id', $receipt)
            ->update(['status' => 'reversing']);
        $entry = app(AccountingBooks::class)->row('accounting_entries', $p->entry_id, $book);
        $reversal = app(AccountingEntries::class)->reverse(
            $book,
            $entry,
            [...$data, 'version' => (int) $entry->version],
            $actor,
        );
        DB::table('withdrawal_receipts')
            ->where('id', $receipt)
            ->update([
                'status' => 'reversed',
                'version' => 2,
                'reference_slot' => null,
                'reversal_entry_id' => $reversal['id'],
                'reversed_on' => $reversal['entry_date'],
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_note' => $data['note'],
                'reversal_document_id' => $document->id,
                'reversal_document_sha256' => $document->sha256,
            ]);
        Records::audit($actor, 'withdrawal.receipt_reversed', $receipt, [
            'reversal_entry_id' => $reversal['id'],
            'note' => $data['note'],
        ]);

        return $this->receiptDto(Records::row('withdrawal_receipts', $receipt, false));
    }

    public function guardReversal(object $entry): void
    {
        if (
            DB::table('withdrawal_receipts')
                ->where(
                    fn ($q) => $q
                        ->where(fn ($p) => $p->where('entry_id', $entry->id)->where('status', 'recorded'))
                        ->orWhere('reversal_entry_id', $entry->id),
                )
                ->exists()
        ) {
            AccountingBooks::fail('entry_id', 'WITHDRAWAL_LINKED_REVERSAL');
        }
    }

    public function balance(object $r): array
    {
        $totals = array_fill_keys(
            ['recorded_gross', 'reversed_gross', 'received_gross', 'received_net', 'fees_paid', 'reconciled_gross'],
            BigInteger::zero(),
        );
        foreach (DB::table('withdrawal_receipts')->where('withdrawal_id', $r->id)->get() as $p) {
            $totals['recorded_gross'] = $totals['recorded_gross']->plus((string) $p->gross);
            if ($p->status === 'reversed') {
                $totals['reversed_gross'] = $totals['reversed_gross']->plus((string) $p->gross);

                continue;
            }
            foreach (['received_gross' => 'gross', 'received_net' => 'net', 'fees_paid' => 'fee'] as $key => $field) {
                $totals[$key] = $totals[$key]->plus((string) $p->{$field});
            }
            if ($this->reconciliation($p)['both_closed']) {
                $totals['reconciled_gross'] = $totals['reconciled_gross']->plus((string) $p->gross);
            }
        }
        $remaining = BigInteger::of((string) $r->amount)->minus($totals['received_gross']);

        return [
            ...array_map(fn ($v) => (string) $v, $totals),
            'remaining_gross' => (string) $remaining,
            'pending_gross' => in_array($r->status, ['pending', 'approved'], true) ? (string) $remaining : '0',
            'pending_review_gross' => $r->status === 'pending' ? (string) $remaining : '0',
            'approved_pending_gross' => $r->status === 'approved' ? (string) $remaining : '0',
            'cancelled_gross' => $r->status === 'cancelled' ? (string) $remaining : '0',
            'unreconciled_received_gross' => (string) $totals['received_gross']->minus($totals['reconciled_gross']),
        ];
    }

    public function summary(object $book): array
    {
        $totals = [];
        $count = 0;
        foreach (DB::table('withdrawals')->where('book_id', $book->id)->orderBy('id')->cursor() as $r) {
            $count++;
            foreach ($this->balance($r) as $key => $amount) {
                $totals[$key] = ($totals[$key] ?? BigInteger::zero())->plus($amount);
            }
        }
        if ($count === 0) {
            foreach (
                [
                    'recorded_gross',
                    'reversed_gross',
                    'received_gross',
                    'received_net',
                    'fees_paid',
                    'reconciled_gross',
                    'remaining_gross',
                    'pending_gross',
                    'pending_review_gross',
                    'approved_pending_gross',
                    'cancelled_gross',
                    'unreconciled_received_gross',
                ] as $key
            ) {
                $totals[$key] = BigInteger::zero();
            }
        }

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'scope' => 'registered_withdrawals_only',
            'state_basis' => 'current_operational_snapshot',
            'request_count' => $count,
            'totals' => array_map(fn ($v) => (string) $v, $totals),
            'available_funds' => null,
            'availability_verified' => false,
            'provisional' => true,
        ];
    }

    public function dto(object $r): array
    {
        return [
            'id' => $r->id,
            'book_id' => $r->book_id,
            'status' => $r->status,
            'version' => (int) $r->version,
            'revision' => (int) $r->revision,
            'snapshot' => json_decode($r->snapshot, true),
            'sha256' => $r->sha256,
            'created_by' => (int) $r->created_by,
            'approved_on' => $r->approved_on,
            'approved_by' => $r->approved_by === null ? null : (int) $r->approved_by,
            'approval_note' => $r->approval_note,
            'closed_on' => $r->closed_on,
            'closed_by' => $r->closed_by === null ? null : (int) $r->closed_by,
            'close_note' => $r->close_note,
            'close_document_id' => $r->close_document_id,
            'close_document_sha256' => $r->close_document_sha256,
            'balance' => $this->balance($r),
        ];
    }

    public function receiptDto(object $r): array
    {
        return [
            'id' => $r->id,
            'withdrawal_id' => $r->withdrawal_id,
            'status' => $r->status,
            'version' => (int) $r->version,
            'revision' => (int) $r->revision,
            'snapshot' => json_decode($r->snapshot, true),
            'sha256' => $r->sha256,
            'created_by' => (int) $r->created_by,
            'reversal_entry_id' => $r->reversal_entry_id,
            'reversed_on' => $r->reversed_on,
            'reversed_by' => $r->reversed_by === null ? null : (int) $r->reversed_by,
            'reversal_note' => $r->reversal_note,
            'reversal_document_id' => $r->reversal_document_id,
            'reversal_document_sha256' => $r->reversal_document_sha256,
            'reconciliation' => $this->reconciliation($r),
        ];
    }
}
