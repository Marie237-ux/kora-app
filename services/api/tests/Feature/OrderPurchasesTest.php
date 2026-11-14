<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class OrderPurchasesTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function setupSupply(int $quantity = 2): array
    {
        $n = $this->network();
        $this->inventoryPolicy();
        $n['rule'] = $this->slotRule();
        $c = $this->customerOrder($n, $quantity);
        $this->operationUser();
        $c['contract'] = $this->contract($n['second']);
        $c['location'] = $this->location();

        return $c;
    }

    private function customerOrder(array $n, int $quantity): array
    {
        $client = $this->client();
        $quote = $this->quote($this->fill([[$n['second']['offer'], $quantity]]), $this->address('Akwa'))
            ->assertCreated()
            ->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $n['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
        $total = (string) ($quantity * 1000 + (int) $quote['payable_delivery']);
        $order = $this->order($quote, $total)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $tx = 'TEST-'.$this->fixtureId();
        $this->gateway->paid($tx, $attempt['id'], (int) $total);
        $this->notify([
            'transaction_id' => $tx,
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->operationUser();
        $line = $order['lines'][0]['id'];
        $reservation = $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/supplier-reservations', [
            'version' => 1,
            'expires_at' => now()->addHour()->toIso8601String(),
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST REAL CONFIRMATION',
        ])->assertOk();

        return compact('n', 'client', 'order', 'line');
    }

    private function customerPurchase(
        array $c,
        int $quantity,
        bool $confirm = true,
        string $purpose = 'customer_needs',
    ): array {
        $purchase = $this->op('POST', 'purchases', [
            'supplier_id' => $c['n']['second']['supplier'],
            'contract_id' => $c['contract']['id'],
            'purpose' => $purpose,
            'reference' => 'TEST-'.$this->fixtureId(),
            'lines' => [['offer_id' => $c['n']['second']['offer'], 'quantity' => $quantity]],
        ])
            ->assertCreated()
            ->json('data');

        return $confirm
            ? $this->op('POST', 'purchases/'.$purchase['id'].'/confirm', [
                'version' => $purchase['version'],
                'supplier_confirmation_reference' => 'TEST PURCHASE CONFIRMED',
            ])
                ->assertOk()
                ->json('data')
            : $purchase;
    }

    private function assign(array $c, array $purchase, int $quantity)
    {
        return $this->op('POST', 'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/purchase-allocations', [
            'version' => (int) DB::table('order_lines')->where('id', $c['line'])->value('supply_version'),
            'purchase_line_id' => $purchase['lines'][0]['id'],
            'quantity' => $quantity,
        ]);
    }

    private function receiveFor(array $c, array $purchase, int $quantity, array $overrides = []): string
    {
        $receipt = $this->receipt($c['n']['second'], $purchase, $c['location'], $quantity, $overrides);

        return DB::table('stock_lots')->where('id', $receipt['lot_ids'][0])->value('receipt_line_id');
    }

    private function attach(array $allocation, string $receipt, int $quantity)
    {
        return $this->op('POST', 'purchase-allocations/'.$allocation['id'].'/receipts', [
            'version' => (int) DB::table('order_purchase_allocations')
                ->where('id', $allocation['id'])
                ->value('version'),
            'receipt_line_id' => $receipt,
            'quantity' => $quantity,
        ]);
    }

    public function test_a_paid_order_can_be_sourced_by_multiple_purchases_without_rewriting_its_sale(): void
    {
        $c = $this->setupSupply(3);
        $first = $this->customerPurchase($c, 1);
        $second = $this->customerPurchase($c, 2);
        $this->assign($c, $first, 1)->assertCreated()->assertJsonPath('data.active_expected_cost', '1000');
        $this->assign($c, $second, 2)->assertCreated()->assertJsonPath('data.active_expected_cost', '2000');
        $this->assign($c, $second, 1)->assertUnprocessable();
        $this->op('GET', 'orders/'.$c['order']['id'].'/procurement')
            ->assertOk()
            ->assertJsonPath('data.lines.0.assigned_quantity', 3)
            ->assertJsonCount(2, 'data.lines.0.allocations');
        $this->assertDatabaseCount('supplier_obligations', 0);
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertOk()
            ->assertJsonPath('data.total', $c['order']['total']);
    }

    public function test_a_grouped_purchase_and_invoice_can_cover_two_orders_without_duplicating_the_supplier_debt(): void
    {
        $c = $this->setupSupply(2);
        $other = $this->customerOrder($c['n'], 1);
        $purchase = $this->customerPurchase($c, 3);
        $this->invoice($c['n']['second'], $purchase, 3);
        $this->assign($c, $purchase, 2)->assertCreated();
        $this->assign($other, $purchase, 1)->assertCreated();
        $this->assertSame(1, DB::table('supplier_obligations')->count());
        $this->assertSame(3000, (int) DB::table('supplier_obligations')->sum('amount'));
        $this->assertSame(3, (int) DB::table('order_purchase_allocations')->sum('quantity'));
    }

    public function test_receipt_allocations_hold_the_exact_lots_and_actual_costs_and_survive_the_payment_timeout(): void
    {
        $c = $this->setupSupply(3);
        $purchase = $this->customerPurchase($c, 3);
        $allocation = $this->assign($c, $purchase, 3)->assertCreated()->json('data');
        $first = $this->receiveFor($c, $purchase, 1);
        $second = $this->receiveFor($c, $purchase, 2, [
            'unit_cost' => 1200,
            'variance_reason' => 'TEST AGREED VARIANCE',
        ]);
        $held = $this->attach($allocation, $first, 1)
            ->assertCreated()
            ->assertJsonPath('data.actual_cost', '1000')
            ->json('data');
        $this->attach($allocation, $second, 2)->assertCreated()->assertJsonPath('data.actual_cost', '2400');
        $this->attach($allocation, $first, 1)->assertUnprocessable();
        $this->assertSame(3, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $held['reservation_id'],
            'order_line_id' => $c['line'],
        ]);
        $this->travel(61)->minutes();
        $this->artisan('inventory:expire')->assertSuccessful();
        $this->artisan('orders:expire-supply')->assertSuccessful();
        $this->assertSame(3, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertDatabaseCount('order_supply_cases', 0);
        $this->client($c['client']);
        $view = $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.procurement_received_quantity', 3)
            ->assertJsonPath('data.lines.0.supply_status', 'supplier_stock_received')
            ->json('data');
        $this->assertStringNotContainsString('actual_unit_cost', json_encode($view));
        $this->assertStringNotContainsString('purchase_line_id', json_encode($view));
        $this->assertSame($c['order']['total'], $view['total']);
    }

    public function test_the_last_purchase_units_cannot_be_assigned_to_two_customer_orders(): void
    {
        $c = $this->setupSupply(1);
        $other = $this->customerOrder($c['n'], 1);
        $purchase = $this->customerPurchase($c, 1);
        $this->assign($c, $purchase, 1)->assertCreated();
        $this->assign($other, $purchase, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('quantity', 'error.fields');
        $this->assertDatabaseCount('order_purchase_allocations', 1);
    }

    public function test_conformity_confirmation_and_purchase_purpose_are_enforced(): void
    {
        $c = $this->setupSupply();
        $draft = $this->customerPurchase($c, 2, false);
        $this->assign($c, $draft, 2)->assertUnprocessable();
        $warehouse = $this->customerPurchase($c, 2, true, 'warehouse_stock');
        $this->assign($c, $warehouse, 2)->assertUnprocessable();
        DB::table('offers')
            ->where('id', $c['n']['second']['offer'])
            ->update(['warranty' => json_encode(['fr' => 'Autre', 'en' => 'Other'])]);
        $changed = $this->customerPurchase($c, 2);
        $this->assign($c, $changed, 2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('purchase_line_id', 'error.fields');
        $this->assertDatabaseCount('order_purchase_allocations', 0);
    }

    public function test_an_expired_supplier_confirmation_cannot_start_an_additional_purchase_allocation(): void
    {
        $c = $this->setupSupply();
        $purchase = $this->customerPurchase($c, 2);
        $this->travel(61)->minutes();
        $this->assign($c, $purchase, 2)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('confirmation', 'error.fields');
    }

    public function test_a_reasoned_release_frees_stock_and_the_earmark_but_does_not_cancel_the_purchase_or_debt(): void
    {
        $c = $this->setupSupply();
        $purchase = $this->customerPurchase($c, 2);
        $this->invoice($c['n']['second'], $purchase, 2);
        $allocation = $this->assign($c, $purchase, 2)->assertCreated()->json('data');
        $receipt = $this->receiveFor($c, $purchase, 2);
        $held = $this->attach($allocation, $receipt, 2)->assertCreated()->json('data');
        $this->op('POST', 'inventory/reservations/'.$held['reservation_id'].'/release')->assertUnprocessable();
        $proof = $this->evidence($c['n']['second']['supplier'], 'stock_evidence');
        $this->op('POST', 'inventory/reservations/'.$held['reservation_id'].'/issue', [
            'sale_reference' => 'TEST CANNOT ISSUE OUTSIDE ORDER',
            'sale_verified' => true,
            'document_id' => $proof['id'],
        ])->assertUnprocessable();
        $this->op('POST', 'purchase-allocations/'.$allocation['id'].'/release', [
            'version' => 2,
            'reason' => 'TEST CANNOT RELEASE RECEIVED UNITS',
        ])->assertUnprocessable();
        $this->op('POST', 'receipt-allocations/'.$held['id'].'/release', [
            'version' => 1,
            'reason' => 'TEST REALLOCATION',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->op('POST', 'purchase-allocations/'.$allocation['id'].'/release', [
            'version' => 3,
            'reason' => 'TEST REALLOCATION',
        ])
            ->assertOk()
            ->assertJsonPath('data.active_quantity', 0);
        $this->assertSame(0, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertSame(2, (int) DB::table('inventory_balances')->sum('on_hand'));
        $this->assertSame(2000, (int) DB::table('supplier_obligations')->sum('amount'));
        $this->assertDatabaseHas('purchase_orders', ['id' => $purchase['id'], 'status' => 'confirmed']);
    }

    public function test_a_customer_cancels_unreceived_units_without_cancelling_received_goods_or_supplier_debt(): void
    {
        $c = $this->setupSupply();
        $purchase = $this->customerPurchase($c, 2);
        $this->invoice($c['n']['second'], $purchase, 2);
        $allocation = $this->assign($c, $purchase, 2)->assertCreated()->json('data');
        $receipt = $this->receiveFor($c, $purchase, 1);
        $this->attach($allocation, $receipt, 1)->assertCreated();
        $case = $this->op('POST', 'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $c['line'])->value('supply_version'),
            'missing_quantity' => 1,
            'message' => ['fr' => 'Test rupture', 'en' => 'Test shortage'],
            'reference' => 'TEST CALL',
        ])
            ->assertOk()
            ->json('data');
        $this->client($c['client']);
        $this->api('POST', 'orders/'.$c['order']['id'].'/supply-cases/'.$case['id'].'/choice', [
            'version' => $case['version'],
            'choice' => 'refund_missing',
            'accepted_refund_amount' => '1000',
        ])->assertOk();
        $this->assertDatabaseHas('order_purchase_allocations', ['id' => $allocation['id'], 'released_quantity' => 1]);
        $this->assertSame(1, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertSame(2000, (int) DB::table('supplier_obligations')->sum('amount'));
        $this->operationUser();
        $this->op('POST', 'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $c['line'])->value('supply_version'),
            'missing_quantity' => 1,
            'message' => ['fr' => 'Test', 'en' => 'Test'],
            'reference' => 'TEST CANNOT CANCEL HELD STOCK',
        ])->assertUnprocessable();
    }

    public function test_quarantined_goods_and_unapproved_overages_cannot_be_used_as_received_stock(): void
    {
        $c = $this->setupSupply();
        $purchase = $this->customerPurchase($c, 2);
        $allocation = $this->assign($c, $purchase, 2)->assertCreated()->json('data');
        $quarantine = $this->receiveFor($c, $purchase, 2, ['quality' => 'quarantine']);
        $this->attach($allocation, $quarantine, 1)->assertUnprocessable();
        $overage = $this->receiveFor($c, $purchase, 1, [
            'quality' => 'quarantine',
            'variance_reason' => 'TEST OVERAGE',
        ]);
        $this->attach($allocation, $overage, 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_line_id', 'error.fields');
        $this->assertDatabaseCount('order_receipt_allocations', 0);
    }

    public function test_received_holds_respect_serials_and_can_allocate_a_partial_receipt_below_the_catalogue_minimum(): void
    {
        $c = $this->setupSupply();
        $profile = DB::table('inventory_profiles')->where('variant_id', $c['n']['second']['variant'])->first();
        $this->op('PUT', 'inventory/profiles/'.$c['n']['second']['variant'], [
            'version' => $profile->version,
            'tracking_mode' => 'serial',
            'requires_expiry' => false,
            'logistics' => json_decode($profile->logistics, true),
        ])->assertOk();
        DB::table('offers')
            ->where('id', $c['n']['second']['offer'])
            ->update(['minimum_quantity' => 2]);
        $purchase = $this->customerPurchase($c, 2);
        $allocation = $this->assign($c, $purchase, 2)->assertCreated()->json('data');
        $receipt = $this->receiveFor($c, $purchase, 2, ['identifiers' => ['TEST-SERIAL-A', 'TEST-SERIAL-B']]);
        $this->attach($allocation, $receipt, 1)->assertCreated();
        $this->assertSame(1, DB::table('inventory_units')->where('status', 'reserved')->count());
        $this->assertSame(1, DB::table('inventory_units')->where('status', 'on_hand')->count());
    }

    public function test_allocations_are_versioned_idempotent_and_hidden_from_clients_and_read_only_roles(): void
    {
        $c = $this->setupSupply();
        $purchase = $this->customerPurchase($c, 2);
        $key = $this->fixtureId();
        $body = ['version' => 3, 'purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 2];
        $path = 'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/purchase-allocations';
        $first = $this->op('POST', $path, $body, $key)->assertCreated()->json('data');
        $this->op('POST', $path, $body, $key)->assertCreated()->assertJsonPath('data.id', $first['id']);
        $this->op('POST', $path, $body)->assertConflict();
        $this->op('POST', $path, [...$body, 'quantity' => 1], $key)->assertConflict();
        $this->assertDatabaseCount('order_purchase_allocations', 1);
        $this->client($c['client']);
        $this->op('GET', 'orders/'.$c['order']['id'].'/procurement')->assertForbidden();
        $this->op('POST', $path, $body)->assertForbidden();
        $this->operationUser('manager');
        $this->op('POST', $path, $body)->assertForbidden();
    }

    public function test_upgrading_preserves_an_existing_active_warehouse_hold_of_a_paid_order(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'PostgreSQL upgrade proof: SQLite cannot rebuild a referenced table inside the fixture transaction.',
            );
        }
        $c = $this->setupSupply();
        $purchase = $this->customerPurchase($c, 2);
        $this->receiveFor($c, $purchase, 2);
        $legacy = $this->op('POST', 'inventory/reservations', [
            'offer_id' => $c['n']['second']['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 2,
        ])
            ->assertCreated()
            ->json('data');
        DB::table('order_lines')
            ->where('id', $c['line'])
            ->update([
                'from_warehouse' => 2,
                'from_supplier' => 0,
                'reservation_id' => $legacy['id'],
            ]);
        // Simulate the schema and paid hold that existed immediately before this incremental migration.
        $migration = require database_path('migrations/2026_11_04_000019_allocate_order_purchases.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseHas('inventory_reservations', ['id' => $legacy['id'], 'order_line_id' => $c['line']]);
        $this->travel(11)->minutes();
        $this->artisan('inventory:expire')->assertSuccessful();
        $this->assertSame(2, (int) DB::table('inventory_balances')->sum('reserved'));
    }
}
