<?php

namespace App\Domain\Procurement;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class SupplierLedger
{
    /** All SQL projections below are fixed server expressions, never supplied by a caller. */
    public function query(string $supplierId, CarbonImmutable $asOf): Builder
    {
        $events = [];
        $add = function (
            Builder $q,
            string $kind,
            string $id,
            string $resource,
            string $order,
            string $reference,
            string $amount,
            string $quantity,
            string $document,
            string $date,
        ) use (&$events) {
            $events[] = $q->selectRaw(
                "CAST($id AS VARCHAR(100)) AS event_id, ? AS kind, CAST($resource AS VARCHAR(100)) AS resource_id, CAST($order AS VARCHAR(100)) AS purchase_order_id, CAST($reference AS VARCHAR(150)) AS reference, CAST($amount AS BIGINT) AS amount, CAST($quantity AS BIGINT) AS quantity, CAST($document AS VARCHAR(100)) AS document_id, $date AS occurred_at",
                [$kind],
            );
        };
        $add(
            DB::table('purchase_orders as p')->where('p.supplier_id', $supplierId),
            'purchase_created',
            'p.id',
            'p.id',
            'p.id',
            'p.reference',
            'p.expected_total',
            'NULL',
            'NULL',
            'p.created_at',
        );
        $add(
            DB::table('purchase_orders as p')->where('p.supplier_id', $supplierId)->whereNotNull('p.confirmed_at'),
            'purchase_confirmed',
            'p.id',
            'p.id',
            'p.id',
            'p.supplier_confirmation_reference',
            'NULL',
            'NULL',
            'NULL',
            'p.confirmed_at',
        );
        $receipts = DB::table('goods_receipts as r')
            ->join('purchase_orders as p', 'p.id', '=', 'r.purchase_order_id')
            ->where('p.supplier_id', $supplierId);
        $add(
            $receipts,
            'goods_received',
            'r.id',
            'r.id',
            'p.id',
            'r.reference',
            'NULL',
            '(SELECT SUM(rl.quantity) FROM receipt_lines rl WHERE rl.receipt_id = r.id)',
            'r.document_id',
            'r.created_at',
        );
        $add(
            DB::table('supplier_obligations as o')->where('o.supplier_id', $supplierId),
            'obligation_recognized',
            'o.id',
            'o.id',
            'o.purchase_order_id',
            'o.source_type',
            'o.amount',
            'NULL',
            'NULL',
            'o.created_at',
        );
        $add(
            DB::table('supplier_payments as x')->where('x.supplier_id', $supplierId)->where('x.status', 'verified'),
            'payment_verified',
            'x.id',
            'x.id',
            'NULL',
            'x.reference',
            'x.amount',
            'NULL',
            'x.evidence_id',
            'x.verified_at',
        );
        $add(
            DB::table('supplier_payment_allocations as a')
                ->join('supplier_obligations as o', 'o.id', '=', 'a.obligation_id')
                ->where('o.supplier_id', $supplierId),
            'payment_allocated',
            'a.id',
            'a.payment_id',
            'o.purchase_order_id',
            'NULL',
            'a.amount',
            'NULL',
            'NULL',
            'a.created_at',
        );
        $add(
            DB::table('supplier_credits as c')
                ->where('c.supplier_id', $supplierId)
                ->join('purchase_invoices as i', 'i.id', '=', 'c.invoice_id'),
            'credit_approved',
            'c.id',
            'c.id',
            'i.purchase_order_id',
            'c.reference',
            'c.amount',
            'NULL',
            'c.document_id',
            'c.created_at',
        );
        $add(
            DB::table('supplier_credit_allocations as a')
                ->join('supplier_obligations as o', 'o.id', '=', 'a.obligation_id')
                ->where('o.supplier_id', $supplierId),
            'credit_allocated',
            'a.id',
            'a.credit_id',
            'o.purchase_order_id',
            'NULL',
            'a.amount',
            'NULL',
            'NULL',
            'a.created_at',
        );
        $add(
            DB::table('supplier_returns as r')
                ->join('stock_lots as l', 'l.id', '=', 'r.lot_id')
                ->join('purchase_lines as pl', 'pl.id', '=', 'l.purchase_line_id')
                ->where('l.supplier_id', $supplierId),
            'goods_returned',
            'r.id',
            'r.id',
            'pl.purchase_order_id',
            'r.supplier_reference',
            'NULL',
            'r.quantity',
            'r.document_id',
            'r.created_at',
        );
        $add(
            DB::table('payable_schedule as s')
                ->join('supplier_obligations as o', 'o.id', '=', 's.obligation_id')
                ->where('o.supplier_id', $supplierId)
                ->where('s.version', '>', 1),
            'schedule_renegotiated',
            's.id',
            's.obligation_id',
            'o.purchase_order_id',
            'NULL',
            's.amount',
            'NULL',
            's.document_id',
            's.created_at',
        );
        $add(
            DB::table('supply_contract_revisions as r')
                ->join('supply_contracts as c', 'c.id', '=', 'r.contract_id')
                ->where('c.supplier_id', $supplierId),
            'contract_revision',
            'r.id',
            'r.contract_id',
            'NULL',
            'r.action',
            'NULL',
            'NULL',
            'NULL',
            'r.created_at',
        );
        $add(
            DB::table('purchase_invoice_revisions as r')
                ->join('purchase_invoices as i', 'i.id', '=', 'r.invoice_id')
                ->where('i.supplier_id', $supplierId),
            'invoice_revision',
            'r.id',
            'r.invoice_id',
            'i.purchase_order_id',
            'r.action',
            'NULL',
            'NULL',
            'NULL',
            'r.created_at',
        );
        $add(
            DB::table('receipt_overage_approvals as a')
                ->join('purchase_lines as l', 'l.id', '=', 'a.purchase_line_id')
                ->join('purchase_orders as p', 'p.id', '=', 'l.purchase_order_id')
                ->where('p.supplier_id', $supplierId),
            'overage_accepted',
            'a.id',
            'a.id',
            'p.id',
            'NULL',
            'NULL',
            'a.quantity',
            'a.document_id',
            'a.created_at',
        );
        $union = array_shift($events);
        foreach ($events as $q) {
            $union->unionAll($q);
        }

        return DB::query()
            ->fromSub($union, 'supplier_ledger')
            ->where('occurred_at', '<=', $asOf)
            ->orderByDesc('occurred_at')
            ->orderBy('kind')
            ->orderBy('event_id');
    }

