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

/** Principal-only, evidence-linked existing postings. Never sends money or qualifies a legal transaction automatically. */
final class Financings
{
    private function evidence(string $id): object
    {
        return app(PrivateDocuments::class)->requireClean($id, null, ['accounting_evidence']);
    }

    private function counter(object $book, string $account, string $kind): object
    {
        $a = app(AccountingBooks::class)->row('accounting_accounts', $account, $book);
        if (
            ! $a->active ||
            ! $a->postable ||
            $a->auxiliary_type !== 'none' ||
            $a->kind !== ($kind === 'capital_contribution' ? 'equity' : 'liability') ||
            DB::table('treasury_bindings')->where('book_id', $book->id)->where('account_id', $account)->exists() ||
            ($book->mode === 'live' && (int) $book->reviewed_chart_version !== (int) $book->chart_version)
        ) {
            AccountingBooks::fail('account_id', 'FINANCING_ACCOUNT');
        }

        return $a;
    }

    private function dates(object $book, string $date, ?object $f = null): void
    {
        app(AccountingCalendar::class)->period($book, $date);
        if ($date > CarbonImmutable::now('Africa/Douala')->toDateString()) {
            AccountingBooks::fail('date', 'FINANCING_DATE');
        }
        if ($f) {
            $last = max($f->effective_on, $f->approved_on ?? '', $f->cancelled_on ?? '');
            foreach (DB::table('financing_movements')->where('financing_id', $f->id)->get() as $m) {
                $last = max($last, $m->posted_on, $m->reversed_on ?? '');
            }
            if ($date < $last) {
                AccountingBooks::fail('date', 'FINANCING_DATE');
            }
        }
    }

