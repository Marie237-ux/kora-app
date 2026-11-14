<?php

namespace App\Domain\Accounting;

use App\Domain\Procurement\Records;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Inventory of seven supported fact families. Candidate does not imply accepted or posted. */
final class AccountingSourceCatalog
{
    public const FACTS = [
        'customer_invoice' => ['customer_documents', 'issued_at', 'CustomerInvoiceIssued'],
        'customer_credit_note' => ['customer_documents', 'issued_at', 'CustomerCreditNoteIssued'],
        'customer_payment' => ['payment_attempts', 'confirmed_at', 'CustomerPaymentConfirmed'],
        'customer_refund' => ['customer_refunds', 'paid_at', 'CustomerRefundPaid'],
        'supplier_obligation' => ['supplier_obligations', 'created_at', 'SupplierObligationRecognized'],
        'supplier_payment' => ['supplier_payments', 'paid_at', 'SupplierPaymentVerified'],
        'goods_receipt' => ['goods_receipts', 'received_at', 'GoodsReceived'],
    ];

    public function typeForEvent(string $event): ?string
    {
        foreach (self::FACTS as $type => $fact) {
            if ($fact[2] === $event) {
                return $type;
            }
        }

        return null;
    }

    public function candidates(string $type, string $from, ?string $to = null): Builder
    {
        [$table, $time] = self::FACTS[$type];
        $fromUtc = CarbonImmutable::parse($from, 'Africa/Douala')->startOfDay()->utc();
        $q = DB::table($table.' AS f')->where(function ($q) use ($time, $fromUtc, $to) {
            $q->whereNull('f.'.$time)->orWhere(function ($q) use ($time, $fromUtc, $to) {
                $q->where('f.'.$time, '>=', $fromUtc);
                if ($to) {
                    $q->where(
                        'f.'.$time,
                        '<',
                        CarbonImmutable::parse($to, 'Africa/Douala')->addDay()->startOfDay()->utc(),
                    );
                }
            });
        });
        match ($type) {
            'customer_invoice', 'customer_credit_note' => $q
                ->where('f.status', 'issued')
                ->where('f.kind', $type === 'customer_invoice' ? 'invoice' : 'credit_note'),
            'customer_payment' => $q->where('f.status', 'confirmed'),
            'customer_refund' => $q->where('f.status', 'paid'),
            'supplier_payment' => $q->where('f.status', 'verified'),
            default => $q,
        };

        return $q;
    }

    /** Recover pre-existing facts and missing notifications without fabricating financial data. */
    public function backfill(object $book, int $limit): int
    {
        $added = 0;
        foreach (self::FACTS as $type => [$table, $time, $event]) {
            if ($added >= $limit) {
                break;
            }
            $ids = $this->candidates($type, $book->capture_from)
                ->whereNotExists(function ($q) use ($event) {
                    $q->selectRaw('1')
                        ->from('outbox_events AS e')
                        ->where('e.type', $event)
                        ->whereRaw('e.deduplication_key = (? || CAST(f.id AS VARCHAR(36)))', [$event.':']);
                })
                ->orderBy('f.id')
                ->limit($limit - $added)
                ->pluck('f.id');
            foreach ($ids as $id) {
                $added += Records::eventOnce($event, $id, ['id' => $id]);
            }
        }

        return $added;
    }

    /** Same filters for the coverage screen and period closing; includes facts with no outbox event. */
    public function coverage(object $book, string $from, string $to): array
    {
        $rows = [];
        foreach (self::FACTS as $type => $fact) {
            $q = $this->candidates($type, $from, $to);
            $total = (clone $q)->count();
            $captured = (clone $q)
                ->whereExists(function ($sq) use ($book, $type) {
                    $sq->selectRaw('1')
                        ->from('accounting_sources AS s')
                        ->where('s.book_id', $book->id)
                        ->where('s.source_type', $type)
                        ->whereColumn('s.resource_id', 'f.id');
                })
                ->count();
            $excluded = (clone $q)
                ->whereNotExists(function ($sq) use ($book, $type) {
                    $sq->selectRaw('1')
                        ->from('accounting_sources AS s')
                        ->where('s.book_id', $book->id)
                        ->where('s.source_type', $type)
                        ->whereColumn('s.resource_id', 'f.id');
                })
                ->whereExists(function ($sq) use ($book, $type) {
                    $sq->selectRaw('1')
                        ->from('accounting_capture_receipts AS r')
                        ->where('r.book_id', $book->id)
                        ->where('r.source_type', $type)
                        ->whereColumn('r.resource_id', 'f.id')
                        ->where('r.status', 'excluded')
                        ->where('r.reason', '!=', 'BEFORE_CAPTURE_START');
                })
                ->count();
            $rows[] = [
                'source_type' => $type,
                'candidates' => $total,
                'captured' => $captured,
                'excluded' => $excluded,
                'unresolved' => $total - $captured - $excluded,
            ];
        }
        $issues = DB::table('accounting_capture_receipts')
            ->where('book_id', $book->id)
            ->whereIn('status', ['review_required', 'failed', 'retry_pending'])
            ->where(fn ($q) => $q->whereNull('event_date')->orWhereBetween('event_date', [$from, $to]));
        $unknown = (clone $issues)->whereNull('event_date')->count();

        return [
            'book_id' => $book->id,
            'from' => $from,
            'to' => $to,
            'scope' => 'seven_supported_fact_families',
            'rows' => $rows,
            'unresolved' => array_sum(array_column($rows, 'unresolved')),
            'capture_issues' => $issues->count(),
            'unidentified_events' => $unknown,
            'external_reconciliation' => false,
            'regulatory_certification' => false,
        ];
    }
}
