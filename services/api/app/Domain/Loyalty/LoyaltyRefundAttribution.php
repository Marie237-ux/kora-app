<?php

namespace App\Domain\Loyalty;

use App\Domain\Procurement\Records;
use App\Domain\Shared\Money;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Stable unit ranges pin the exact cash and points of even one-XAF rounding differences. Order lock required. */
final class LoyaltyRefundAttribution
{
    public function slice(object $line, int $start, int $quantity): array
    {
        if ($quantity < 1 || $start + $quantity > $line->quantity) {
            LoyaltyProgram::fail('lines', 'RETURN_QUANTITY_INVALID');
        }
        $end = $start + $quantity;
        $discount =
            LoyaltyLedger::ratio((int) $line->loyalty_discount, $end, (int) $line->quantity) -
            LoyaltyLedger::ratio((int) $line->loyalty_discount, $start, (int) $line->quantity);
        $points =
            LoyaltyLedger::ratio((int) $line->loyalty_points_used, $end, (int) $line->quantity) -
            LoyaltyLedger::ratio((int) $line->loyalty_points_used, $start, (int) $line->quantity);

        return [
            'amount' => Money::multiply((int) $line->unit_price, $quantity) - $discount,
            'discount' => $discount,
            'points' => $points,
        ];
    }

    public function nextSlice(object $line, int $quantity): array
    {
        $start = (int) DB::table('loyalty_refund_lines')->where('order_line_id', $line->id)->sum('quantity');

        return ['start_quantity' => $start, ...$this->slice($line, $start, $quantity)];
    }

    /** Units refunded after delivery under an after-sales case: same exact slice as a supply shortage. */
    public function reserveReturn(object $order, object $line, array $refund, int $quantity): void
    {
        $this->reserveSupply($order, $line, $refund, $quantity, [
            'fr' => 'Unités remboursées après livraison (SAV).',
            'en' => 'Units refunded after delivery (after-sales).',
        ]);
    }

    public function reserveSupply(
        object $order,
        object $line,
        array $refund,
        int $quantity,
        ?array $explanation = null,
    ): void {
        if (! $order->loyalty_checkout_quote_id) {
            return;
        }
        $slice = $this->nextSlice($line, $quantity);
        DB::table('loyalty_refund_lines')->insert([
            'refund_id' => $refund['id'],
            'order_line_id' => $line->id,
            'quantity' => $quantity,
            'start_quantity' => $slice['start_quantity'],
            'amount' => $slice['amount'],
            'discount' => $slice['discount'],
            'status' => 'pending',
            'explanation' => Records::json(
                $explanation ?? [
                    'fr' => 'Unités de la rupture fournisseur.',
                    'en' => 'Units from the supplier shortage.',
                ],
            ),
            'created_at' => now(),
        ]);
    }

    public function allocate(object $order, object $refund, array $lines, array $text, ?User $actor): array
    {
        $existing = DB::table('loyalty_refund_lines')->where('refund_id', $refund->id)->get();
        if ($existing->isNotEmpty()) {
            abort_if($existing->contains(fn ($r) => $r->status !== 'pending'), 409);
            $requested = collect($lines)
                ->sortBy('order_line_id')
                ->map(fn ($l) => [$l['order_line_id'], $l['quantity']])
                ->values()
                ->all();
            $held = $existing
                ->sortBy('order_line_id')
                ->map(fn ($l) => [$l->order_line_id, (int) $l->quantity])
                ->values()
                ->all();
            abort_unless($requested === $held, 409);
        }
        $claims = [];
        $total = 0;
        foreach ($lines as $input) {
            $line = DB::table('order_lines')
                ->where('id', $input['order_line_id'])
                ->where('order_id', $order->id)
                ->first();
            abort_unless($line, 404);
            $old = $existing->firstWhere('order_line_id', $line->id);
            $slice = $old
                ? [
                    'start_quantity' => (int) $old->start_quantity,
                    ...$this->slice($line, (int) $old->start_quantity, (int) $old->quantity),
                ]
                : $this->nextSlice($line, $input['quantity']);
            $total = Money::sum([$total, $slice['amount']]);
            $values = ['status' => 'paid', 'explanation' => Records::json($text), 'actor_id' => $actor?->id];
            if ($old) {
                $id = $old->id;
                DB::table('loyalty_refund_lines')->where('id', $id)->update($values);
            } else {
                $id = DB::table('loyalty_refund_lines')->insertGetId([
                    ...$values,
                    'refund_id' => $refund->id,
                    'order_line_id' => $line->id,
                    'quantity' => $input['quantity'],
                    'start_quantity' => $slice['start_quantity'],
                    'amount' => $slice['amount'],
                    'discount' => $slice['discount'],
                    'created_at' => now(),
                ]);
            }
            $claims[] = ['id' => (int) $id, 'line' => $line, ...$slice];
        }
        if ($total > (int) $refund->amount) {
            LoyaltyProgram::fail('lines', 'RETURN_EXCEEDS_REFUND');
        }

        return ['claims' => $claims, 'total' => $total];
    }
}
