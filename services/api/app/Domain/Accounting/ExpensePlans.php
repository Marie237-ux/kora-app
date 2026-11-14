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

/** Book-locked expense budgets and evidence-linked existing cash postings. Never sends money. */
final class ExpensePlans
{
    private function evidence(string $id): object
    {
        return app(PrivateDocuments::class)->requireClean($id, null, ['accounting_evidence']);
    }

    private function chronological(object $book, object $plan, string $date): void
    {
        app(AccountingCalendar::class)->period($book, $date);
        $last = max($plan->planned_on, $plan->approved_on ?? '', $plan->cancelled_on ?? '');
        foreach (DB::table('expense_payments')->where('plan_id', $plan->id)->get() as $p) {
            $last = max($last, $p->posted_on, $p->reversed_on ?? '');
        }
        if ($date < $last) {
            AccountingBooks::fail('date', 'EXPENSE_CHRONOLOGY');
        }
    }

    public function create(object $book, array $data, User $actor): array
    {
        app(AccountingCalendar::class)->period($book, $data['planned_on']);
        $binding = app(TreasuryBindings::class)->usable($book, $data['binding_id']);
        $document = $this->evidence($data['document_id']);
        $amount = Money::parse($data['amount']);
        if ($amount < 1) {
            AccountingBooks::fail('amount', 'EXPENSE_AMOUNT');
        }
        $reference = mb_strtoupper(trim($data['reference']));
        if (DB::table('expense_plans')->where('book_id', $book->id)->where('reference_key', $reference)->exists()) {
            AccountingBooks::fail('reference', 'EXPENSE_REFERENCE');
        }
        $snapshot = [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'currency' => 'XAF',
            'binding' => app(TreasuryBindings::class)->dto($binding),
            'amount' => (string) $amount,
            'category' => $data['category'],
            'description' => $data['description'],
            'payee' => $data['payee'],
            'reference' => $data['reference'],
            'planned_on' => $data['planned_on'],
            'due_on' => $data['due_on'],
            'note' => $data['note'],
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
        ];
        $id = Records::id();
        DB::table('expense_plans')->insert([
            'id' => $id,
            'book_id' => $book->id,
            'treasury_account_id' => $binding->treasury_account_id,
            'binding_id' => $binding->id,
            'amount' => $amount,
            'category' => $data['category'],
            'reference_key' => $reference,
            'planned_on' => $data['planned_on'],
            'due_on' => $data['due_on'],
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'expense.created', $id, ['sha256' => app(CommandBus::class)->fingerprint($snapshot)]);

        return $this->dto(Records::row('expense_plans', $id, false));
    }

    public function transition(object $book, string $id, string $action, array $data, User $actor): array
    {
        $plan = app(AccountingBooks::class)->row('expense_plans', $id, $book);
        abort_unless((int) $plan->version === $data['version'], 409);
        if (! hash_equals($plan->sha256, $data['sha256']) || $plan->status === 'cancelled') {
            AccountingBooks::fail('status', 'EXPENSE_STATE');
        }
        $this->chronological($book, $plan, $data['date']);
        if ($action === 'approve') {
            if ($plan->status !== 'pending' || ($book->mode === 'live' && (int) $plan->created_by === $actor->id)) {
                AccountingBooks::fail('status', 'EXPENSE_REVIEW');
            }
            app(TreasuryBindings::class)->usable($book, $plan->binding_id);
            $document = $this->evidence($plan->document_id);
            if (! hash_equals($document->sha256, json_decode($plan->snapshot, true)['document']['sha256'])) {
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
            $remaining = $this->balance($plan)['remaining'];
            abort_unless($data['accepted_remaining'] === $remaining, 409);
            if ($remaining === '0') {
                AccountingBooks::fail('amount', 'EXPENSE_AMOUNT');
            }
            $changes = [
                'status' => 'cancelled',
                'cancelled_on' => $data['date'],
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
                'cancel_note' => $data['note'],
            ];
        }
        DB::table('expense_plans')
            ->where('id', $id)
            ->update([...$changes, 'version' => $plan->version + 1]);
        Records::audit($actor, 'expense.'.$action, $id, ['note' => $data['note'], 'date' => $data['date']]);

        return $this->dto(Records::row('expense_plans', $id, false));
    }

    public function recordPayment(object $book, string $id, array $data, User $actor): array
    {
        $plan = app(AccountingBooks::class)->row('expense_plans', $id, $book);
        abort_unless((int) $plan->version === $data['version'], 409);
        if ($plan->status !== 'approved') {
            AccountingBooks::fail('status', 'EXPENSE_STATE');
        }
        $binding = app(TreasuryBindings::class)->usable($book, $data['binding_id']);
        $entry = app(AccountingBooks::class)->row('accounting_entries', $data['entry_id'], $book);
        $line = DB::table('accounting_entry_lines')
            ->where('id', $data['ledger_line_id'])
            ->where('entry_id', $entry->id)
            ->first();
        $side = $binding->inflow_side === 'debit' ? 'credit' : 'debit';
        if (
            ! $line ||
            $binding->treasury_account_id !== $plan->treasury_account_id ||
            $line->account_id !== $binding->account_id ||
            $entry->status !== 'posted' ||
            $entry->source_id ||
            $entry->reversal_of ||
            ! $line->{$side} ||
            DB::table('accounting_entries')->where('reversal_of', $entry->id)->exists() ||
            DB::table('accounting_entry_lines')
                ->where('entry_id', $entry->id)
                ->where('id', '!=', $line->id)
                ->whereIn(
                    'account_id',
                    DB::table('treasury_bindings')->where('book_id', $book->id)->select('account_id'),
                )
                ->exists()
        ) {
            AccountingBooks::fail('entry_id', 'EXPENSE_POSTING');
        }
        if (
            $book->mode === 'live' &&
            ((int) $entry->posted_by === $actor->id || (int) $plan->created_by === $actor->id)
        ) {
            AccountingBooks::fail('entry_id', 'EXPENSE_REVIEW');
        }
        if (
            DB::table('expense_payments')->where('entry_id', $entry->id)->exists() ||
            DB::table('financing_movements')->where('entry_id', $entry->id)->exists()
        ) {
            AccountingBooks::fail('entry_id', 'EXPENSE_USED');
        }
        $this->chronological($book, $plan, $entry->entry_date);
        $amount = (string) $line->{$side};
        if (
            $amount !== $data['accepted_amount'] ||
            BigInteger::of($amount)->isGreaterThan($this->balance($plan)['remaining'])
        ) {
            AccountingBooks::fail('amount', 'EXPENSE_AMOUNT');
        }
        $document = $this->evidence($data['document_id']);
        $snapshot = [
            'plan_id' => $id,
            'plan_sha256' => $plan->sha256,
            'book_id' => $book->id,
            'binding' => app(TreasuryBindings::class)->dto($binding),
            'entry_id' => $entry->id,
            'entry_sha256' => $entry->sha256,
            'entry_number' => $entry->number,
            'ledger_line_id' => (int) $line->id,
            'amount' => $amount,
            'posted_on' => $entry->entry_date,
            'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc()->toIso8601String(),
            'document' => ['id' => $document->id, 'sha256' => $document->sha256],
            'note' => $data['note'],
            'verification' => 'reviewed_manual_cash_posting',
        ];
        $payment = Records::id();
        DB::table('expense_payments')->insert([
            'id' => $payment,
            'plan_id' => $id,
            'book_id' => $book->id,
            'binding_id' => $binding->id,
            'entry_id' => $entry->id,
            'ledger_line_id' => $line->id,
            'amount' => $amount,
            'posted_on' => $entry->entry_date,
            'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc(),
            'document_id' => $document->id,
            'snapshot' => Records::json($snapshot),
            'sha256' => app(CommandBus::class)->fingerprint($snapshot),
            'created_by' => $actor->id,
            'created_at' => now(),
        ]);
        Records::audit($actor, 'expense.payment_recorded', $payment, ['entry_id' => $entry->id, 'plan_id' => $id]);

        return $this->paymentDto(Records::row('expense_payments', $payment, false));
    }

    public function reversePayment(object $book, string $id, string $payment, array $data, User $actor): array
    {
        $plan = app(AccountingBooks::class)->row('expense_plans', $id, $book);
        $p = app(AccountingBooks::class)->row('expense_payments', $payment, $book);
        abort_unless($p->plan_id === $id, 404);
        abort_unless((int) $p->version === $data['version'], 409);
        if ($p->status !== 'recorded' || ! hash_equals($p->sha256, $data['sha256'])) {
            AccountingBooks::fail('status', 'EXPENSE_STATE');
        }
        $this->chronological($book, $plan, $data['entry_date']);
        DB::table('expense_payments')
            ->where('id', $payment)
            ->update(['status' => 'reversing']);
        $entry = app(AccountingBooks::class)->row('accounting_entries', $p->entry_id, $book);
        $reversal = app(AccountingEntries::class)->reverse(
            $book,
            $entry,
            [...$data, 'version' => (int) $entry->version],
            $actor,
        );
        DB::table('expense_payments')
            ->where('id', $payment)
            ->update([
                'status' => 'reversed',
                'version' => 2,
                'reversal_entry_id' => $reversal['id'],
                'reversed_on' => $reversal['entry_date'],
                'reversed_by' => $actor->id,
                'reversed_at' => now(),
                'reversal_note' => $data['note'],
            ]);
        Records::audit($actor, 'expense.payment_reversed', $payment, [
            'reversal_entry_id' => $reversal['id'],
            'note' => $data['note'],
        ]);

        return $this->paymentDto(Records::row('expense_payments', $payment, false));
    }

    public function guardReversal(object $entry): void
    {
        if (
            DB::table('expense_payments')
                ->where(
                    fn ($q) => $q
                        ->where(fn ($v) => $v->where('entry_id', $entry->id)->where('status', 'recorded'))
                        ->orWhere('reversal_entry_id', $entry->id),
                )
                ->exists()
        ) {
            AccountingBooks::fail('entry_id', 'EXPENSE_LINKED_REVERSAL');
        }
    }

    public function balance(object $plan, ?string $asOf = null): array
    {
        $spent = BigInteger::zero();
        $reversed = BigInteger::zero();
        foreach (DB::table('expense_payments')->where('plan_id', $plan->id)->get() as $p) {
            if (! $asOf || $p->posted_on <= $asOf) {
                $spent = $spent->plus((string) $p->amount);
            }
            if ($p->status === 'reversed' && (! $asOf || $p->reversed_on <= $asOf)) {
                $reversed = $reversed->plus((string) $p->amount);
            }
        }
        $net = $spent->minus($reversed);
        $remaining = BigInteger::of((string) $plan->amount)->minus($net);
        $approved = $plan->approved_on && (! $asOf || $plan->approved_on <= $asOf);
        $cancelled = $plan->cancelled_on && (! $asOf || $plan->cancelled_on <= $asOf);

        return [
            'recorded' => (string) $spent,
            'reversed' => (string) $reversed,
            'net_spent' => (string) $net,
            'remaining' => (string) $remaining,
            'committed' => $approved && ! $cancelled ? (string) $remaining : '0',
            'cancelled_unspent' => $cancelled ? (string) $remaining : '0',
        ];
    }

    public function report(object $book, string $date, ?string $treasury): array
    {
        if ($treasury) {
            Records::row('treasury_accounts', $treasury, false);
        }
        $totals = array_fill_keys(
            ['recorded', 'reversed', 'net_spent', 'remaining', 'committed', 'cancelled_unspent'],
            BigInteger::zero(),
        );
        $count = 0;
        foreach (
            DB::table('expense_plans')
                ->where('book_id', $book->id)
                ->where('planned_on', '<=', $date)
                ->when($treasury, fn ($q) => $q->where('treasury_account_id', $treasury))
                ->cursor() as $p
        ) {
            $count++;
            foreach ($this->balance($p, $date) as $key => $amount) {
                $totals[$key] = $totals[$key]->plus($amount);
            }
        }

        return [
            'book_id' => $book->id,
            'mode' => $book->mode,
            'as_of' => $date,
            'treasury_account_id' => $treasury,
            'currency' => 'XAF',
            'scope' => 'expense_plans_only',
            'date_basis' => 'posted_cash_entry',
            'plans' => $count,
            'totals' => array_map(fn ($a) => (string) $a, $totals),
            'available_funds' => null,
            'availability_verified' => false,
            'external_payment_verified' => false,
            'provisional' => true,
        ];
    }

    public function dto(object $p): array
    {
        return [
            'id' => $p->id,
            'book_id' => $p->book_id,
            'treasury_account_id' => $p->treasury_account_id,
            'status' => $p->status,
            'version' => (int) $p->version,
            'snapshot' => json_decode($p->snapshot, true),
            'sha256' => $p->sha256,
            'created_by' => (int) $p->created_by,
            'approved_on' => $p->approved_on,
            'approved_by' => $p->approved_by === null ? null : (int) $p->approved_by,
            'approval_note' => $p->approval_note,
            'cancelled_on' => $p->cancelled_on,
            'cancelled_by' => $p->cancelled_by === null ? null : (int) $p->cancelled_by,
            'cancel_note' => $p->cancel_note,
            'balance' => $this->balance($p),
        ];
    }

    public function paymentDto(object $p): array
    {
        return [
            'id' => $p->id,
            'plan_id' => $p->plan_id,
            'book_id' => $p->book_id,
            'status' => $p->status,
            'version' => (int) $p->version,
            'snapshot' => json_decode($p->snapshot, true),
            'sha256' => $p->sha256,
            'created_by' => (int) $p->created_by,
            'reversal_entry_id' => $p->reversal_entry_id,
            'reversed_on' => $p->reversed_on,
            'reversed_by' => $p->reversed_by === null ? null : (int) $p->reversed_by,
            'reversal_note' => $p->reversal_note,
        ];
    }
}
