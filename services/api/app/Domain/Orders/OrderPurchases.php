<?php

namespace App\Domain\Orders;

use App\Domain\Inventory\Inventory;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Domain\Shared\Money;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Purchase commitments and actual receipt holds, kept separate from supplier debts and the frozen sale. */
final class OrderPurchases
{
    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('order_purchases.'.$code)]);
    }

    private function line(object $order, string $lineId): object
    {
        $line = app(OrderSupply::class)->line($order, $lineId);
        if ($order->closed_at || $line->from_supplier <= $line->cancelled_quantity) {
            self::fail('line', 'LINE_CLOSED');
        }

        return $line;
    }

    /** All writers use order → line → supplier → purchase → purchase line → inventory. */
    private function purchaseLine(string $id): array
    {
        $initial = Records::row('purchase_lines', $id, false);
        $purchase = Records::row('purchase_orders', $initial->purchase_order_id, false);
        Records::supplier($purchase->supplier_id);
        $purchase = Records::row('purchase_orders', $purchase->id);

        return [$purchase, Records::row('purchase_lines', $id)];
    }

    private function conforming(object $line, object $purchase, object $purchaseLine): void
    {
        if ($purchase->status !== 'confirmed' || $purchase->purpose !== 'customer_needs') {
            self::fail('purchase_line_id', 'CONFIRMED_CUSTOMER_PURCHASE_REQUIRED');
        }
        $sale = json_decode($line->snapshot, true);
        $internal = json_decode($line->internal_snapshot, true);
        $terms = json_decode($purchaseLine->commercial_snapshot, true)['terms'];
        $expected = [
            'offer_id' => $line->offer_id,
            'variant_id' => $sale['variant_id'],
            'shop_id' => $sale['shop_id'],
            'supplier_id' => $internal['supplier_id'],
            'sku' => $sale['sku'],
            'attributes' => $sale['attributes'],
            'warranty' => $sale['warranty'],
        ];
        $actual = array_intersect_key($terms, $expected);
        if (
            $purchase->supplier_id !== $internal['supplier_id'] ||
            $purchaseLine->offer_id !== $line->offer_id ||
            app(CommandBus::class)->fingerprint($actual) !== app(CommandBus::class)->fingerprint($expected)
        ) {
            self::fail('purchase_line_id', 'TERMS_MISMATCH');
        }
    }

    private function assigned(string $lineId): int
    {
        return (int) DB::table('order_purchase_allocations')
            ->where('order_line_id', $lineId)
            ->selectRaw('COALESCE(SUM(quantity - released_quantity), 0) AS assigned')
            ->value('assigned');
    }

    public function receivedForAllocation(string $id): int
    {
        return (int) DB::table('order_receipt_allocations')
            ->where('purchase_allocation_id', $id)
            ->where('status', 'held')
            ->sum('quantity');
    }

    public function receivedQuantity(string $lineId): int
    {
        return (int) DB::table('order_receipt_allocations AS r')
            ->join('order_purchase_allocations AS a', 'a.id', '=', 'r.purchase_allocation_id')
            ->join('inventory_reservations AS s', 's.id', '=', 'r.reservation_id')
            ->where('a.order_line_id', $lineId)
            ->where('r.status', 'held')
            ->whereIn('s.status', ['active', 'consumed'])
            ->sum('r.quantity');
    }

    public function assign(object $order, string $lineId, array $data, User $actor): array
    {
        $line = $this->line($order, $lineId);
        abort_unless((int) $line->supply_version === $data['version'], 409);
        [$purchase, $purchaseLine] = $this->purchaseLine($data['purchase_line_id']);
        $this->conforming($line, $purchase, $purchaseLine);
        $assigned = $this->assigned($lineId);
        if ($assigned + $data['quantity'] > $line->from_supplier - $line->cancelled_quantity) {
            self::fail('quantity', 'ORDER_QUANTITY_EXCEEDED');
        }
        $confirmation = $line->supplier_reservation_id
            ? Records::row('supplier_reservations', $line->supplier_reservation_id, false)
            : null;
        if (
            ! $confirmation ||
            ! in_array($confirmation->status, ['confirmed', 'partly_confirmed'], true) ||
            ! CarbonImmutable::parse($confirmation->expires_at, 'UTC')->isFuture() ||
            $assigned + $data['quantity'] - $this->receivedQuantity($lineId) > $confirmation->confirmed_quantity
        ) {
            self::fail('confirmation', 'CONFIRMATION_REQUIRED');
        }
        $committed = (int) DB::table('order_purchase_allocations')
            ->where('purchase_line_id', $purchaseLine->id)
            ->selectRaw('COALESCE(SUM(quantity - released_quantity), 0) AS committed')
            ->value('committed');
        if ($committed + $data['quantity'] > $purchaseLine->quantity + $purchaseLine->approved_overage_quantity) {
            self::fail('quantity', 'PURCHASE_QUANTITY_EXCEEDED');
        }
        Money::multiply((int) $purchaseLine->unit_cost, $data['quantity']);
        $id = Records::id();
        DB::table('order_purchase_allocations')->insert([
            'id' => $id,
            'order_line_id' => $lineId,
            'purchase_line_id' => $purchaseLine->id,
            'quantity' => $data['quantity'],
            'expected_unit_cost' => $purchaseLine->unit_cost,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->refreshLine($line);
        $this->record($line, 'order_purchase_assigned', $id, $actor, ['quantity' => $data['quantity']]);

        return $this->allocationDto(Records::row('order_purchase_allocations', $id, false));
    }

    public function receive(object $order, string $allocationId, array $data, User $actor): array
    {
        $initial = Records::row('order_purchase_allocations', $allocationId, false);
        $line = $this->line($order, $initial->order_line_id);
        [$purchase, $purchaseLine] = $this->purchaseLine($initial->purchase_line_id);
        $this->conforming($line, $purchase, $purchaseLine);
        $allocation = Records::row('order_purchase_allocations', $allocationId);
        abort_unless((int) $allocation->version === $data['version'], 409);
        $receipt = Records::row('receipt_lines', $data['receipt_line_id']);
        if ($receipt->purchase_line_id !== $purchaseLine->id) {
            self::fail('receipt_line_id', 'RECEIPT_MISMATCH');
        }
        if (
            $receipt->excess_quantity > 0 &&
            ! DB::table('receipt_overage_approvals')->where('receipt_line_id', $receipt->id)->exists()
        ) {
            self::fail('receipt_line_id', 'OVERAGE_NOT_APPROVED');
        }
        if (
            $data['quantity'] >
            $allocation->quantity - $allocation->released_quantity - $this->receivedForAllocation($allocationId)
        ) {
            self::fail('quantity', 'ALLOCATION_QUANTITY_EXCEEDED');
        }
        $used = (int) DB::table('order_receipt_allocations')
            ->where('receipt_line_id', $receipt->id)
            ->where('status', 'held')
            ->sum('quantity');
        if ($used + $data['quantity'] > $receipt->quantity) {
            self::fail('quantity', 'RECEIPT_QUANTITY_EXCEEDED');
        }
        $case = DB::table('order_supply_cases')
            ->where('order_line_id', $line->id)
            ->where('status', 'awaiting_choice')
            ->first();
        if (
            $case &&
            $this->receivedQuantity($line->id) + $data['quantity'] >
                $line->from_supplier - $line->cancelled_quantity - $case->missing_quantity
        ) {
            self::fail('case', 'CUSTOMER_CHOICE_REQUIRED');
        }
        Money::multiply((int) $receipt->unit_cost, $data['quantity']);
        $id = Records::id();
        $reservation = app(Inventory::class)->reserveReceipt(
            $line->offer_id,
            $id,
            $data['quantity'],
            $purchaseLine->terms_hash,
            $receipt->id,
            $line->id,
            $actor,
        );
        DB::table('order_receipt_allocations')->insert([
            'id' => $id,
            'purchase_allocation_id' => $allocationId,
            'receipt_line_id' => $receipt->id,
            'reservation_id' => $reservation['id'],
            'quantity' => $data['quantity'],
            'actual_unit_cost' => $receipt->unit_cost,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->bumpAllocation($allocation);
        $this->refreshLine($line);
        $this->record($line, 'order_receipt_held', $id, $actor, [
            'quantity' => $data['quantity'],
            'receipt_line_id' => $receipt->id,
        ]);

        return $this->receiptDto(Records::row('order_receipt_allocations', $id, false));
    }

    public function releasePurchase(object $order, string $id, array $data, User $actor): array
    {
        $initial = Records::row('order_purchase_allocations', $id, false);
        $line = app(OrderSupply::class)->line($order, $initial->order_line_id);
        $this->purchaseLine($initial->purchase_line_id);
        $allocation = Records::row('order_purchase_allocations', $id);
        abort_unless((int) $allocation->version === $data['version'], 409);
        $quantity = $allocation->quantity - $allocation->released_quantity - $this->receivedForAllocation($id);
        if ($quantity < 1) {
            self::fail('allocation', 'NOTHING_RELEASABLE');
        }
        $this->releaseUnits($line, $allocation, $quantity, $data['reason'], $actor);
        $this->refreshLine($line);

        return $this->allocationDto(Records::row('order_purchase_allocations', $id, false));
    }

    public function releaseReceipt(object $order, string $id, array $data, User $actor): array
    {
        $initial = Records::row('order_receipt_allocations', $id, false);
        $initialAllocation = Records::row('order_purchase_allocations', $initial->purchase_allocation_id, false);
        $line = app(OrderSupply::class)->line($order, $initialAllocation->order_line_id);
        $this->purchaseLine($initialAllocation->purchase_line_id);
        $allocation = Records::row('order_purchase_allocations', $initialAllocation->id);
        $receipt = Records::row('order_receipt_allocations', $id);
        abort_unless((int) $receipt->version === $data['version'], 409);
        if (
            $receipt->status !== 'held' ||
            Records::row('inventory_reservations', $receipt->reservation_id, false)->status !== 'active'
        ) {
            self::fail('receipt', 'NOTHING_RELEASABLE');
        }
        app(Inventory::class)->release($receipt->reservation_id, $actor, false, true);
        DB::table('order_receipt_allocations')
            ->where('id', $id)
            ->update([
                'status' => 'released',
                'release_reason' => $data['reason'],
                'version' => $receipt->version + 1,
                'updated_at' => now(),
            ]);
        $this->bumpAllocation($allocation);
        $this->refreshLine($line);
        $this->record($line, 'order_receipt_released', $id, $actor, ['reason' => $data['reason']]);

        return $this->receiptDto(Records::row('order_receipt_allocations', $id, false));
    }

    /** A customer cancels only unreceived units; release the corresponding purchase earmarks, not the supplier debt. */
    public function releaseExcess(object $line, int $remaining, User $actor): void
    {
        $excess = max(0, $this->assigned($line->id) - $remaining);
        $allocations = DB::table('order_purchase_allocations AS a')
            ->join('purchase_lines AS p', 'p.id', '=', 'a.purchase_line_id')
            ->where('a.order_line_id', $line->id)
            ->orderBy('p.purchase_order_id')
            ->orderBy('p.id')
            ->orderBy('a.id')
            ->select('a.*')
            ->get();
        foreach ($allocations as $initial) {
            if ($excess === 0) {
                break;
            }
            $this->purchaseLine($initial->purchase_line_id);
            $allocation = Records::row('order_purchase_allocations', $initial->id);
            $release = min(
                $excess,
                $allocation->quantity - $allocation->released_quantity - $this->receivedForAllocation($allocation->id),
            );
            if ($release > 0) {
                $this->releaseUnits($line, $allocation, $release, 'Customer cancelled unavailable units', $actor);
                $excess -= $release;
            }
        }
        if ($excess > 0) {
            self::fail('quantity', 'RECEIVED_UNITS_PROTECTED');
        }
    }

    private function releaseUnits(object $line, object $allocation, int $quantity, string $reason, User $actor): void
    {
        DB::table('order_purchase_allocations')
            ->where('id', $allocation->id)
            ->update([
                'released_quantity' => $allocation->released_quantity + $quantity,
                'version' => $allocation->version + 1,
                'updated_at' => now(),
            ]);
        $this->record($line, 'order_purchase_released', $allocation->id, $actor, [
            'quantity' => $quantity,
            'reason' => $reason,
        ]);
    }

    private function bumpAllocation(object $allocation): void
    {
        DB::table('order_purchase_allocations')
            ->where('id', $allocation->id)
            ->update(['version' => $allocation->version + 1, 'updated_at' => now()]);
    }

    private function refreshLine(object $line): void
    {
        $received = $this->receivedQuantity($line->id);
        $covered = $received >= $line->from_supplier - $line->cancelled_quantity;
        $case = DB::table('order_supply_cases')->where('order_line_id', $line->id)->orderByDesc('sequence')->first();
        if ($covered && $case && $case->status === 'waiting') {
            DB::table('order_supply_cases')
                ->where('id', $case->id)
                ->update(['status' => 'resolved', 'version' => $case->version + 1, 'updated_at' => now()]);
        }
        $status = $line->supply_status;
        if ($covered && ! in_array($status, ['unavailable', 'refund_pending', 'cancelled'], true)) {
            $status = 'supplier_stock_received';
        } elseif (! $covered && $status === 'supplier_stock_received') {
            $confirmation = $line->supplier_reservation_id
                ? Records::row('supplier_reservations', $line->supplier_reservation_id, false)
                : null;
            $status =
                $confirmation &&
                in_array($confirmation->status, ['confirmed', 'partly_confirmed'], true) &&
                CarbonImmutable::parse($confirmation->expires_at, 'UTC')->isFuture()
                    ? 'supplier_confirmed'
                    : 'to_confirm';
        }
        DB::table('order_lines')
            ->where('id', $line->id)
            ->update([
                'supply_status' => $status,
                'supply_version' => $line->supply_version + 1,
                'updated_at' => now(),
            ]);
    }

    private function record(object $line, string $action, string $id, User $actor, array $data): void
    {
        app(Orders::class)->event($line->order_id, $action, $actor, [
            'line_id' => $line->id,
            'allocation_id' => $id,
            ...$data,
        ]);
        Records::audit($actor, $action, $id, $data);
    }

    public function allocationDto(object $row): array
    {
        $active = $row->quantity - $row->released_quantity;

        return [
            'id' => $row->id,
            'order_line_id' => $row->order_line_id,
            'purchase_line_id' => $row->purchase_line_id,
            'quantity' => (int) $row->quantity,
            'released_quantity' => (int) $row->released_quantity,
            'active_quantity' => $active,
            'received_quantity' => $this->receivedForAllocation($row->id),
            'expected_unit_cost' => (string) $row->expected_unit_cost,
            'active_expected_cost' => (string) Money::multiply((int) $row->expected_unit_cost, $active),
            'version' => (int) $row->version,
            'receipts' => DB::table('order_receipt_allocations')
                ->where('purchase_allocation_id', $row->id)
                ->orderBy('created_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($receipt) => $this->receiptDto($receipt)),
        ];
    }

    public function receiptDto(object $row): array
    {
        return [
            'id' => $row->id,
            'purchase_allocation_id' => $row->purchase_allocation_id,
            'receipt_line_id' => $row->receipt_line_id,
            'reservation_id' => $row->reservation_id,
            'quantity' => (int) $row->quantity,
            'actual_unit_cost' => (string) $row->actual_unit_cost,
            'actual_cost' => (string) Money::multiply((int) $row->actual_unit_cost, (int) $row->quantity),
            'status' => $row->status,
            'release_reason' => $row->release_reason,
            'version' => (int) $row->version,
        ];
    }

    public function view(object $order): array
    {
        return [
            'order_id' => $order->id,
            'lines' => DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->get()->map(
                fn ($line) => [
                    'id' => $line->id,
                    'supplier_quantity' => (int) $line->from_supplier,
                    'cancelled_quantity' => (int) $line->cancelled_quantity,
                    'assigned_quantity' => $this->assigned($line->id),
                    'received_quantity' => $this->receivedQuantity($line->id),
                    'supply_version' => (int) $line->supply_version,
                    'allocations' => DB::table('order_purchase_allocations')
                        ->where('order_line_id', $line->id)
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->get()
                        ->map(fn ($allocation) => $this->allocationDto($allocation)),
                ],
            ),
        ];
    }
}
