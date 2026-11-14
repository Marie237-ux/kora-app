<?php

namespace App\Domain\Payments;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Loyalty\LoyaltyRedemptions;
use App\Domain\Orders\Orders;
use App\Domain\Procurement\Records;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Closing a payment incident is an explicit, audited decision. Where money was received and not kept, the only
 * way out is a verified refund; a note alone can never close it.
 */
final class PaymentIncidents
{
    /** Allowed outcomes per incident kind. "refunded" is set by a verified refund, never chosen by hand. */
    private const OUTCOMES = [
        'amount_mismatch' => ['refunded'],
        'overpayment' => ['refunded'],
        'verification_incomplete' => ['confirmed_after_review', 'refunded'],
        'testmode_payment' => ['dismissed'],
        'reference_mismatch' => ['dismissed'],
        'transaction_already_used' => ['dismissed'],
        'stock_unavailable_after_payment' => ['refunded', 'dismissed'],
        'slot_unavailable_after_payment' => ['dismissed'],
        // The customer keeps the accepted price; the overrun is acknowledged against the campaign budget.
        'promotion_budget_exceeded' => ['dismissed'],
        'loyalty_points_unavailable_after_payment' => ['refunded', 'dismissed'],
        'loyalty_budget_exceeded_after_payment' => ['dismissed'],
    ];

    public function resolve(object $incident, string $outcome, string $note, ?string $evidenceId, User $actor): array
    {
        if ($incident->status !== 'open') {
            Orders::fail('incident', 'INCIDENT_CLOSED');
        }
        if ($outcome === 'refunded' || ! in_array($outcome, self::OUTCOMES[$incident->kind] ?? [], true)) {
            Orders::fail('outcome', 'INCIDENT_OUTCOME_NOT_ALLOWED');
        }
        if ($outcome === 'confirmed_after_review') {
            // The person confirms from the provider's own records and attaches them.
            if (! $evidenceId) {
                Orders::fail('evidence_id', 'EVIDENCE_REQUIRED');
            }
            app(PrivateDocuments::class)->requireClean($evidenceId, null, ['customer_payment_evidence']);
            $order = Records::row('orders', $incident->order_id);
            $attempt = Records::row('payment_attempts', $incident->attempt_id);
            app(Payments::class)->confirmReviewed($order, $attempt);
        }

        return $this->close($incident, $outcome, $note, $actor);
    }

    public function close(object $incident, string $outcome, string $note, User $actor): array
    {
        DB::table('payment_incidents')
            ->where('id', $incident->id)
            ->update([
                'status' => 'resolved',
                'resolution' => $outcome,
                'resolution_note' => $note,
                'resolved_by' => $actor->id,
                'resolved_at' => now(),
                'updated_at' => now(),
            ]);
        if ($incident->attempt_id && $outcome === 'refunded') {
            DB::table('payment_attempts')
                ->where('id', $incident->attempt_id)
                ->where('status', 'to_verify')
                ->update(['status' => 'reversed', 'updated_at' => now()]);
        }
        $this->reopenOrder($incident->order_id);
        app(LoyaltyRedemptions::class)->reviewResolved($incident, $outcome);
        app(Orders::class)->event($incident->order_id, 'incident_resolved', $actor, [
            'incident_id' => $incident->id,
            'outcome' => $outcome,
        ]);
        Records::audit($actor, 'payment.incident_resolved', $incident->id, ['outcome' => $outcome]);

        return $this->dto(Records::row('payment_incidents', $incident->id, false));
    }

    /** An order under review becomes payable again once nothing is left to verify, or expires if its window passed. */
    private function reopenOrder(string $orderId): void
    {
        $order = Records::row('orders', $orderId);
        $pending = DB::table('payment_attempts')->where('order_id', $orderId)->where('status', 'to_verify')->exists();
        if ($order->status !== 'payment_review' || $pending) {
            return;
        }
        DB::table('orders')
            ->where('id', $orderId)
            ->update(['status' => 'awaiting_payment', 'version' => $order->version + 1, 'updated_at' => now()]);
        app(Orders::class)->expire(Records::row('orders', $orderId));
    }

    public function dto(object $incident): array
    {
        return [
            'id' => $incident->id,
            'order_id' => $incident->order_id,
            'attempt_id' => $incident->attempt_id,
            'order_line_id' => $incident->order_line_id,
            'kind' => $incident->kind,
            'status' => $incident->status,
            'details' => json_decode($incident->details, true),
            'resolution' => $incident->resolution,
            'resolution_note' => $incident->resolution_note,
            'resolved_at' => Records::iso($incident->resolved_at),
            'created_at' => Records::iso($incident->created_at),
        ];
    }
}
