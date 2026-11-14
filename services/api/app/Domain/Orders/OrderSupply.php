<?php

namespace App\Domain\Orders;

use App\Domain\Loyalty\LoyaltyRefundAttribution;
use App\Domain\Payments\Refunds;
use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Supplier facts and customer decisions are separate from the frozen sale and its payment state. */
final class OrderSupply
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('supply.'.$code)]);
    }

    public function line(object $order, string $id): object
    {
        $line = DB::table('order_lines')->where('id', $id)->where('order_id', $order->id)->lockForUpdate()->first();
        abort_unless($line, 404);
        if ($order->status !== 'paid') {
            self::fail('order', 'PAID_ORDER_REQUIRED');
        }

        return $line;
    }

    private function editable(object $line): void
    {
        if ($this->outstanding($line) < 1) {
            self::fail('line', 'SUPPLY_LOCKED');
        }
    }

    private function outstanding(object $line): int
    {
        return max(
            0,
            $line->from_supplier - $line->cancelled_quantity - app(OrderPurchases::class)->receivedQuantity($line->id),
        );
    }

    /** The caller holds the order lock; no request is sent to a real supplier. */
    public function request(object $order, string $lineId, array $data, User $actor): array
    {
        $line = $this->line($order, $lineId);
        abort_unless((int) $line->supply_version === $data['version'], 409);
        $this->editable($line);
        if ($line->supplier_reservation_id) {
            $previous = Records::row('supplier_reservations', $line->supplier_reservation_id);
            if (
                in_array(
                    $previous->status,
                    ['requested', 'confirmed', 'partly_confirmed', 'release_requested'],
                    true,
                ) &&
                CarbonImmutable::parse($previous->expires_at, 'UTC')->isFuture()
            ) {
                self::fail('reservation', 'REQUEST_ALREADY_OPEN');
            }
        }
        $id = Records::id();
        DB::table('supplier_reservations')->insert([
            'id' => $id,
            'offer_id' => $line->offer_id,
            'purpose_id' => $line->id,
            'order_line_id' => $line->id,
            'quantity' => $this->outstanding($line),
            'status' => 'requested',
            'expires_at' => CarbonImmutable::parse($data['expires_at'])->utc(),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->updateLine($line, [
            'supplier_reservation_id' => $id,
            'supplier_confirmed_quantity' => 0,
            'supply_status' => 'confirmation_requested',
        ]);
        $this->record($order->id, 'supply_requested', $line->id, $actor, ['reservation_id' => $id]);

        return ['id' => $id, 'order_line_id' => $line->id, 'status' => 'requested', 'supplier_confirmed' => false];
    }

    /** Lock order: order → line → reservation. Generic reservation routes delegate linked records here. */
    public function transition(string $id, string $action, array $data, User $actor): object
    {
        $initial = Records::row('supplier_reservations', $id, false);
        $orderId = DB::table('order_lines')->where('id', $initial->order_line_id)->value('order_id');
        $order = Records::row('orders', $orderId);
        $line = $this->line($order, $initial->order_line_id);
        $this->editable($line);
        $reservation = Records::row('supplier_reservations', $id);
        abort_unless($line->supplier_reservation_id === $id, 409);
        if (CarbonImmutable::parse($reservation->expires_at, 'UTC')->lessThanOrEqualTo(now())) {
            self::fail('reservation', 'RESERVATION_EXPIRED');
        }
        // Releasing a paid order's external reservation requires its own customer resolution first.
        if (! in_array($action, ['confirm', 'reject'], true) || $reservation->status !== 'requested') {
            self::fail('reservation', 'RESERVATION_STATE');
        }
        $quantity = $action === 'reject' ? 0 : $data['confirmed_quantity'] ?? (int) $reservation->quantity;
        if (
            (int) $reservation->quantity !== $this->outstanding($line) ||
            $quantity > $reservation->quantity ||
            ($action === 'confirm' && $quantity < 1)
        ) {
            self::fail('confirmed_quantity', 'QUANTITY_INVALID');
        }
        $status =
            $quantity === 0
                ? 'rejected'
                : ($quantity === (int) $reservation->quantity
                    ? 'confirmed'
                    : 'partly_confirmed');
        DB::table('supplier_reservations')
            ->where('id', $id)
            ->update([
                'status' => $status,
                'confirmed_quantity' => $quantity,
                'confirmation_reference' => $data['reference'],
                'confirmed_at' => now(),
                'updated_at' => now(),
            ]);
        $missing = (int) $reservation->quantity - $quantity;
        $this->updateLine($line, [
            'supplier_confirmed_quantity' => $quantity,
            'supply_status' => $missing ? 'unavailable' : 'supplier_confirmed',
        ]);
        if ($missing) {
            $this->openCase($order, $line, $missing, $this->defaultMessage(), $actor);
        } else {
            $case = $this->currentCase($line->id);
            if ($case && in_array($case->status, ['awaiting_choice', 'waiting'], true)) {
                DB::table('order_supply_cases')
                    ->where('id', $case->id)
                    ->update([
                        'status' => 'resolved',
                        'version' => $case->version + 1,
                        'updated_at' => now(),
                    ]);
            }
        }
        $this->record($order->id, 'supplier_'.$status, $line->id, $actor, [
            'reservation_id' => $id,
            'confirmed_quantity' => $quantity,
            'reference' => $data['reference'],
        ]);

        return Records::row('supplier_reservations', $id, false);
    }

    public function unavailable(object $order, string $lineId, array $data, User $actor): array
    {
        $line = $this->line($order, $lineId);
        abort_unless((int) $line->supply_version === $data['version'], 409);
        $this->editable($line);
        if ($data['missing_quantity'] > $this->outstanding($line)) {
            self::fail('missing_quantity', 'QUANTITY_INVALID');
        }
        // Supplier facts are retained; the reservation no longer promises the missing units.
        if ($line->supplier_reservation_id) {
            $reservation = Records::row('supplier_reservations', $line->supplier_reservation_id);
            $remaining = min(
                (int) $line->supplier_confirmed_quantity,
                $this->outstanding($line) - $data['missing_quantity'],
            );
            DB::table('supplier_reservations')
                ->where('id', $line->supplier_reservation_id)
                ->update([
                    'status' => $remaining > 0 ? 'partly_confirmed' : 'rejected',
                    'confirmed_quantity' => $remaining,
                    'updated_at' => now(),
                ]);
        }
        $this->updateLine($line, ['supplier_confirmed_quantity' => $remaining ?? 0, 'supply_status' => 'unavailable']);
        $case = $this->openCase($order, $line, $data['missing_quantity'], $data['message'], $actor);
        $this->record($order->id, 'supply_unavailable', $line->id, $actor, ['reference' => $data['reference']]);

        return $this->caseDto($case);
    }

    private function openCase(object $order, object $line, int $missing, array $message, ?User $actor): object
    {
        $previous = $this->currentCase($line->id);
        $case = $previous && in_array($previous->status, ['awaiting_choice', 'waiting'], true) ? $previous : null;
        $id = $case?->id ?? Records::id();
        DB::table('order_supply_cases')->updateOrInsert(
            ['id' => $id],
            [
                'order_line_id' => $line->id,
                'sequence' => $case ? $case->sequence : ($previous?->sequence ?? 0) + 1,
                'order_id' => $order->id,
                'missing_quantity' => $missing,
                'message' => Records::json($message),
                'status' => 'awaiting_choice',
                'choice' => null,
                'chosen_at' => null,
                'version' => $case ? $case->version + 1 : 1,
                'created_at' => $case?->created_at ?? now(),
                'updated_at' => now(),
            ],
        );
        $this->record($order->id, 'supply_choice_required', $line->id, $actor, [
            'case_id' => $id,
            'missing_quantity' => $missing,
        ]);

        return Records::row('order_supply_cases', $id, false);
    }

    /** Ownership is enforced by the controller before this method; order and line locks serialize decisions. */
    public function choose(
        object $order,
        string $caseId,
        int $version,
        string $choice,
        ?string $acceptedAmount,
        User $actor,
    ): array {
        abort_unless((int) $order->user_id === (int) $actor->id, 404);
        $initial = DB::table('order_supply_cases')->where('id', $caseId)->where('order_id', $order->id)->first();
        abort_unless($initial, 404);
        $line = $this->line($order, $initial->order_line_id);
        $case = Records::row('order_supply_cases', $caseId);
        abort_unless((int) $case->version === $version, 409);
        if ($case->status !== 'awaiting_choice' || ! in_array($choice, ['wait', 'refund_missing'], true)) {
            self::fail('choice', 'CHOICE_NOT_AVAILABLE');
        }
        $refund = null;
        $deliveryRefund = 0;
        $reservationChanges = [];
        if ($choice === 'refund_missing') {
            if ($case->missing_quantity > $this->outstanding($line)) {
                OrderPurchases::fail('quantity', 'RECEIVED_UNITS_PROTECTED');
            }
            [$amount, $deliveryRefund] = $this->refundAmounts($case, $line);
            if ($acceptedAmount !== (string) $amount) {
                self::fail('accepted_refund_amount', 'REFUND_CHANGED');
            }
            app(OrderPurchases::class)->releaseExcess(
                $line,
                $line->from_supplier - $line->cancelled_quantity - $case->missing_quantity,
                $actor,
            );
            $refund = app(Refunds::class)->request(
                $order,
                $amount,
                'Unavailable order units / Articles indisponibles',
                null,
                $actor,
            );
            app(LoyaltyRefundAttribution::class)->reserveSupply($order, $line, $refund, (int) $case->missing_quantity);
            // An internal request for the old quantity must not later confirm units the customer cancelled.
            if ($line->supplier_reservation_id) {
                $reservation = Records::row('supplier_reservations', $line->supplier_reservation_id);
                if ($reservation->status === 'requested') {
                    DB::table('supplier_reservations')
                        ->where('id', $reservation->id)
                        ->update([
                            'status' => 'superseded',
                            'updated_at' => now(),
                        ]);
                    $reservationChanges = ['supplier_reservation_id' => null, 'supplier_confirmed_quantity' => 0];
                }
            }
        }
        DB::table('order_supply_cases')
            ->where('id', $caseId)
            ->update([
                'status' => $refund ? 'refund_requested' : 'waiting',
                'choice' => $choice,
                'refund_id' => $refund['id'] ?? null,
                'chosen_at' => now(),
                'refund_amount' => $refund['amount'] ?? null,
                'delivery_refund' => $deliveryRefund,
                'version' => $case->version + 1,
                'updated_at' => now(),
            ]);
        $this->updateLine($line, [
            ...$reservationChanges,
            'cancelled_quantity' => $line->cancelled_quantity + ($refund ? $case->missing_quantity : 0),
            'supply_status' => $refund ? 'refund_pending' : 'waiting_supplier',
        ]);
        $this->record($order->id, 'supply_customer_choice', $line->id, $actor, [
            'case_id' => $caseId,
            'choice' => $choice,
        ]);

        return $this->caseDto(Records::row('order_supply_cases', $caseId, false));
    }

    /** Called by verified manual refunds, within the same order transaction. */
    public function refundPaid(object $refund): void
    {
        $case = DB::table('order_supply_cases')->where('refund_id', $refund->id)->lockForUpdate()->first();
        if (! $case || $case->status !== 'refund_requested') {
            return;
        }
        $line = Records::row('order_lines', $case->order_line_id);
        DB::table('order_supply_cases')
            ->where('id', $case->id)
            ->update([
                'status' => 'refunded',
                'version' => $case->version + 1,
                'updated_at' => now(),
            ]);
        if ($this->currentCase($line->id)->id === $case->id) {
            $this->updateLine($line, [
                'supply_status' => (int) $line->cancelled_quantity === (int) $line->quantity ? 'cancelled' : 'partially_cancelled',
            ]);
        }
        $this->finishCancellation($refund->order_id);
    }

    /** Expiry never invents a supplier confirmation, even after the customer's payment. */
    public function expire(string $id): void
    {
        $initial = Records::row('supplier_reservations', $id, false);
        $orderId = DB::table('order_lines')->where('id', $initial->order_line_id)->value('order_id');
        $order = Records::row('orders', $orderId);
        $line = $this->line($order, $initial->order_line_id);
        $reservation = Records::row('supplier_reservations', $id);
        if (
            ! in_array($reservation->status, ['requested', 'confirmed', 'partly_confirmed'], true) ||
            CarbonImmutable::parse($reservation->expires_at, 'UTC')->isFuture()
        ) {
            return;
        }
        DB::table('supplier_reservations')
            ->where('id', $id)
            ->update(['status' => 'expired', 'updated_at' => now()]);
        if ($line->supplier_reservation_id !== $id || $this->outstanding($line) < 1) {
            return;
        }
        $this->updateLine($line, ['supplier_confirmed_quantity' => 0, 'supply_status' => 'unavailable']);
        $this->openCase($order, $line, $this->outstanding($line), $this->defaultMessage(), null);
        $this->record($order->id, 'supplier_confirmation_expired', $line->id, null, ['reservation_id' => $id]);
    }

    private function defaultMessage(): array
    {
        return [
            'fr' => 'La disponibilité fournisseur doit être reconfirmée. Choisissez une attente ou un remboursement des unités manquantes.',
            'en' => 'Supplier availability needs confirmation. Choose to wait or request a refund for the missing units.',
        ];
    }

    private function updateLine(object $line, array $values): void
    {
        DB::table('order_lines')
            ->where('id', $line->id)
            ->update([...$values, 'supply_version' => $line->supply_version + 1, 'updated_at' => now()]);
    }

    private function record(string $orderId, string $action, string $lineId, ?User $actor, array $data = []): void
    {
        app(Orders::class)->event($orderId, $action, $actor, ['line_id' => $lineId, ...$data]);
        Records::audit($actor, $action, $lineId, $data);
    }

    private function currentCase(string $lineId): ?object
    {
        return DB::table('order_supply_cases')->where('order_line_id', $lineId)->orderByDesc('sequence')->first();
    }

    /** If this cancels the final remaining units, the full net delivery fee is returned as well. */
    private function refundAmounts(object $case, object $line): array
    {
        if ($case->refund_amount !== null) {
            return [(int) $case->refund_amount, (int) $case->delivery_refund];
        }
        $remaining = (int) DB::table('order_lines')
            ->where('order_id', $case->order_id)
            ->selectRaw('SUM(quantity - cancelled_quantity) AS remaining')
            ->value('remaining');
        $delivery =
            $remaining === (int) $case->missing_quantity
                ? (int) DB::table('orders')->where('id', $case->order_id)->value('delivery_total')
                : 0;

        return [
            Money::sum([
                $line->loyalty_discount > 0
                    ? app(LoyaltyRefundAttribution::class)->nextSlice($line, (int) $case->missing_quantity)['amount']
                    : Money::multiply((int) $line->unit_price, (int) $case->missing_quantity),
                $delivery,
            ]),
            $delivery,
        ];
    }

    /** All units cancelled and money returned: release slot and restore the delivery advantage exactly once. */
    private function finishCancellation(string $orderId): void
    {
        $order = Records::row('orders', $orderId);
        if (
            $order->closed_at ||
            DB::table('order_lines')
                ->where('order_id', $orderId)
                ->whereColumn('cancelled_quantity', '<', 'quantity')
                ->exists() ||
            DB::table('order_supply_cases')->where('order_id', $orderId)->where('status', 'refund_requested')->exists()
        ) {
            return;
        }
        if (
            (int) DB::table('customer_refunds')->where('order_id', $orderId)->where('status', 'paid')->sum('amount') <
            $order->total
        ) {
            return;
        }
        Records::row('delivery_quotes', $order->quote_id);
        DB::table('delivery_quotes')
            ->where('id', $order->quote_id)
            ->update(['slot_status' => 'released', 'updated_at' => now()]);
        $usage = DB::table('delivery_offer_usages')->where('order_id', $orderId)->first();
        if ($usage) {
            Records::row('delivery_offers', $usage->offer_id);
            DB::table('delivery_offer_usages')
                ->where('id', $usage->id)
                ->update(['status' => 'released', 'updated_at' => now()]);
        }
        // Payment stays paid: cancellation concerns fulfilment, while refunds remain separately visible.
        DB::table('orders')
            ->where('id', $orderId)
            ->update(['closed_at' => now(), 'version' => $order->version + 1, 'updated_at' => now()]);
        app(Orders::class)->event($orderId, 'supply_order_cancelled_and_refunded', null);
        Records::audit(null, 'supply_order_cancelled_and_refunded', $orderId);
    }

    public function caseDto(object $case): array
    {
        $line = Records::row('order_lines', $case->order_line_id, false);
        $message = json_decode($case->message, true);
        [$amount, $deliveryRefund] = $this->refundAmounts($case, $line);

        return [
            'id' => $case->id,
            'order_line_id' => $case->order_line_id,
            'version' => (int) $case->version,
            'status' => $case->status,
            'missing_quantity' => (int) $case->missing_quantity,
            'message' => $message[app()->getLocale()] ?? $message['fr'],
            'choices' => $case->status === 'awaiting_choice' ? ['wait', 'refund_missing'] : [],
            'choice' => $case->choice,
            'refund_amount' => (string) $amount,
            'delivery_refund' => (string) $deliveryRefund,
            'refund_id' => $case->refund_id,
            'chosen_at' => Records::iso($case->chosen_at),
        ];
    }

    /** Read-side expiry is immediate, without waiting for the scheduled cleanup. */
    public function customerState(object $line): array
    {
        $state = ['supply_status' => $line->supply_status, 'supplier_confirmation_valid' => false];
        if ($line->from_supplier > $line->cancelled_quantity && $this->outstanding($line) === 0) {
            if (! in_array($line->supply_status, ['refund_pending', 'cancelled'], true)) {
                $state['supply_status'] = 'supplier_stock_received';
            }

            return $state;
        }
        if (! $line->supplier_reservation_id || $line->from_supplier <= $line->cancelled_quantity) {
            return $state;
        }
        $reservation = Records::row('supplier_reservations', $line->supplier_reservation_id, false);
        $fresh = CarbonImmutable::parse($reservation->expires_at, 'UTC')->isFuture();
        $state['supplier_confirmation_valid'] =
            in_array($reservation->status, ['confirmed', 'partly_confirmed'], true) &&
            (int) $reservation->confirmed_quantity >= $this->outstanding($line) &&
            $fresh;
        if (
            ! $fresh &&
            in_array(
                $line->supply_status,
                ['supplier_confirmed', 'confirmation_requested', 'partially_cancelled'],
                true,
            )
        ) {
            $state['supply_status'] = 'confirmation_expired';
        }

        return $state;
    }
}