    public function create(object $book, array $data, User $actor): array
    {
        $a = $this->counter($book, $data['account_id'], $data['kind']);
        $amount = Money::parse($data['principal']);
        if (
            $amount < 1 ||
            ($data['kind'] === 'capital_contribution' && isset($data['due_on'])) ||
            (isset($data['due_on']) && $data['due_on'] < $data['effective_on']) ||
            $data['effective_on'] > CarbonImmutable::now('Africa/Douala')->toDateString()
        ) {
            AccountingBooks::fail('principal', 'FINANCING_TERMS');
        }
        $reference = trim($data['reference']);
        if (DB::table('financings')->where('book_id', $book->id)->where('reference', $reference)->exists()) {
            AccountingBooks::fail('reference', 'FINANCING_REFERENCE');
        }
        $d = $this->evidence($data['document_id']);
        $id = Records::id();
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'kind' => $data['kind'],
            'reference' => $reference,
            'counterparty' => $data['counterparty'],
            'account' => app(AccountingBooks::class)->account($a),
            'chart_version' => (int) $book->chart_version,
            'principal' => (string) $amount,
            'effective_on' => $data['effective_on'],
            'due_on' => $data['due_on'] ?? null,
            'document' => ['id' => $d->id, 'sha256' => $d->sha256],
            'note' => $data['note'],
            'terms_scope' => 'non_revolving_principal_only',
        ];
        DB::table('financings')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'kind' => $data['kind'],
            'reference' => $reference,
            'counterparty' => $data['counterparty'],
            'account_id' => $a->id,
            'principal' => $amount,
            'effective_on' => $data['effective_on'],
            'due_on' => $data['due_on'] ?? null,
            'document_id' => $d->id,
            'document_sha256' => $d->sha256,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'financing.created', $id);

        return $this->dto(Records::row('financings', $id, false));
    }

    public function transition(object $book, string $id, string $action, array $data, User $actor): array
    {
        $f = app(AccountingBooks::class)->row('financings', $id, $book);
        abort_unless((int) $f->version === $data['version'], 409);
        if (
            ! hash_equals($f->sha256, $data['sha256']) ||
            ! in_array($f->status, ['pending', 'approved'], true) ||
            ($book->mode === 'live' && (int) $f->created_by === $actor->id)
        ) {
            AccountingBooks::fail('status', 'FINANCING_STATE');
        }
        $this->dates($book, $data['date'], $f);
        if ($action === 'approve') {
            if ($f->status !== 'pending') {
                AccountingBooks::fail('status', 'FINANCING_STATE');
            }
            $this->counter($book, $f->account_id, $f->kind);
            $d = $this->evidence($f->document_id);
            if (! hash_equals($f->document_sha256, $d->sha256)) {
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
            $balance = $this->balance($f);
            abort_unless($data['accepted_undrawn'] === $balance['undrawn_principal'], 409);
            if ($balance['undrawn_principal'] === '0') {
                AccountingBooks::fail('amount', 'FINANCING_AMOUNT');
            }
            $d = $this->evidence($data['document_id']);
            $changes = [
                'status' => 'cancelled',
                'cancelled_on' => $data['date'],
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_note' => $data['note'],
                'cancel_document_id' => $d->id,
                'cancel_document_sha256' => $d->sha256,
            ];
        }
        DB::table('financings')
            ->where('id', $id)
            ->update([...$changes, 'version' => $f->version + 1]);
        Records::audit($actor, 'financing.'.$action, $id, ['date' => $data['date'], 'note' => $data['note']]);

        return $this->dto(Records::row('financings', $id, false));
    }

    public function record(object $book, string $id, array $data, User $actor): array
    {
        $f = app(AccountingBooks::class)->row('financings', $id, $book);
        abort_unless((int) $f->version === $data['version'], 409);
        if (
            ! in_array($f->status, ['approved', 'cancelled'], true) ||
            ($data['direction'] === 'repayment' && $f->kind === 'capital_contribution')
        ) {
            AccountingBooks::fail('status', 'FINANCING_STATE');
        }
        $this->counter($book, $f->account_id, $f->kind);
        $b = app(TreasuryBindings::class)->usable($book, $data['binding_id']);
        $cash = Records::row('accounting_accounts', $b->account_id, false);
        if ($b->inflow_side !== 'debit' || $cash->kind !== 'asset' || $cash->id === $f->account_id) {
            AccountingBooks::fail('binding_id', 'FINANCING_ACCOUNT');
        }
        $e = app(AccountingBooks::class)->row('accounting_entries', $data['entry_id'], $book);
        $lines = DB::table('accounting_entry_lines')->where('entry_id', $e->id)->get();
        $line = $lines->firstWhere('id', $data['cash_line_id']);
        $other = $lines->firstWhere('account_id', $f->account_id);
        $in = $data['direction'] === 'funding' ? 'debit' : 'credit';
        $out = $in === 'debit' ? 'credit' : 'debit';
        if (
            $lines->count() !== 2 ||
            ! $line ||
            ! $other ||
            $line->account_id !== $b->account_id ||
            $line->{$out} != 0 ||
            $other->{$in} != 0 ||
            $line->{$in} < 1 ||
            $line->{$in} != $other->{$out} ||
            $e->status !== 'posted' ||
            $e->source_id ||
            $e->reversal_of ||
            DB::table('accounting_entries')->where('reversal_of', $e->id)->exists() ||
            DB::table('financing_movements')->where('entry_id', $e->id)->exists() ||
            DB::table('expense_payments')->where('entry_id', $e->id)->exists() ||
            DB::table('withdrawal_receipts')->where('entry_id', $e->id)->exists()
        ) {
            AccountingBooks::fail('entry_id', 'FINANCING_POSTING');
        }
        $amount = (string) $line->{$in};
        $balance = $this->balance($f);
        $capacity =
            $data['direction'] === 'funding' ? $balance['undrawn_principal'] : $balance['outstanding_principal'];
        if ($amount !== $data['accepted_amount'] || BigInteger::of($amount)->isGreaterThan($capacity)) {
            AccountingBooks::fail('amount', 'FINANCING_AMOUNT');
        }
        if ($book->mode === 'live' && ((int) $f->created_by === $actor->id || (int) $e->posted_by === $actor->id)) {
            AccountingBooks::fail('actor', 'FINANCING_REVIEW');
        }
        $this->dates($book, $e->entry_date, $f);
        $at = CarbonImmutable::parse($data['occurred_at'])->utc();
        if (
            $at->isAfter(CarbonImmutable::now('UTC')) ||
            $at->setTimezone('Africa/Douala')->toDateString() < $f->effective_on ||
            $at->setTimezone('Africa/Douala')->toDateString() > $e->entry_date
        ) {
            AccountingBooks::fail('occurred_at', 'FINANCING_DATE');
        }
        $reference = trim($data['reference']);
        $previous = DB::table('financing_movements')
            ->where('book_id', $book->id)
            ->where('treasury_account_id', $b->treasury_account_id)
            ->where('reference', $reference)
            ->orderByDesc('revision')
            ->first();
        if (
            (! $previous && ! empty($data['replaces_id'])) ||
            ($previous &&
                (($data['replaces_id'] ?? null) !== $previous->id ||
                    $previous->status !== 'reversed' ||
                    $previous->reference_slot !== null ||
                    $previous->direction !== $data['direction'] ||
                    $e->entry_date < $previous->reversed_on))
        ) {
            AccountingBooks::fail('reference', 'FINANCING_REFERENCE');
        }
        if (
            $f->status === 'cancelled' &&
            $data['direction'] === 'funding' &&
            (! $previous ||
                $previous->financing_id !== $id ||
                BigInteger::of($amount)->isGreaterThan((string) $previous->amount))
        ) {
            AccountingBooks::fail('status', 'FINANCING_STATE');
        }
        $d = $this->evidence($data['document_id']);
        $movement = Records::id();
        $snapshot = [
            'financing_id' => $id,
            'financing_sha256' => $f->sha256,
            'book_id' => $book->id,
            'direction' => $data['direction'],
            'binding' => app(TreasuryBindings::class)->dto($b),
            'entry_id' => $e->id,
            'entry_sha256' => $e->sha256,
            'entry_number' => $e->number,
            'cash_line_id' => (int) $line->id,
            'amount' => $amount,
            'posted_on' => $e->entry_date,
            'occurred_at' => $at->toIso8601String(),
            'reference' => $reference,
            'document' => ['id' => $d->id, 'sha256' => $d->sha256],
            'note' => $data['note'],
            'replaces' => $previous ? ['id' => $previous->id, 'sha256' => $previous->sha256] : null,
            'verification' => 'reviewed_existing_principal_posting',
        ];
        DB::table('financing_movements')->insert([
            'id' => $movement,
            'financing_id' => $id,
            'book_id' => $book->id,
            'treasury_account_id' => $b->treasury_account_id,
            'binding_id' => $b->id,
            'entry_id' => $e->id,
            'cash_line_id' => $line->id,
            'direction' => $data['direction'],
            'amount' => $amount,
            'posted_on' => $e->entry_date,
            'occurred_at' => $at,
            'reference' => $reference,
            'revision' => ($previous?->revision ?? 0) + 1,
            'replaces_id' => $previous?->id,
            'document_id' => $d->id,
            'document_sha256' => $d->sha256,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'financing.movement_recorded', $movement, ['financing_id' => $id, 'entry_id' => $e->id]);

        return $this->movementDto(Records::row('financing_movements', $movement, false));
    }

    public function reverse(object $book, string $id, string $movement, array $data, User $actor): array
    {
        $f = app(AccountingBooks::class)->row('financings', $id, $book);
        $m = app(AccountingBooks::class)->row('financing_movements', $movement, $book);
        abort_unless($m->financing_id === $id, 404);
        abort_unless((int) $m->version === $data['version'], 409);
        if ($m->status !== 'recorded' || ! hash_equals($m->sha256, $data['sha256'])) {
            AccountingBooks::fail('status', 'FINANCING_STATE');
        }
        $this->dates($book, $data['entry_date'], $f);
        if (
            $m->direction === 'funding' &&
            BigInteger::of((string) $m->amount)->isGreaterThan($this->balance($f)['funded_unrepaid'])
        ) {
            AccountingBooks::fail('amount', 'FINANCING_REVERSE_CAPACITY');
        }
        if (
            DB::table('treasury_match_ledger as l')
                ->join('treasury_matches as m', 'm.id', '=', 'l.match_id')
                ->where('l.ledger_line_id', $m->cash_line_id)
                ->where('l.active_slot', 1)
                ->where('m.status', 'active')
                ->exists()
        ) {
            AccountingBooks::fail('movement', 'FINANCING_UNMATCH_FIRST');
        }
        $d = $this->evidence($data['document_id']);
        DB::table('financing_movements')
            ->where('id', $movement)
            ->update(['status' => 'reversing']);
        $e = app(AccountingBooks::class)->row('accounting_entries', $m->entry_id, $book);
        $counter = app(AccountingEntries::class)->reverse(
            $book,
            $e,
            [...$data, 'version' => (int) $e->version],
            $actor,
        );
        DB::table('financing_movements')
            ->where('id', $movement)
            ->update([
                'status' => 'reversed',
                'version' => 2,
                'reference_slot' => null,
                'reversal_entry_id' => $counter['id'],
                'reversed_on' => $counter['entry_date'],
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_note' => $data['note'],
                'reversal_document_id' => $d->id,
                'reversal_document_sha256' => $d->sha256,
            ]);
        Records::audit($actor, 'financing.movement_reversed', $movement, [
            'reversal_entry_id' => $counter['id'],
            'note' => $data['note'],
        ]);

        return $this->movementDto(Records::row('financing_movements', $movement, false));
    }

    public function guardReversal(object $entry): void
    {
        if (
            DB::table('financing_movements')
                ->where(
                    fn ($q) => $q
                        ->where(fn ($v) => $v->where('entry_id', $entry->id)->where('status', 'recorded'))
                        ->orWhere('reversal_entry_id', $entry->id),
                )
                ->exists()
        ) {
            AccountingBooks::fail('entry_id', 'FINANCING_LINKED_REVERSAL');
        }
    }

    public function balance(object $f): array
    {
        $funded = BigInteger::zero();
        $paid = BigInteger::zero();
        foreach (
            DB::table('financing_movements')->where('financing_id', $f->id)->where('status', '!=', 'reversed')->get() as $m
        ) {
            if ($m->direction === 'funding') {
                $funded = $funded->plus((string) $m->amount);
            } else {
                $paid = $paid->plus((string) $m->amount);
            }
        }
        $undrawn = BigInteger::of((string) $f->principal)->minus($funded);
        $net = $funded->minus($paid);
        $capital = $f->kind === 'capital_contribution';

        return [
            'funded_principal' => (string) $funded,
            'repaid_principal' => (string) $paid,
            'funded_unrepaid' => (string) $net,
            'outstanding_principal' => $capital ? '0' : (string) $net,
            'capital_received' => $capital ? (string) $funded : '0',
            'undrawn_principal' => (string) $undrawn,
            'pending_funding' => $f->status === 'approved' ? (string) $undrawn : '0',
            'pending_review_principal' => $f->status === 'pending' ? (string) $undrawn : '0',
            'cancelled_undrawn' => $f->status === 'cancelled' ? (string) $undrawn : '0',
        ];
    }

    public function summary(object $book): array
    {
        $totals = array_fill_keys(
            [
                'funded_principal',
                'repaid_principal',
                'funded_unrepaid',
                'outstanding_principal',
                'capital_received',
                'undrawn_principal',
                'pending_funding',
                'pending_review_principal',
                'cancelled_undrawn',
            ],
            BigInteger::zero(),
        );
        $byKind = array_fill_keys(['founder_advance', 'capital_contribution', 'loan'], $totals);
        $schedule = [];
        $count = 0;
        foreach (DB::table('financings')->where('book_id', $book->id)->orderBy('id')->cursor() as $f) {
            $count++;
            $balance = $this->balance($f);
            foreach ($balance as $key => $amount) {
                $totals[$key] = $totals[$key]->plus($amount);
                $byKind[$f->kind][$key] = $byKind[$f->kind][$key]->plus($amount);
            }
            if ($balance['outstanding_principal'] !== '0') {
                $schedule[] = [
                    'financing_id' => $f->id,
                    'kind' => $f->kind,
                    'due_on' => $f->due_on,
                    'principal' => $balance['outstanding_principal'],
                ];
            }
        }

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'scope' => 'registered_principal_financing_only',
            'state_basis' => 'current_operational_snapshot',
            'agreement_count' => $count,
            'totals' => array_map(fn ($v) => (string) $v, $totals),
            'by_kind' => array_map(fn ($row) => array_map(fn ($v) => (string) $v, $row), $byKind),
            'repayment_schedule' => $schedule,
            'available_funds' => null,
            'provisional' => true,
        ];
    }

    public function forecast(object $book, string $from, string $to, string $today): array
    {
        $summary = $this->summary($book);
        $buckets = array_fill_keys(
            ['before_window', 'within_window', 'after_window', 'undated', 'past_due'],
            BigInteger::zero(),
        );
        $days = [];
        foreach ($summary['repayment_schedule'] as $row) {
            $due = $row['due_on'];
            $key =
                $due === null
                    ? 'undated'
                    : ($due < $from
                        ? 'before_window'
                        : ($due > $to
                            ? 'after_window'
                            : 'within_window'));
            $buckets[$key] = $buckets[$key]->plus($row['principal']);
            if ($due !== null) {
                $days[$due] = ($days[$due] ?? BigInteger::zero())->plus($row['principal']);
                if ($due < $today) {
                    $buckets['past_due'] = $buckets['past_due']->plus($row['principal']);
                }
            }
        }
        ksort($days);

        return [
            ...$summary,
            'forecast' => [
                ...array_map(fn ($v) => (string) $v, $buckets),
                'days' => array_map(
                    fn ($date, $amount) => ['due_on' => $date, 'amount' => (string) $amount],
                    array_keys($days),
                    array_values($days),
                ),
            ],
        ];
    }

    public function dto(object $f): array
    {
        return [
            'id' => $f->id,
            'book_id' => $f->book_id,
            'status' => $f->status,
            'version' => (int) $f->version,
            'snapshot' => json_decode($f->snapshot, true),
            'sha256' => $f->sha256,
            'created_by' => (int) $f->created_by,
            'approved_on' => $f->approved_on,
            'approved_by' => $f->approved_by === null ? null : (int) $f->approved_by,
            'approval_note' => $f->approval_note,
            'cancelled_on' => $f->cancelled_on,
            'cancelled_by' => $f->cancelled_by === null ? null : (int) $f->cancelled_by,
            'cancel_note' => $f->cancel_note,
            'cancel_document_id' => $f->cancel_document_id,
            'cancel_document_sha256' => $f->cancel_document_sha256,
            'balance' => $this->balance($f),
        ];
    }

    public function movementDto(object $m): array
    {
        return [
            'id' => $m->id,
            'financing_id' => $m->financing_id,
            'status' => $m->status,
            'version' => (int) $m->version,
            'revision' => (int) $m->revision,
            'snapshot' => json_decode($m->snapshot, true),
            'sha256' => $m->sha256,
            'created_by' => (int) $m->created_by,
            'reversal_entry_id' => $m->reversal_entry_id,
            'reversed_on' => $m->reversed_on,
            'reversed_by' => $m->reversed_by === null ? null : (int) $m->reversed_by,
            'reversal_note' => $m->reversal_note,
            'reversal_document_id' => $m->reversal_document_id,
            'reversal_document_sha256' => $m->reversal_document_sha256,
        ];
    }
}
