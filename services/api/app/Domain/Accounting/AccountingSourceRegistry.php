<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Verified existing facts only. No account numbers, agent/reseller scheme, VAT deduction or fees are guessed. */
final class AccountingSourceRegistry
{
    public const TYPES = [
        'customer_invoice',
        'customer_credit_note',
        'customer_payment',
        'customer_refund',
        'supplier_obligation',
        'supplier_payment',
        'goods_receipt',
    ];

    public function measures(string $type): array
    {
        return in_array($type, ['customer_invoice', 'customer_credit_note'], true)
            ? ['total', 'net', 'vat', ...$type === 'customer_invoice' ? ['items', 'delivery', 'loyalty_discount'] : []]
            : ['total'];
    }

    private function customer(object $order): array
    {
        $profile = $order->professional_quote_revision_id
            ? DB::table('professional_quote_revisions AS r')
                ->join('professional_quotes AS q', 'q.id', '=', 'r.quote_id')
                ->where('r.id', $order->professional_quote_revision_id)
                ->value('q.profile_id')
            : null;

        return ['type' => 'customer', 'id' => $profile ? 'professional:'.$profile : 'user:'.$order->user_id];
    }

    public function read(string $type, string $id): array
    {
        if (! in_array($type, self::TYPES, true)) {
            AccountingBooks::fail('source_type', 'SOURCE_NOT_ELIGIBLE');
        }
        $documents = [];
        $demo = false;
        $metadata = [];
        if (in_array($type, ['customer_invoice', 'customer_credit_note'], true)) {
            $r = Records::row('customer_documents', $id, false);
            if (
                $r->status !== 'issued' ||
                $r->kind !== ($type === 'customer_invoice' ? 'invoice' : 'credit_note') ||
                ! hash_equals($r->snapshot_sha256, hash('sha256', $r->snapshot))
            ) {
                AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
            }
            $snapshot = json_decode($r->snapshot, true);
            $total = Money::parse($snapshot['totals']['total']);
            $measures = [
                'total' => (string) $total,
                'net' => $snapshot['totals']['excluding_vat'] ?? (string) $total,
                'vat' => $snapshot['totals']['vat'] ?? '0',
            ];
            if ($type === 'customer_invoice') {
                $measures += [
                    'items' => $snapshot['totals']['items'],
                    'delivery' => $snapshot['delivery']['total'],
                    'loyalty_discount' => (string) Money::sum(
                        array_map(fn ($l) => (int) ($l['loyalty_discount'] ?? 0), $snapshot['lines']),
                    ),
                ];
            }
            $occurred = $r->issued_at;
            $reference = $r->number;
            $party = $this->customer(Records::row('orders', $r->order_id, false));
            $demo = (bool) $r->demo;
            $metadata = ['order_id' => $r->order_id, 'document_sha256' => $r->snapshot_sha256, 'document' => $snapshot];
        } elseif ($type === 'customer_payment') {
            $r = Records::row('payment_attempts', $id, false);
            if ($r->testmode !== null && (bool) $r->testmode) {
                throw new AccountingSourceRejected('TESTMODE_SOURCE');
            }
            if (
                $r->status !== 'confirmed' ||
                ($r->testmode !== false && (int) $r->testmode !== 0) ||
                $r->testmode === null ||
                ! $r->provider_transaction_id ||
                (int) $r->received_amount < 1 ||
                $r->currency !== 'XAF'
            ) {
                AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
            }
            $measures = ['total' => (string) $r->received_amount];
            $occurred = $r->confirmed_at;
            $reference = $r->provider_transaction_id;
            $party = $this->customer(Records::row('orders', $r->order_id, false));
            $metadata = [
                'order_id' => $r->order_id,
                'provider' => $r->provider,
                'provider_transaction_id' => $reference,
                'provider_fees' => null,
                'fees_status' => 'not_reconciled',
            ];
        } elseif ($type === 'customer_refund') {
            $r = Records::row('customer_refunds', $id, false);
            if ($r->status !== 'paid') {
                AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
            }
            $measures = ['total' => (string) $r->amount];
            $occurred = $r->paid_at;
            $reference = $r->reference;
            $party = $this->customer(Records::row('orders', $r->order_id, false));
            $documents = [$r->evidence_id];
            $metadata = ['order_id' => $r->order_id, 'channel' => $r->channel];
        } elseif ($type === 'supplier_obligation') {
            $r = Records::row('supplier_obligations', $id, false);
            if ($r->amount < 1) {
                AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
            }
            $measures = ['total' => (string) $r->amount];
            $occurred = $r->created_at;
            $reference = $r->source_type.':'.$r->source_id;
            $party = ['type' => 'supplier', 'id' => 'supplier:'.$r->supplier_id];
            if ($r->source_type === 'invoice') {
                $invoice = Records::row('purchase_invoices', $r->source_id, false);
                if ($invoice->status !== 'approved') {
                    AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
                }
                $documents = [$invoice->document_id];
                $reference = $invoice->number;
            }
            $metadata = [
                'purchase_order_id' => $r->purchase_order_id,
                'origin_type' => $r->source_type,
                'origin_id' => $r->source_id,
                // Due-date rescheduling changes the obligation's terms_snapshot, not the original recognition.
                'purchase_terms' => json_decode(
                    Records::row('purchase_orders', $r->purchase_order_id, false)->contract_snapshot,
                    true,
                ),
            ];
        } elseif ($type === 'supplier_payment') {
            $r = Records::row('supplier_payments', $id, false);
            if ($r->status !== 'verified' || $r->amount < 1) {
                AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
            }
            $measures = ['total' => (string) $r->amount];
            $occurred = $r->paid_at;
            $reference = $r->reference;
            $party = ['type' => 'supplier', 'id' => 'supplier:'.$r->supplier_id];
            $documents = [$r->evidence_id];
            $metadata = [
                'treasury_account_id' => $r->account_id,
                'classification' => 'cash_outflow_requires_mapping',
                'advance_allocation_not_inferred' => true,
            ];
        } else {
            $r = Records::row('goods_receipts', $id, false);
            $purchase = Records::row('purchase_orders', $r->purchase_order_id, false);
            if ($purchase->mode === 'consignment') {
                throw new AccountingSourceRejected('CONSIGNMENT_NOT_OWNED');
            }
            $rows = DB::table('receipt_lines')->where('receipt_id', $id)->orderBy('id')->get();
            $value = 0;
            $accepted = [];
            foreach ($rows as $line) {
                if ($line->quality === 'available') {
                    $quantity = max(0, (int) $line->quantity - (int) $line->excess_quantity);
                    $value = Money::sum([$value, Money::multiply((int) $line->unit_cost, $quantity)]);
                    $accepted[] = [
                        'receipt_line_id' => $line->id,
                        'quantity' => $quantity,
                        'unit_cost' => (string) $line->unit_cost,
                    ];
                }
            }
            if ($value < 1) {
                throw new AccountingSourceRejected('NO_ACCEPTED_VALUE');
            }
            $measures = ['total' => (string) $value];
            $occurred = $r->received_at;
            $reference = $r->reference;
            $party = ['type' => 'supplier', 'id' => 'supplier:'.$purchase->supplier_id];
            $documents = [$r->document_id];
            $metadata = [
                'purchase_order_id' => $purchase->id,
                'accepted_lines' => $accepted,
                'basis' => 'accepted_actual_receipt_cost',
                'consignment_excluded' => true,
                'subsequent_valuation_adjustments_not_inferred' => true,
            ];
        }
        foreach ($measures as $amount) {
            Money::parse($amount);
        }
        if ((int) $measures['total'] < 1 || ! $occurred) {
            AccountingBooks::fail('resource_id', 'SOURCE_NOT_ELIGIBLE');
        }

        return [
            'source_type' => $type,
            'resource_id' => $id,
            'occurred_at' => CarbonImmutable::parse($occurred, 'UTC')->toIso8601String(),
            'event_date' => CarbonImmutable::parse($occurred, 'UTC')->setTimezone('Africa/Douala')->toDateString(),
            'currency' => 'XAF',
            'reference' => (string) $reference,
            'demo' => $demo,
            'measures' => $measures,
            'party' => $party,
            'document_ids' => array_values(array_filter($documents)),
            'metadata' => $metadata,
        ];
    }
}
