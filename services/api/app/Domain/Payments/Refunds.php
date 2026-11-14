<?php

namespace App\Domain\Payments;

use App\Domain\Documents\PrivateDocuments;
use App\Domain\Loyalty\LoyaltyReturns;
use App\Domain\Orders\Orders;
use App\Domain\Orders\OrderSupply;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manual refunds, followed until the money has really been sent. A request reserves part of the collected amount;
 * only a verified transfer with evidence marks it paid. Requests are taken under the order lock, so concurrent
 * refunds can never exceed what was actually collected for the order.
 */
final class Refunds
{
    public const CHANNELS = ['mobile_money', 'bank_transfer', 'cash'];

    /**
     * Real money the provider reported as received for this order: confirmed payments, overpayments and disputed
     * amounts alike. Test-mode transactions are not real money and never count.
     */
    public function collected(string $orderId): int
    {
        return (int) DB::table('payment_attempts')
            ->where('order_id', $orderId)
            ->whereIn('status', ['confirmed', 'to_verify', 'reversed'])
            ->where(fn ($q) => $q->whereNull('testmode')->orWhere('testmode', false))
            ->sum('received_amount');
    }

    /** Requested or paid refunds: both are already committed against the collected amount. */
    public function committed(string $orderId): int
    {
        return (int) DB::table('customer_refunds')
            ->where('order_id', $orderId)
            ->whereIn('status', ['requested', 'paid'])
            ->sum('amount');
    }

    public function request(object $order, int $amount, string $reason, ?string $incidentId, User $actor): array
    {
        if ($incidentId) {
            $incident = DB::table('payment_incidents')
                ->where('id', $incidentId)
                ->where('order_id', $order->id)
                ->first();
            abort_unless($incident, 404);
            if ($incident->status !== 'open') {
                Orders::fail('incident_id', 'INCIDENT_CLOSED');
            }
        }
        if ($amount < 1 || $amount > $this->collected($order->id) - $this->committed($order->id)) {
            Orders::fail('amount', 'REFUND_EXCEEDS_COLLECTED');
        }
        $id = Records::id();
        DB::table('customer_refunds')->insert([
            'id' => $id,
            'order_id' => $order->id,
            'incident_id' => $incidentId,
            'amount' => $amount,
            'reason' => $reason,
            'status' => 'requested',
            'requested_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(Orders::class)->event($order->id, 'refund_requested', $actor, [
            'refund_id' => $id,
            'amount' => (string) $amount,
        ]);
        Records::audit($actor, 'refund.requested', $id, ['order_id' => $order->id, 'amount' => (string) $amount]);

        return $this->dto(Records::row('customer_refunds', $id, false));
    }

    /** The actual transfer: amount, channel, reference and a clean evidence file are all required. */
    public function verify(object $refund, array $data, User $actor): array
    {
        if ($refund->status !== 'requested') {
            Orders::fail('refund', 'REFUND_NOT_PENDING');
        }
        if (Money::parse($data['amount']) !== (int) $refund->amount) {
            // A different amount is another refund: cancel this one and request the right amount.
            Orders::fail('amount', 'REFUND_AMOUNT_DIFFERS');
        }
        app(PrivateDocuments::class)->requireClean($data['evidence_id'], null, ['customer_payment_evidence']);
        DB::table('customer_refunds')
            ->where('id', $refund->id)
            ->update([
                'status' => 'paid',
                'channel' => $data['channel'],
                'reference' => $data['reference'],
                'evidence_id' => $data['evidence_id'],
                'paid_at' => CarbonImmutable::parse($data['paid_at'])->utc(),
                'verified_by' => $actor->id,
                'version' => $refund->version + 1,
                'updated_at' => now(),
            ]);
        app(Orders::class)->event($refund->order_id, 'refund_paid', $actor, ['refund_id' => $refund->id]);
        Records::audit($actor, 'refund.paid', $refund->id, ['reference' => $data['reference']]);
        Records::eventOnce('CustomerRefundPaid', $refund->id, ['id' => $refund->id]);
        app(OrderSupply::class)->refundPaid($refund);
        app(LoyaltyReturns::class)->refundPaid(Records::row('customer_refunds', $refund->id, false));
        if ($refund->incident_id) {
            $incident = Records::row('payment_incidents', $refund->incident_id);
            if ($incident->status === 'open') {
                app(PaymentIncidents::class)->close(
                    $incident,
                    'refunded',
                    'Remboursement vérifié '.$data['reference'],
                    $actor,
                );
            }
        }

        return $this->dto(Records::row('customer_refunds', $refund->id, false));
    }

    public function cancel(object $refund, string $reason, User $actor): array
    {
        if (DB::table('order_supply_cases')->where('refund_id', $refund->id)->exists()) {
            OrderSupply::fail('refund', 'REFUND_REQUIRED');
        }
        if ($refund->status !== 'requested') {
            Orders::fail('refund', 'REFUND_NOT_PENDING');
        }
        DB::table('customer_refunds')
            ->where('id', $refund->id)
            ->update([
                'status' => 'cancelled',
                'cancel_reason' => $reason,
                'version' => $refund->version + 1,
                'updated_at' => now(),
            ]);
        app(Orders::class)->event($refund->order_id, 'refund_cancelled', $actor, ['refund_id' => $refund->id]);
        Records::audit($actor, 'refund.cancelled', $refund->id);

        return $this->dto(Records::row('customer_refunds', $refund->id, false));
    }

    public function dto(object $refund): array
    {
        return [
            'id' => $refund->id,
            'order_id' => $refund->order_id,
            'incident_id' => $refund->incident_id,
            'amount' => (string) $refund->amount,
            'reason' => $refund->reason,
            'status' => $refund->status,
            'channel' => $refund->channel,
            'reference' => $refund->reference,
            'paid_at' => Records::iso($refund->paid_at),
            'version' => (int) $refund->version,
            'created_at' => Records::iso($refund->created_at),
        ];
    }
}
