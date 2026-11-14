<?php

namespace App\Domain\Documents;

use App\Domain\Orders\Orders;
use App\Domain\Procurement\Records;
use App\Domain\Shared\DocumentNumbers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Invoices and credit notes. A draft can be previewed; issuing allocates the number and freezes everything the
 * document shows. An issued document is never edited or deleted: a correction is a credit note linked to it.
 * The French and English versions are two renderings of the same frozen document and number.
 */
final class CustomerDocuments
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('documents.'.$code)]);
    }

    /** The caller holds the order lock. One invoice per order. */
    public function createInvoice(object $order, User $actor): array
    {
        if ($order->status !== 'paid') {
            self::fail('order', 'ORDER_NOT_PAID');
        }
        abort_if(
            DB::table('customer_documents')->where('order_id', $order->id)->where('kind', 'invoice')->exists(),
            409,
        );

        return $this->insert($order->id, 'invoice', (int) $order->total, null, null, $actor);
    }

    /** A credit note reduces an issued invoice for a verified refund, never beyond what the invoice still holds. */
    public function createCreditNote(object $refund, User $actor): array
    {
        if ($refund->status !== 'paid') {
            self::fail('refund', 'REFUND_NOT_PAID');
        }
        $invoice = DB::table('customer_documents')
            ->where('order_id', $refund->order_id)
            ->where('kind', 'invoice')
            ->where('status', 'issued')
            ->lockForUpdate()
            ->first();
        if (! $invoice) {
            self::fail('invoice', 'INVOICE_REQUIRED');
        }
        abort_if(DB::table('customer_documents')->where('refund_id', $refund->id)->exists(), 409);
        $credited = (int) DB::table('customer_documents')->where('invoice_id', $invoice->id)->sum('total');
        if ((int) $refund->amount > (int) $invoice->total - $credited) {
            // An overpayment refund returns money that was never part of the sale: no credit note for it.
            self::fail('refund', 'CREDIT_EXCEEDS_INVOICE');
        }

        return $this->insert(
            $refund->order_id,
            'credit_note',
            (int) $refund->amount,
            $invoice->id,
            $refund->id,
            $actor,
        );
    }

    private function insert(
        string $orderId,
        string $kind,
        int $total,
        ?string $invoiceId,
        ?string $refundId,
        User $actor,
    ): array {
        $id = Records::id();
        DB::table('customer_documents')->insert([
            'id' => $id,
            'order_id' => $orderId,
            'kind' => $kind,
            'status' => 'draft',
            'invoice_id' => $invoiceId,
            'refund_id' => $refundId,
            'total' => $total,
            'demo' => app(BillingIdentity::class)->current()['missing'] !== [],
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(Orders::class)->event($orderId, $kind.'_drafted', $actor, ['document_id' => $id]);

        return $this->dto(Records::row('customer_documents', $id, false));
    }

    public function issue(object $document, User $actor): array
    {
        if ($document->status !== 'draft') {
            self::fail('document', 'DOCUMENT_ALREADY_ISSUED');
        }
        $identity = app(BillingIdentity::class)->current();
        $demo = $identity['missing'] !== [];
        $year = CarbonImmutable::now((string) config('kora.business_timezone'))->year;
        $type = $document->kind === 'invoice' ? 'FAC' : 'AV';
        // Demonstration documents use their own sequence so that the real numbering starts clean.
        $number = app(DocumentNumbers::class)->next(
            ($demo ? 'demo-' : '').$document->kind.':'.$year,
            ($demo ? 'DEMO-' : '').$type.'-'.$year.'-',
        );
        $snapshot = $this->snapshot($document, $identity, $number, $demo);
        $json = Records::json($snapshot);
        DB::table('customer_documents')
            ->where('id', $document->id)
            ->update([
                'status' => 'issued',
                'number' => $number,
                'demo' => $demo,
                'snapshot' => $json,
                'snapshot_sha256' => hash('sha256', $json),
                'issued_by' => $actor->id,
                'issued_at' => now(),
                'version' => $document->version + 1,
                'updated_at' => now(),
            ]);
        app(Orders::class)->event($document->order_id, $document->kind.'_issued', $actor, ['number' => $number]);
        Records::audit($actor, 'document.issued', $document->id, ['number' => $number, 'demo' => $demo]);
        Records::eventOnce(
            $document->kind === 'invoice' ? 'CustomerInvoiceIssued' : 'CustomerCreditNoteIssued',
            $document->id,
            ['id' => $document->id],
        );

        return $this->dto(Records::row('customer_documents', $document->id, false));
    }

    /** Built from the order's own frozen lines and the identity in force at issue time. */
    public function snapshot(object $document, array $identity, ?string $number, bool $demo): array
    {
        $order = Records::row('orders', $document->order_id, false);
        $customer = DB::table('users')
            ->where('id', $order->user_id)
            ->first(['name', 'email']);
        $address = json_decode($order->address_snapshot, true);
        $delivery = json_decode($order->delivery_snapshot, true);
        $vatRate = $identity['tax_regime'] === 'vat' ? (int) $identity['vat_rate_basis_points'] : null;
        $base = [
            'kind' => $document->kind,
            'number' => $number,
            'demo' => $demo,
            'issued_at' => now()->toIso8601String(),
            'currency' => 'XAF',
            'seller' => array_diff_key($identity, array_flip(['version', 'missing'])),
            'logo_sha256' => app(DocumentAssets::class)->freezeLogo(),
            'buyer' => [
                'name' => $customer->name,
                'email' => $customer->email,
                'contact_name' => $address['contact_name'] ?? null,
                'district' => $address['district'] ?? null,
                'landmark' => $address['landmark'] ?? null,
            ],
            'order' => ['number' => $order->number, 'paid_at' => Records::iso($order->paid_at)],
        ];
        if ($order->professional_billing_snapshot) {
            $buyer = json_decode($order->professional_billing_snapshot, true);
            $base['buyer'] = [
                ...$base['buyer'],
                'name' => $buyer['legal_name'],
                'email' => $buyer['email'] ?? null,
                'billing_address' => $buyer['billing_address'],
                'niu' => $buyer['niu'] ?? null,
                'rccm' => $buyer['rccm'] ?? null,
            ];
        }
        if ($document->kind === 'credit_note') {
            $invoice = Records::row('customer_documents', $document->invoice_id, false);
            $refund = Records::row('customer_refunds', $document->refund_id, false);

            return [
                ...$base,
                'invoice_number' => $invoice->number,
                'reason' => $refund->reason,
                'refund' => [
                    'channel' => $refund->channel,
                    'reference' => $refund->reference,
                    'paid_at' => Records::iso($refund->paid_at),
                ],
                'lines' => [],
                'totals' => $this->totals((int) $document->total, $vatRate),
            ];
        }

        return [
            ...$base,
            'lines' => DB::table('order_lines')
                ->where('order_id', $order->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(function ($line) {
                    $snapshot = json_decode($line->snapshot, true);

                    return [
                        'name' => $snapshot['name'],
                        'sku' => $snapshot['sku'],
                        'shop' => $snapshot['shop']['name'],
                        'warranty' => $snapshot['warranty'],
                        'quantity' => (int) $line->quantity,
                        'unit_price' => (string) $line->unit_price,
                        'line_total' => (string) $line->line_total,
                        'loyalty_discount' => (string) $line->loyalty_discount,
                        'gross_line_total' => (string) ((int) $line->line_total + (int) $line->loyalty_discount),
                    ];
                })
                ->all(),
            'delivery' => [
                'total' => (string) $order->delivery_total,
                'gross_total' => (string) ($delivery['gross_total'] ?? $order->delivery_total),
                'promotion' => empty($delivery['promotion'])
                    ? null
                    : [
                        'name' => $delivery['promotion']['name'],
                        'discount' => (string) $delivery['promotion']['discount'],
                    ],
                'breakdown' => array_map(
                    fn ($item) => [...$item, 'amount' => (string) $item['amount']],
                    $delivery['breakdown'],
                ),
            ],
            'payments' => DB::table('payment_attempts')
                ->where('order_id', $order->id)
                ->where('status', 'confirmed')
                ->orderBy('confirmed_at')
                ->get()
                ->map(
                    fn ($a) => [
                        'provider' => $a->provider,
                        'reference' => $a->provider_transaction_id,
                        'amount' => (string) $a->received_amount,
                        'confirmed_at' => Records::iso($a->confirmed_at),
                    ],
                )
                ->all(),
            'totals' => [...$this->totals((int) $order->total, $vatRate), 'items' => (string) $order->items_total],
        ];
    }

    /**
     * Prices are tax-inclusive public prices. VAT is shown only under a VAT regime, extracted from the total and
     * rounded half up to the franc; computed without multiplying the full amount, so no integer overflow.
     */
    public function totals(int $total, ?int $rate): array
    {
        if (! $rate) {
            return [
                'total' => (string) $total,
                'vat_rate_basis_points' => null,
                'vat' => null,
                'excluding_vat' => null,
            ];
        }
        $divisor = 10000 + $rate;
        $vat = intdiv($total, $divisor) * $rate + intdiv(($total % $divisor) * $rate + intdiv($divisor, 2), $divisor);

        return [
            'total' => (string) $total,
            'vat_rate_basis_points' => $rate,
            'vat' => (string) $vat,
            'excluding_vat' => (string) ($total - $vat),
        ];
    }

    public function dto(object $document): array
    {
        return [
            'id' => $document->id,
            'order_id' => $document->order_id,
            'kind' => $document->kind,
            'status' => $document->status,
            'number' => $document->number,
            'invoice_id' => $document->invoice_id,
            'refund_id' => $document->refund_id,
            'total' => (string) $document->total,
            'demo' => (bool) $document->demo,
            'snapshot_sha256' => $document->snapshot_sha256,
            'issued_at' => Records::iso($document->issued_at),
            'version' => (int) $document->version,
            'created_at' => Records::iso($document->created_at),
        ];
    }
}
