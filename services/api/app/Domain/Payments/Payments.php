<?php

namespace App\Domain\Payments;

use App\Domain\Delivery\DeliverySlots;
use App\Domain\Inventory\Inventory;
use App\Domain\Loyalty\LoyaltyRedemptions;
use App\Domain\Orders\Orders;
use App\Domain\Procurement\Records;
use App\Domain\Promotions\DeliveryOffers;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Payment attempts and their reconciliation. Provider calls always happen outside database transactions:
 * the attempt is claimed in one transaction, the provider is called, then the outcome is written in another.
 *
 * Only a server-side check whose reference, amount and currency match the attempt confirms a payment. A browser
 * return, a notification body or a screenshot never does. A second real payment opens an overpayment incident.
 */
final class Payments
{
    public function __construct(private PaymentGateway $gateway) {}

    /** Minutes during which an unfinished attempt is offered again instead of starting a new charge. */
    private function reuseMinutes(): int
    {
        return max(1, (int) config('kora.payment_attempt_reuse_minutes', 15));
    }

    public function start(string $orderId, string $key, User $actor, string $returnUrl): array
    {
        $claim = DB::transaction(function () use ($orderId, $key, $actor) {
            $order = app(Orders::class)->owned($orderId, $actor, true);
            $same = DB::table('payment_attempts')
                ->where('order_id', $orderId)
                ->where('request_key', strtolower($key))
                ->first();
            if ($same) {
                abort_if($same->status === 'starting', 409);

                return ['attempt' => $same, 'new' => false];
            }
            if (
                $order->status !== 'awaiting_payment' ||
                CarbonImmutable::parse($order->payment_expires_at, 'UTC')->isPast()
            ) {
                Orders::fail('order', 'ORDER_NOT_PAYABLE');
            }
            $open = DB::table('payment_attempts')
                ->where('order_id', $orderId)
                ->whereIn('status', ['starting', 'pending', 'to_verify'])
                ->orderByDesc('created_at')
                ->first();
            if ($open && $open->status === 'to_verify') {
                Orders::fail('order', 'PAYMENT_UNDER_REVIEW');
            }
            if (
                $open &&
                CarbonImmutable::parse($open->updated_at, 'UTC')->addMinutes($this->reuseMinutes())->isFuture()
            ) {
                abort_if($open->status === 'starting', 409);

                return ['attempt' => $open, 'new' => false];
            }
            $id = Records::id();
            DB::table('payment_attempts')->insert([
                'id' => $id,
                'order_id' => $orderId,
                'request_key' => strtolower($key),
                'provider' => $this->gateway->name(),
                'status' => 'starting',
                'amount' => $order->total,
                'currency' => $order->currency,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            app(Orders::class)->event($orderId, 'payment_started', $actor, ['attempt_id' => $id]);

            return ['attempt' => Records::row('payment_attempts', $id, false), 'new' => true];
        }, 3);
        $attempt = $claim['attempt'];
        if (! $claim['new']) {
            return $this->attemptDto($attempt);
        }

        $failure = null;
        $url = null;
        if (! $this->gateway->configured()) {
            $failure = 'provider_not_configured';
        } else {
            try {
                $url = $this->gateway->start([
                    'reference' => $attempt->id,
                    'amount' => (int) $attempt->amount,
                    'currency' => $attempt->currency,
                    'locale' => app()->getLocale(),
                    'notify_url' => url('/api/v1/payments/'.$this->gateway->name().'/notify'),
                    'return_url' => $returnUrl,
                    'user' => (string) $actor->id,
                ])['payment_url'];
            } catch (PaymentProviderException $error) {
                $failure = $error->getMessage();
            }
        }
        DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->update([
                'status' => $failure ? 'failed' : 'pending',
                'payment_url' => $url,
                'failure_reason' => $failure,
                'version' => $attempt->version + 1,
                'updated_at' => now(),
            ]);
        if ($failure) {
            // Nothing was charged; the customer may try again once the provider is available.
            throw ValidationException::withMessages(['payment' => __('orders.PAYMENT_PROVIDER_UNAVAILABLE')]);
        }

        return $this->attemptDto(Records::row('payment_attempts', $attempt->id, false));
    }

    /** A transaction id learnt from a notification or a browser return; still unverified at this point. */
    public function claim(object $attempt, string $transactionId): void
    {
        DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->whereNull('provider_transaction_id')
            ->update(['provider_transaction_id' => $transactionId, 'updated_at' => now()]);
    }

    /** Verifies one transaction with the provider, then applies the verified facts. Returns the outcome code. */
    public function verify(string $attemptId, string $transactionId): string
    {
        $result = $this->gateway->check($transactionId);

        return DB::transaction(fn () => $this->apply($attemptId, $transactionId, $result), 3);
    }

    private function apply(string $attemptId, string $transactionId, array $result): string
    {
        $orderId = DB::table('payment_attempts')->where('id', $attemptId)->value('order_id');
        abort_unless($orderId, 404);
        // Lock order then attempt, the same order as start().
        $order = Records::row('orders', $orderId);
        $attempt = Records::row('payment_attempts', $attemptId);
        DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->update(['checks' => $attempt->checks + 1, 'checked_at' => now(), 'updated_at' => now()]);
        if ($attempt->status === 'confirmed') {
            return 'already_confirmed';
        }
        if ($result['state'] === 'unknown') {
            return 'unknown';
        }
        if ($result['reference'] !== null && $result['reference'] !== $attempt->id) {
            // The transaction belongs to another payment reference: never attach it to this order.
            $this->incident($order, $attempt, 'reference_mismatch', ['transaction_id' => $transactionId]);

            return 'reference_mismatch';
        }
        if (in_array($result['state'], ['failed', 'cancelled'], true)) {
            if (in_array($attempt->status, ['pending', 'starting'], true)) {
                $this->setAttempt($attempt, $result['state'], $transactionId, $result);
                app(Orders::class)->event($order->id, 'payment_'.$result['state'], null, [
                    'attempt_id' => $attempt->id,
                ]);
            }

            return $result['state'];
        }

        $problem = match (true) {
            $result['testmode'] === true && ! config('services.monetbil.accept_testmode') => 'testmode_payment',
            $result['reference'] === null || $result['amount'] === null || $result['currency'] === null => 'verification_incomplete',
            $result['amount'] !== (int) $attempt->amount || $result['currency'] !== $attempt->currency => 'amount_mismatch',
            DB::table('payment_attempts')
                ->where('provider', $attempt->provider)
                ->where('provider_transaction_id', $transactionId)
                ->where('status', 'confirmed')
                ->exists() => 'transaction_already_used',
            default => null,
        };
        if ($problem) {
            $this->setAttempt($attempt, 'to_verify', $transactionId, $result);
            $this->incident($order, $attempt, $problem, [
                'transaction_id' => $transactionId,
                'amount' => $result['amount'],
                'currency' => $result['currency'],
            ]);
            if ($order->status === 'awaiting_payment') {
                DB::table('orders')
                    ->where('id', $order->id)
                    ->update(['status' => 'payment_review', 'version' => $order->version + 1, 'updated_at' => now()]);
            }

            return $problem;
        }

        $this->setAttempt($attempt, 'confirmed', $transactionId, $result);
        if ($order->status === 'paid') {
            // A genuine second payment: the money must be returned, so it is tracked, not ignored.
            $this->incident($order, $attempt, 'overpayment', [
                'transaction_id' => $transactionId,
                'amount' => $result['amount'],
            ]);

            return 'overpayment';
        }
        $this->markPaid($order, $attempt);

        return 'confirmed';
    }

    private function setAttempt(object $attempt, string $status, string $transactionId, array $result): void
    {
        DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->update([
                'status' => $status,
                'provider_transaction_id' => $transactionId,
                'provider_status' => $result['provider_status'],
                'testmode' => $result['testmode'],
                // Recorded for confirmed and disputed payments alike: it is money that may have to be returned.
                'received_amount' => in_array($status, ['confirmed', 'to_verify'], true) ? $result['amount'] : null,
                'confirmed_at' => $status === 'confirmed' ? now() : null,
                'version' => $attempt->version + 1,
                'updated_at' => now(),
            ]);
        if ($status === 'confirmed') {
            Records::eventOnce('CustomerPaymentConfirmed', $attempt->id, ['id' => $attempt->id]);
        }
    }

    /**
     * Confirmation by an authorised person after checking the provider's own records, for an attempt whose
     * answer was incomplete. Same effects as an automatic confirmation; a second payment still opens an incident.
     * The caller holds the order lock.
     */
    public function confirmReviewed(object $order, object $attempt): string
    {
        DB::table('payment_attempts')
            ->where('id', $attempt->id)
            ->update([
                'status' => 'confirmed',
                // The reviewer confirmed the provider's records show the requested amount.
                'received_amount' => $attempt->received_amount ?? $attempt->amount,
                'confirmed_at' => now(),
                'version' => $attempt->version + 1,
                'updated_at' => now(),
            ]);
        Records::eventOnce('CustomerPaymentConfirmed', $attempt->id, ['id' => $attempt->id]);
        if ($order->status === 'paid') {
            $this->incident($order, $attempt, 'overpayment', ['amount' => (int) $attempt->amount]);

            return 'overpayment';
        }
        $this->markPaid($order, $attempt);

        return 'confirmed';
    }

    private function markPaid(object $order, object $attempt): void
    {
        $late = $order->status === 'expired';
        $lines = DB::table('order_lines')->where('order_id', $order->id)->orderBy('offer_id')->get();
        // Lock order as in order creation: cart, then quote (slot), then delivery offer.
        Records::row('carts', $order->cart_id);
        // Purchased lines leave the saved cart; unselected lines stay for later.
        $cartLines = $lines->pluck('cart_line_id')->filter()->all();
        $removed = DB::table('cart_lines')->where('cart_id', $order->cart_id)->whereIn('id', $cartLines)->delete();
        if ($removed) {
            DB::table('carts')
                ->where('id', $order->cart_id)
                ->increment('version', 1, ['updated_at' => now()]);
        }
        $this->confirmSlot($order);
        app(DeliveryOffers::class)->consume($order);
        // Inventory locks follow the cart and promotion locks, including for a late payment.
        foreach ($lines as $line) {
            if ($line->from_warehouse > 0 && ! app(Inventory::class)->retainForPaidLine($line)) {
                $this->reserveAgain($order, $line);
                app(Inventory::class)->retainForPaidLine(Records::row('order_lines', $line->id));
            }
        }
        app(LoyaltyRedemptions::class)->consume($order);
        DB::table('orders')
            ->where('id', $order->id)
            ->update(['status' => 'paid', 'paid_at' => now(), 'version' => $order->version + 1, 'updated_at' => now()]);
        app(Orders::class)->event($order->id, $late ? 'paid_after_expiry' : 'paid', null, [
            'attempt_id' => $attempt->id,
        ]);
    }

    /** Payment confirmed after the stock window: try a conforming reservation again, else open an incident. */
    private function reserveAgain(object $order, object $line): void
    {
        $owner = User::findOrFail($order->user_id);
        try {
            $id = DB::transaction(
                fn () => app(Inventory::class)->reserve(
                    [
                        'offer_id' => $line->offer_id,
                        'purpose_id' => Records::id(),
                        'quantity' => $line->from_warehouse,
                    ],
                    $owner,
                )['id'],
            );
            DB::table('order_lines')
                ->where('id', $line->id)
                ->update(['reservation_id' => $id, 'supply_status' => 'reserved', 'updated_at' => now()]);
        } catch (ValidationException) {
            DB::table('order_lines')
                ->where('id', $line->id)
                ->update(['supply_status' => 'incident', 'updated_at' => now()]);
            DB::table('payment_incidents')->insertOrIgnore([
                'id' => Records::id(),
                'order_id' => $order->id,
                'order_line_id' => $line->id,
                'kind' => 'stock_unavailable_after_payment',
                'details' => Records::json(['quantity' => (int) $line->from_warehouse]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function confirmSlot(object $order): void
    {
        $quote = Records::row('delivery_quotes', $order->quote_id);
        if (! $quote->slot_rule_id) {
            return;
        }
        $stillHeld = $quote->slot_status === 'held' && CarbonImmutable::parse($quote->expires_at, 'UTC')->isFuture();
        if (! $stillHeld) {
            DB::table('delivery_slot_locks')->insertOrIgnore([
                'rule_id' => $quote->slot_rule_id,
                'date' => $quote->slot_date,
                'created_at' => now(),
            ]);
            DB::table('delivery_slot_locks')
                ->where('rule_id', $quote->slot_rule_id)
                ->where('date', $quote->slot_date)
                ->lockForUpdate()
                ->first();
            $rule = Records::row('delivery_slot_rules', $quote->slot_rule_id, false);
            $capacity = (int) $rule->capacity;
            $stillHeld = app(DeliverySlots::class)->used($rule->id, $quote->slot_date, $quote->id) < $capacity;
        }
        DB::table('delivery_quotes')
            ->where('id', $quote->id)
            ->update(['slot_status' => $stillHeld ? 'confirmed' : 'released', 'updated_at' => now()]);
        if (! $stillHeld) {
            DB::table('payment_incidents')->insertOrIgnore([
                'id' => Records::id(),
                'order_id' => $order->id,
                'kind' => 'slot_unavailable_after_payment',
                'details' => Records::json(['date' => $quote->slot_date]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    private function incident(object $order, object $attempt, string $kind, array $details): void
    {
        DB::table('payment_incidents')->insertOrIgnore([
            'id' => Records::id(),
            'order_id' => $order->id,
            'attempt_id' => $attempt->id,
            'kind' => $kind,
            'details' => Records::json($details),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(Orders::class)->event($order->id, 'payment_incident', null, [
            'attempt_id' => $attempt->id,
            'kind' => $kind,
        ]);
    }

    public function attemptDto(object $attempt): array
    {
        return [
            'id' => $attempt->id,
            'order_id' => $attempt->order_id,
            'provider' => $attempt->provider,
            'status' => $attempt->status,
            'amount' => (string) $attempt->amount,
            'currency' => $attempt->currency,
            'payment_url' => $attempt->status === 'pending' ? $attempt->payment_url : null,
            'created_at' => Records::iso($attempt->created_at),
        ];
    }
}
