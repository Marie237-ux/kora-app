<?php

namespace App\Domain\Procurement;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class Payables
{
    public function createObligation(
        object $order,
        string $sourceType,
        string $sourceId,
        int $amount,
        string $issuedOn,
        User $actor,
    ): array {
        $id = Records::id();
        DB::table('supplier_obligations')->insert([
            'id' => $id,
            'supplier_id' => $order->supplier_id,
            'purchase_order_id' => $order->id,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'amount' => $amount,
            'terms_snapshot' => $order->contract_snapshot,
            'due_state' => 'awaiting_receipt',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->schedule(Records::row('supplier_obligations', $id), $issuedOn, $actor);
        Records::event('SupplierObligationRecognized', $id, [
            'id' => $id,
            'supplier_id' => $order->supplier_id,
            'amount' => (string) $amount,
        ]);

        return $this->obligationDto(Records::row('supplier_obligations', $id));
    }

    public function schedule(object $obligation, string $issuedOn, User $actor): void
    {
        if ($obligation->due_state === 'scheduled') {
            return;
        }
        $snapshot = json_decode($obligation->terms_snapshot, true);
        $terms = $snapshot['terms'];
        $anchor = match ($terms['due_basis']) {
            'invoice_date' => $issuedOn,
            'fixed_date' => $terms['fixed_due_on'],
            'first_receipt' => $this->firstReceipt($obligation->purchase_order_id),
        };
        if (! $anchor) {
            return;
        }
        $date =
            $terms['due_basis'] === 'first_receipt'
                ? CarbonImmutable::parse($anchor, 'UTC')->setTimezone('Africa/Douala')->startOfDay()
                : CarbonImmutable::parse($anchor, 'Africa/Douala');
        $parts = Money::apportion((int) $obligation->amount, array_column($terms['schedule'], 'basis_points'));
        foreach ($terms['schedule'] as $index => $installment) {
            DB::table('payable_schedule')->insert([
                'id' => Records::id(),
                'obligation_id' => $obligation->id,
                'version' => 1,
                'sequence' => $index + 1,
                'amount' => $parts[$index],
                'due_on' => $date->addDays($installment['offset_days'])->toDateString(),
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);
        }
        $snapshot['schedule_anchor'] = $date->toDateString();
        DB::table('supplier_obligations')
            ->where('id', $obligation->id)
            ->update(['due_state' => 'scheduled', 'terms_snapshot' => Records::json($snapshot), 'updated_at' => now()]);
    }

    public function scheduleAfterReceipt(object $order, User $actor): void
    {
        foreach (
            DB::table('supplier_obligations')
                ->where('purchase_order_id', $order->id)
                ->where('due_state', 'awaiting_receipt')
                ->orderBy('id')
                ->lockForUpdate()
                ->get() as $obligation
        ) {
            $issuedOn =
                $obligation->source_type === 'invoice'
                    ? Records::row('purchase_invoices', $obligation->source_id, false)->issued_on
                    : Records::row('consignment_statements', $obligation->source_id, false)->ends_on;
            $this->schedule($obligation, $issuedOn, $actor);
        }
    }

    private function firstReceipt(string $orderId): ?string
    {
        // Supplier locking serializes receipts. The audit sequence preserves recording
        // order even when SQL timestamps have the same second; a receipt currently
        // being inserted has no audit row yet and follows already recorded receipts.
        return DB::table('goods_receipts as r')
            ->leftJoin('audit_events as a', function ($join) {
                $join
                    ->on('a.resource_id', '=', DB::raw('CAST(r.id AS VARCHAR(100))'))
                    ->where('a.action', 'purchase.received');
            })
            ->where('r.purchase_order_id', $orderId)
            ->orderByRaw('CASE WHEN a.id IS NULL THEN 1 ELSE 0 END')
            ->orderBy('a.id')
            ->orderBy('r.created_at')
            ->orderBy('r.id')
            ->value('r.received_at');
    }

    public function reschedule(object $obligation, array $data, User $actor): array
    {
        Records::supplier($obligation->supplier_id);
        $obligation = Records::row('supplier_obligations', $obligation->id);
        abort_unless((int) $obligation->schedule_version === $data['version'], 409);
        app(PrivateDocuments::class)->requireClean($data['document_id'], $obligation->supplier_id, [
            'supplier_contract',
        ]);
        $amounts = array_map(fn ($line) => Money::parse($line['amount']), $data['installments']);
        if (Money::sum($amounts) !== (int) $obligation->amount) {
            Money::fail('installments', 'SCHEDULE_INVALID');
        }
        $version = $obligation->schedule_version + 1;
        foreach ($data['installments'] as $index => $installment) {
            DB::table('payable_schedule')->insert([
                'id' => Records::id(),
                'obligation_id' => $obligation->id,
                'version' => $version,
                'sequence' => $index + 1,
                'amount' => $amounts[$index],
                'due_on' => $installment['due_on'],
                'reason' => $data['reason'],
                'document_id' => $data['document_id'],
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);
        }
        DB::table('supplier_obligations')
            ->where('id', $obligation->id)
            ->update(['schedule_version' => $version, 'due_state' => 'scheduled', 'updated_at' => now()]);
        Records::audit($actor, 'payable.rescheduled', $obligation->id, [
            'version' => $version,
            'document_id' => $data['document_id'],
            'reason' => $data['reason'],
        ]);

        return $this->obligationDto(Records::row('supplier_obligations', $obligation->id));
    }

    public function outstanding(object $obligation, ?CarbonImmutable $asOf = null): int
    {
        $paid = DB::table('supplier_payment_allocations')->where('obligation_id', $obligation->id);
        $credits = DB::table('supplier_credit_allocations')->where('obligation_id', $obligation->id);
        if ($asOf) {
            $paid->where('created_at', '<=', $asOf);
            $credits->where('created_at', '<=', $asOf);
        }

        return (int) $obligation->amount - (int) $paid->sum('amount') - (int) $credits->sum('amount');
    }

    public function requestPayment(array $data, User $actor): array
    {
        $supplier = Records::supplier($data['supplier_id']);
        $account = Records::row('treasury_accounts', $data['account_id']);
        if ($supplier->status !== 'active' || ! $account->active) {
            Money::fail('account_id', 'ACCOUNT_INACTIVE');
        }
        $amount = Money::parse($data['amount']);
        if ($amount < 1) {
            Money::fail('amount', 'AMOUNT_INVALID');
        }
        $id = Records::id();
        DB::table('supplier_payments')->insert([
            'id' => $id,
            'supplier_id' => $supplier->id,
            'amount' => $amount,
            'account_id' => $account->id,
            'requested_amount' => $amount,
            'request_reference' => $data['reference'],
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'supplier_payment.requested', $id, [
            'amount' => (string) $amount,
            'account_id' => $account->id,
            'request_reference' => $data['reference'],
        ]);

        return $this->paymentDto(Records::row('supplier_payments', $id));
    }

    public function verifyPayment(string $id, array $data, User $actor): array
    {
        $initial = Records::row('supplier_payments', $id, false);
        Records::supplier($initial->supplier_id);
        $payment = Records::row('supplier_payments', $id);
        if ($payment->status !== 'requested') {
            Money::fail('status', 'PAYMENT_ALREADY_VERIFIED');
        }
        $evidence = app(PrivateDocuments::class)->requireClean($data['evidence_id'], $payment->supplier_id, [
            'payment_evidence',
        ]);
        $actual = Money::parse($data['amount']);
        if ($actual < 1) {
            Money::fail('amount', 'AMOUNT_INVALID');
        }
        $amounts = array_map(fn ($line) => Money::parse($line['amount']), $data['allocations']);
        $allocated = Money::sum($amounts);
        if ($allocated > $actual || (! $data['allow_advance'] && $allocated !== $actual)) {
            Money::fail('allocations', 'ALLOCATION_INVALID');
        }
        $obligations = DB::table('supplier_obligations')
            ->whereIn('id', array_column($data['allocations'], 'obligation_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        foreach ($data['allocations'] as $index => $allocation) {
            $obligation = $obligations->get($allocation['obligation_id']);
            if (
                ! $obligation ||
                $obligation->supplier_id !== $payment->supplier_id ||
                $amounts[$index] < 1 ||
                $this->outstanding($obligation) < $amounts[$index]
            ) {
                Money::fail('allocations', 'ALLOCATION_INVALID');
            }
            DB::table('supplier_payment_allocations')->insert([
                'id' => Records::id(),
                'payment_id' => $id,
                'obligation_id' => $obligation->id,
                'amount' => $amounts[$index],
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);
        }
        DB::table('supplier_payments')
            ->where('id', $id)
            ->update([
                'status' => 'verified',
                'evidence_id' => $evidence->id,
                'amount' => $actual,
                'reference' => $data['reference'],
                'reference_key' => mb_strtoupper(trim($data['reference'])),
                'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc(),
                'verified_by' => $actor->id,
                'verified_at' => now(),
                'updated_at' => now(),
            ]);
        Records::audit($actor, 'supplier_payment.verified', $id, [
            'evidence_sha256' => $evidence->sha256,
            'allocated' => (string) $allocated,
            'advance' => (string) ($actual - $allocated),
            'actual_amount' => (string) $actual,
        ]);
        Records::event('SupplierPaymentVerified', $id, ['id' => $id, 'amount' => (string) $actual]);

        return $this->paymentDto(Records::row('supplier_payments', $id));
    }

    public function allocateAdvance(string $paymentId, array $allocations, User $actor): array
    {
        $initial = Records::row('supplier_payments', $paymentId, false);
        Records::supplier($initial->supplier_id);
        $payment = Records::row('supplier_payments', $paymentId);
        if ($payment->status !== 'verified') {
            Money::fail('payment', 'PAYMENT_NOT_VERIFIED');
        }
        $available =
            $payment->amount -
            (int) DB::table('supplier_payment_allocations')->where('payment_id', $paymentId)->sum('amount');
        $amounts = array_map(fn ($line) => Money::parse($line['amount']), $allocations);
        if (Money::sum($amounts) > $available) {
            Money::fail('amount', 'ALLOCATION_INVALID');
        }
        foreach (collect($allocations)->sortBy('obligation_id')->values() as $allocation) {
            $obligation = Records::row('supplier_obligations', $allocation['obligation_id']);
            $amount = Money::parse($allocation['amount']);
            if (
                $obligation->supplier_id !== $payment->supplier_id ||
                $amount < 1 ||
                $this->outstanding($obligation) < $amount
            ) {
                Money::fail('allocations', 'ALLOCATION_INVALID');
            }
            DB::table('supplier_payment_allocations')->insert([
                'id' => Records::id(),
                'payment_id' => $paymentId,
                'obligation_id' => $obligation->id,
                'amount' => $amount,
                'actor_id' => $actor->id,
                'created_at' => now(),
            ]);
        }
        Records::audit($actor, 'supplier_payment.advance_allocated', $paymentId, ['allocations' => $allocations]);

        return $this->paymentDto($payment);
    }

    public function credit(array $data, User $actor): array
    {
        $invoice = Records::row('purchase_invoices', $data['invoice_id'], false);
        Records::supplier($invoice->supplier_id);
        $invoice = Records::row('purchase_invoices', $invoice->id);
        $amount = Money::parse($data['amount']);
        app(PrivateDocuments::class)->requireClean($data['document_id'], $invoice->supplier_id, ['supplier_invoice']);
        if (
            $invoice->status !== 'approved' ||
            $amount < 1 ||
            $amount > $invoice->amount - DB::table('supplier_credits')->where('invoice_id', $invoice->id)->sum('amount')
        ) {
            Money::fail('amount', 'CREDIT_INVALID');
        }
        if (! empty($data['return_id'])) {
            $return = Records::row('supplier_returns', $data['return_id'], false);
            $lot = Records::row('stock_lots', $return->lot_id, false);
            $line = Records::row('purchase_lines', $lot->purchase_line_id, false);
            if ($line->purchase_order_id !== $invoice->purchase_order_id) {
                Money::fail('return_id', 'CREDIT_INVALID');
            }
        }
        $id = Records::id();
        DB::table('supplier_credits')->insert([
            'id' => $id,
            'supplier_id' => $invoice->supplier_id,
            'return_id' => $data['return_id'] ?? null,
            'invoice_id' => $invoice->id,
            'amount' => $amount,
            'reference' => $data['reference'],
            'reason' => $data['reason'],
            'document_id' => $data['document_id'],
            'approved_by' => $actor->id,
            'created_at' => now(),
        ]);
        $obligation = DB::table('supplier_obligations')
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->lockForUpdate()
            ->first();
        $applied = min($amount, $this->outstanding($obligation));
        if ($applied > 0) {
            DB::table('supplier_credit_allocations')->insert([
                'id' => Records::id(),
                'credit_id' => $id,
                'obligation_id' => $obligation->id,
                'amount' => $applied,
                'created_at' => now(),
            ]);
        }
        Records::audit($actor, 'supplier_credit.approved', $id, [
            'amount' => (string) $amount,
            'unapplied' => (string) ($amount - $applied),
        ]);

        return [
            'id' => $id,
            'amount' => (string) $amount,
            'applied' => (string) $applied,
            'unapplied' => (string) ($amount - $applied),
        ];
    }

    public function allocateCredit(string $id, array $allocations, User $actor): array
    {
        $initial = Records::row('supplier_credits', $id, false);
        Records::supplier($initial->supplier_id);
        $credit = Records::row('supplier_credits', $id);
        $applied = (int) DB::table('supplier_credit_allocations')->where('credit_id', $id)->sum('amount');
        $total = Money::sum(array_map(fn ($a) => Money::parse($a['amount']), $allocations));
        if ($total < 1 || $total > $credit->amount - $applied) {
            Money::fail('allocations', 'ALLOCATION_INVALID');
        }
        foreach (collect($allocations)->sortBy('obligation_id')->values() as $allocation) {
            $obligation = Records::row('supplier_obligations', $allocation['obligation_id']);
            $amount = Money::parse($allocation['amount']);
            if (
                $obligation->supplier_id !== $credit->supplier_id ||
                $amount < 1 ||
                $this->outstanding($obligation) < $amount
            ) {
                Money::fail('allocations', 'ALLOCATION_INVALID');
            }
            DB::table('supplier_credit_allocations')->insert([
                'id' => Records::id(),
                'credit_id' => $id,
                'obligation_id' => $obligation->id,
                'amount' => $amount,
                'created_at' => now(),
            ]);
        }
        Records::audit($actor, 'supplier_credit.allocated', $id, ['allocations' => $allocations]);

        return [
            'id' => $id,
            'amount' => (string) $credit->amount,
            'applied' => (string) ($applied + $total),
            'unapplied' => (string) ($credit->amount - $applied - $total),
        ];
    }

    /** Settlements cover the earliest due installment first, including installments outside a requested page/date range. */
    public function installments(object $obligation): array
    {
        $covered = $obligation->amount - $this->outstanding($obligation);
        $rows = [];
        foreach (
            DB::table('payable_schedule')
                ->where('obligation_id', $obligation->id)
                ->where('version', $obligation->schedule_version)
                ->orderBy('due_on')
                ->orderBy('sequence')
                ->get() as $s
        ) {
            $settled = min($covered, $s->amount);
            $covered -= $settled;
            $remaining = $s->amount - $settled;
            $rows[] = [
                'id' => $s->id,
                'obligation_id' => $obligation->id,
                'supplier_id' => $obligation->supplier_id,
                'amount' => (string) $s->amount,
                'settled' => (string) $settled,
                'outstanding' => (string) $remaining,
                'due_on' => $s->due_on,
                'sequence' => (int) $s->sequence,
                'version' => (int) $s->version,
                'status' => $remaining === 0
                        ? 'settled'
                        : ($s->due_on < now('Africa/Douala')->toDateString()
                            ? 'past_due'
                            : 'pending'),
            ];
        }

        return $rows;
    }

    public function balances(string $supplierId, ?CarbonImmutable $asOf = null): array
    {
        $asOf ??= CarbonImmutable::now('UTC');
        $obligations = DB::table('supplier_obligations')
            ->where('supplier_id', $supplierId)
            ->where('created_at', '<=', $asOf)
            ->get();
        $outstanding = [];
        $due = [];
        $awaiting = [];
        foreach ($obligations as $obligation) {
            $open = $this->outstanding($obligation, $asOf);
            $outstanding[] = $open;
            $covered = $obligation->amount - $open;
            $version = DB::table('payable_schedule')
                ->where('obligation_id', $obligation->id)
                ->where('created_at', '<=', $asOf)
                ->max('version');
            $schedule = $version
                ? DB::table('payable_schedule')
                    ->where('obligation_id', $obligation->id)
                    ->where('version', $version)
                    ->orderBy('due_on')
                    ->orderBy('sequence')
                    ->get()
                : collect();
            if ($schedule->isEmpty()) {
                $awaiting[] = $open;
            }
            foreach ($schedule as $installment) {
                $settled = min($covered, $installment->amount);
                $covered -= $settled;
                if ($installment->due_on < $asOf->setTimezone('Africa/Douala')->toDateString()) {
                    $due[] = $installment->amount - $settled;
                }
            }
        }
        $payments = DB::table('supplier_payments')
            ->where('supplier_id', $supplierId)
            ->where('status', 'verified')
            ->where('verified_at', '<=', $asOf)
            ->get();
        $advances = [];
        $paid = [];
        foreach ($payments as $payment) {
            $paid[] = (int) $payment->amount;
            $advances[] =
                $payment->amount -
                (int) DB::table('supplier_payment_allocations')
                    ->where('payment_id', $payment->id)
                    ->where('created_at', '<=', $asOf)
                    ->sum('amount');
        }
        $credits = DB::table('supplier_credits')
            ->where('supplier_id', $supplierId)
            ->where('created_at', '<=', $asOf)
            ->get();
        $unapplied = [];
        foreach ($credits as $credit) {
            $unapplied[] =
                $credit->amount -
                (int) DB::table('supplier_credit_allocations')
                    ->where('credit_id', $credit->id)
                    ->where('created_at', '<=', $asOf)
                    ->sum('amount');
        }

        return [
            'currency' => 'XAF',
            'known_as_of' => $asOf->toIso8601String(),
            'recognized_total' => (string) Money::sum($obligations->map(fn ($o) => (int) $o->amount)->all()),
            'invoiced_total' => (string) Money::sum(
                $obligations->where('source_type', 'invoice')->map(fn ($o) => (int) $o->amount)->all(),
            ),
            'consignment_statement_total' => (string) Money::sum(
                $obligations->where('source_type', 'consignment_statement')->map(fn ($o) => (int) $o->amount)->all(),
            ),
            'outstanding_total' => (string) Money::sum($outstanding),
            'past_due_total' => (string) Money::sum($due),
            'awaiting_receipt_schedule_total' => (string) Money::sum($awaiting),
            'verified_paid_total' => (string) Money::sum($paid),
            'advance_total' => (string) Money::sum($advances),
            'unapplied_credit_total' => (string) Money::sum($unapplied),
        ];
    }

    public function commitment(string $supplierId): int
    {
        return Money::sum([
            (int) $this->balances($supplierId)['outstanding_total'],
            app(PurchaseCommitment::class)->unrecognized($supplierId),
        ]);
    }

    public function obligationDto(object $row): array
    {
        return [
            'id' => $row->id,
            'supplier_id' => $row->supplier_id,
            'purchase_order_id' => $row->purchase_order_id,
            'source_type' => $row->source_type,
            'source_id' => $row->source_id,
            'amount' => (string) $row->amount,
            'outstanding' => (string) $this->outstanding($row),
            'terms_snapshot' => json_decode($row->terms_snapshot, true),
            'due_state' => $row->due_state,
            'schedule_version' => (int) $row->schedule_version,
            'schedule' => $this->installments($row),
            'created_at' => Records::iso($row->created_at),
        ];
    }

    public function paymentDto(object $row): array
    {
        $allocated = (int) DB::table('supplier_payment_allocations')->where('payment_id', $row->id)->sum('amount');

        return [
            'id' => $row->id,
            'supplier_id' => $row->supplier_id,
            'account_id' => $row->account_id,
            'reference' => $row->reference,
            'amount' => (string) $row->amount,
            'requested_amount' => (string) $row->requested_amount,
            'request_reference' => $row->request_reference,
            'status' => $row->status,
            'allocated' => (string) $allocated,
            'advance' => $row->status === 'verified' ? (string) ($row->amount - $allocated) : '0',
            'paid_at' => Records::iso($row->paid_at),
            'verified_at' => Records::iso($row->verified_at),
            'evidence_id' => $row->evidence_id,
        ];
    }
}
