<?php

namespace App\Domain\Accounting;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Operational contribution margin (§22) by order, product, shop or delivery campaign. It is not the accounting
 * result nor available cash. Goods cost is actual where the units are traced (depot lots, purchase lines) and
 * otherwise estimated from the agreement in force at the sale, and says so; costs not yet known (payment fees,
 * actual delivery cost, value of loyalty points) are named rather than guessed.
 */
final class OperationalMargins
{
    public const GROUPS = ['order', 'product', 'shop', 'campaign'];

    public function report(array $data): array
    {
        $from = CarbonImmutable::parse($data['from'], 'Africa/Douala')->startOfDay()->utc();
        $to = CarbonImmutable::parse($data['to'], 'Africa/Douala')->endOfDay()->utc();
        $orders = DB::table('orders')
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$from, $to])
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get();
        $groups = [];
        $totals = $this->blank('total', null);
        $unknown = ['payment_fees', 'delivery_cost'];
        // Product of each offer sold, for the product grouping.
        $products = DB::table('offers')
            ->join('variants', 'variants.id', '=', 'offers.variant_id')
            ->whereIn(
                'offers.id',
                DB::table('order_lines')->whereIn('order_id', $orders->pluck('id'))->pluck('offer_id'),
            )
            ->pluck('variants.product_id', 'offers.id');
        foreach ($orders as $order) {
            $lines = DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->get();
            $refunded = (int) DB::table('customer_refunds')
                ->where('order_id', $order->id)
                ->where('status', 'paid')
                ->sum('amount');
            $usage = DB::table('delivery_offer_usages AS u')
                ->join('delivery_offers AS o', 'o.id', '=', 'u.offer_id')
                ->where('u.order_id', $order->id)
                ->where('u.status', 'consumed')
                ->first(['u.amount', 'o.id', 'o.name']);
            $subsidy = (int) ($usage->amount ?? 0);
            if (DB::table('loyalty_grants')->where('order_id', $order->id)->exists()) {
                $unknown[] = 'loyalty_rewards';
            }
            $revenues = $this->apportion(
                array_map(fn ($l) => (int) $l->line_total, $lines->all()),
                (int) $order->items_total,
            );
            $refunds = $this->apportion(
                array_map(fn ($l) => (int) $l->line_total, $lines->all()),
                min($refunded, (int) $order->items_total),
            );
            foreach ($lines as $i => $line) {
                $snapshot = json_decode($line->snapshot, true);
                [$actual, $estimated, $basis] = $this->goodsCost($line);
                $key = match ($data['group']) {
                    'order' => [$order->id, $order->number],
                    'product' => [
                        $products[$line->offer_id] ?? $line->offer_id,
                        ['name' => $snapshot['name'] ?? null, 'sku' => $snapshot['sku'] ?? null],
                    ],
                    'shop' => [$snapshot['shop']['id'] ?? null, $snapshot['shop']['name'] ?? null],
                    'campaign' => [$usage->id ?? 'none', $usage ? json_decode($usage->name, true) : null],
                };
                $add = function (array &$target) use (
                    $line,
                    $revenues,
                    $refunds,
                    $i,
                    $actual,
                    $estimated,
                    $basis,
                    $order,
                ) {
                    $target['quantity'] += (int) $line->quantity;
                    $target['revenue'] += $revenues[$i];
                    $target['refunds'] += $refunds[$i];
                    $target['goods_cost_actual'] += $actual;
                    $target['goods_cost_estimated'] += $estimated;
                    $target['bases'][$basis] = true;
                    $target['order_ids'][$order->id] = true;
                };
                $groups[(string) $key[0]] ??= $this->blank((string) $key[0], $key[1]);
                $add($groups[(string) $key[0]]);
                $add($totals);
            }
            // Order-level amounts go to the order's group (or its campaign), and always to the total.
            $orderKey = match ($data['group']) {
                'order' => $order->id,
                'campaign' => $usage->id ?? 'none',
                default => null,
            };
            if ($orderKey !== null) {
                $groups[$orderKey]['delivery_collected'] += (int) $order->delivery_total;
                $groups[$orderKey]['promotion_subsidies'] += $subsidy;
            }
            $totals['delivery_collected'] += (int) $order->delivery_total;
            $totals['promotion_subsidies'] += $subsidy;
        }

        return [
            'from' => $data['from'],
            'to' => $data['to'],
            'group' => $data['group'],
            'currency' => 'XAF',
            'groups' => array_values(array_map(fn ($g) => $this->finish($g), $groups)),
            'total' => $this->finish($totals),
            // Not part of any figure above: their amount is not known yet.
            'unknown_costs' => array_values(array_unique($unknown)),
            'definition' => 'items revenue net of order discounts − paid refunds − goods cost − delivery promotion subsidies; delivery collected shown apart, its actual cost unknown',
            'accounting_result' => false,
        ];
    }

    /** Actual cost of traced units; the rest estimated from the agreement captured at the sale. */
    private function goodsCost(object $line): array
    {
        $quantity = (int) $line->quantity;
        $actual = 0;
        $known = 0;
        if ($line->reservation_id) {
            foreach (
                DB::table('inventory_reservation_lines AS r')
                    ->join('inventory_balances AS b', 'b.id', '=', 'r.balance_id')
                    ->join('stock_lots AS lot', 'lot.id', '=', 'b.lot_id')
                    ->where('r.reservation_id', $line->reservation_id)
                    ->get(['r.quantity', 'lot.unit_cost']) as $r
            ) {
                $actual += (int) $r->quantity * (int) $r->unit_cost;
                $known += (int) $r->quantity;
            }
        }
        foreach (
            DB::table('order_purchase_allocations AS a')
                ->join('purchase_lines AS p', 'p.id', '=', 'a.purchase_line_id')
                ->where('a.order_line_id', $line->id)
                ->get(['a.quantity', 'a.released_quantity', 'p.unit_cost']) as $a
        ) {
            $units = max(0, (int) $a->quantity - (int) $a->released_quantity);
            $actual += $units * (int) $a->unit_cost;
            $known += $units;
        }
        $known = min($known, $quantity);
        $remaining = $quantity - $known;
        $estimated = 0;
        if ($remaining > 0) {
            $internal = json_decode($line->internal_snapshot, true);
            $purchase = $internal['agreement']['calculation']['purchase_total'] ?? null;
            $estimated =
                $purchase !== null
                    ? intdiv((int) $purchase * $remaining + intdiv($quantity, 2), $quantity)
                    : (int) ($internal['reference_price'] ?? 0) * $remaining;
        }

        return [$actual, $estimated, $remaining === 0 ? 'actual' : ($known > 0 ? 'partly_estimated' : 'estimated')];
    }

    /** Spreads an order amount over its lines in proportion, to the unit, with no franc lost. */
    private function apportion(array $weights, int $amount): array
    {
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return array_fill(0, count($weights), 0);
        }
        $shares = [];
        $remainders = [];
        foreach ($weights as $i => $w) {
            $shares[$i] = intdiv($w * $amount, $sum);
            $remainders[$i] = ($w * $amount) % $sum;
        }
        arsort($remainders);
        foreach (array_slice(array_keys($remainders), 0, $amount - array_sum($shares)) as $i) {
            $shares[$i]++;
        }

        return $shares;
    }

    private function blank(string $key, mixed $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'order_ids' => [],
            'quantity' => 0,
            'revenue' => 0,
            'refunds' => 0,
            'goods_cost_actual' => 0,
            'goods_cost_estimated' => 0,
            'promotion_subsidies' => 0,
            'delivery_collected' => 0,
            'bases' => [],
        ];
    }

    private function finish(array $g): array
    {
        $contribution =
            $g['revenue'] -
            $g['refunds'] -
            $g['goods_cost_actual'] -
            $g['goods_cost_estimated'] -
            $g['promotion_subsidies'];

        return [
            'key' => $g['key'],
            'label' => $g['label'],
            'orders' => count($g['order_ids']),
            'quantity' => $g['quantity'],
            'revenue' => (string) $g['revenue'],
            'refunds' => (string) $g['refunds'],
            'goods_cost_actual' => (string) $g['goods_cost_actual'],
            'goods_cost_estimated' => (string) $g['goods_cost_estimated'],
            'promotion_subsidies' => (string) $g['promotion_subsidies'],
            'delivery_collected' => (string) $g['delivery_collected'],
            'contribution' => (string) $contribution,
            'cost_basis' => isset($g['bases']['estimated']) || isset($g['bases']['partly_estimated'])
                    ? (isset($g['bases']['actual']) || isset($g['bases']['partly_estimated'])
                        ? 'partly_estimated'
                        : 'estimated')
                    : 'actual',
        ];
    }
}