    public function dto(object $row): array
    {
        $fr = [
            'purchase_created' => 'Achat enregistré',
            'purchase_confirmed' => 'Achat confirmé',
            'goods_received' => 'Réception physique',
            'obligation_recognized' => 'Dette reconnue',
            'payment_verified' => 'Règlement vérifié',
            'payment_allocated' => 'Règlement affecté',
            'credit_approved' => 'Avoir accepté',
            'credit_allocated' => 'Avoir affecté',
            'goods_returned' => 'Retour fournisseur',
            'schedule_renegotiated' => 'Échéance renégociée',
            'contract_revision' => 'Version du contrat',
            'invoice_revision' => 'Version de saisie de facture',
            'overage_accepted' => 'Excédent accepté',
        ];
        $en = [
            'purchase_created' => 'Purchase recorded',
            'purchase_confirmed' => 'Purchase confirmed',
            'goods_received' => 'Physical receipt',
            'obligation_recognized' => 'Payable recognized',
            'payment_verified' => 'Payment verified',
            'payment_allocated' => 'Payment allocated',
            'credit_approved' => 'Credit accepted',
            'credit_allocated' => 'Credit allocated',
            'goods_returned' => 'Supplier return',
            'schedule_renegotiated' => 'Installment renegotiated',
            'contract_revision' => 'Contract revision',
            'invoice_revision' => 'Invoice registration revision',
            'overage_accepted' => 'Overage accepted',
        ];

        return [
            'event_id' => $row->event_id,
            'kind' => $row->kind,
            'label' => (app()->getLocale() === 'fr' ? $fr : $en)[$row->kind],
            'resource_id' => $row->resource_id,
            'purchase_order_id' => $row->purchase_order_id,
            'reference' => $row->reference,
            'amount' => $row->amount === null ? null : (string) $row->amount,
            'quantity' => $row->quantity === null ? null : (int) $row->quantity,
            'document_id' => $row->document_id,
            'occurred_at' => Records::iso($row->occurred_at),
        ];
    }
}
