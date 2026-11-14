<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderPreparation;
use App\Domain\Orders\OrderPurchases;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class OrderPreparationTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, PreparedOrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    public function test_a_paid_order_is_picked_from_its_retained_stock_then_declared_ready(): void
    {
        $o = $this->warehouseOrder(2);
        $view = $this->preparation($o['order']);
        $this->assertSame('not_started', $view['status']);
        $this->assertSame(2, $view['lines'][0]['held_quantity']);
        $this->assertSame(0, $view['lines'][0]['awaiting_stock_quantity']);
        $source = $view['lines'][0]['sources'][0];
        $this->assertSame('warehouse', $source['origin']);
        $this->assertSame('TEST', $source['locations'][0]['location_code']);
        // The picking list is for the stock team: no prices, costs or customer address.
        $this->assertStringNotContainsString('unit_price', json_encode($view));
        $this->assertStringNotContainsString('unit_cost', json_encode($view));
        $this->assertStringNotContainsString('landmark', json_encode($view));

        $key = $this->fixtureId();
        $picked = $this->op(
            'POST',
            'orders/'.$o['order']['id'].'/preparation/picks',
            ['version' => $view['version'], 'reservation_id' => $source['reservation_id'], 'identifiers' => []],
            $key,
        )
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.lines.0.prepared_quantity', 2)
            ->json('data');
        // The same request replayed returns the recorded result without a second issue.
        $this->op(
            'POST',
            'orders/'.$o['order']['id'].'/preparation/picks',
            ['version' => $view['version'], 'reservation_id' => $source['reservation_id'], 'identifiers' => []],
            $key,
        )
            ->assertCreated()
            ->assertJsonPath('data.version', $picked['version']);
        $this->pick($o['order'], $source['reservation_id'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('reservation_id', 'error.fields');
        $this->assertSame(3, (int) DB::table('inventory_balances')->sum('on_hand'));
        $this->assertSame(0, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertSame(1, DB::table('inventory_movements')->where('kind', 'sale_issue')->count());
        $this->assertSame(
            $o['order']['number'],
            DB::table('inventory_movements')->where('kind', 'sale_issue')->value('reason'),
        );
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $source['reservation_id'],
            'status' => 'consumed',
        ]);

        $this->ready($o['order'], 2)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.packages', 2);
        $this->ready($o['order'])->assertUnprocessable();
        $this->assertTrue(DB::table('order_events')->where('action', 'preparation_ready')->exists());

        $this->client($o['client']);
        $this->getJson('/api/v1/orders/'.$o['order']['id'])
            ->assertOk()
            ->assertJsonPath('data.preparation_status', 'ready')
            ->assertJsonPath('data.lines.0.prepared_quantity', 2)
            ->assertJsonPath('data.total', $o['order']['total']);
    }

    public function test_readiness_requires_every_unit_and_unreceived_supplier_units_stay_visible(): void
    {
        $n = $this->network();
        $this->inventoryPolicy();
        $n['rule'] = $this->slotRule();
        $client = $this->client();
        $quote = $this->quote($this->fill([[$n['second']['offer'], 3]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $n['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $total = (string) (3000 + (int) $quote['payable_delivery']);
        $order = $this->order($quote, $total)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-PREP', $attempt['id'], (int) $total);
        $this->notify([
            'transaction_id' => 'TX-PREP',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->operationUser();
        $line = $order['lines'][0]['id'];
        $request = $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/supplier-reservations', [
            'version' => 1,
            'expires_at' => now()->addHour()->toIso8601String(),
        ])->json('data');
        $this->op('POST', 'supplier-reservations/'.$request['id'].'/confirm', [
            'reference' => 'TEST REAL CONFIRMATION',
        ])->assertOk();
        $purchase = $this->purchase($n['second'], $this->contract($n['second']), 3, true, 'customer_needs');
        $allocation = $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/purchase-allocations', [
            'version' => (int) DB::table('order_lines')->where('id', $line)->value('supply_version'),
            'purchase_line_id' => $purchase['lines'][0]['id'],
            'quantity' => 3,
        ])
            ->assertCreated()
            ->json('data');
        $receipt = $this->receipt($n['second'], $purchase, $this->location(), 1);
        $held = $this->op('POST', 'purchase-allocations/'.$allocation['id'].'/receipts', [
            'version' => (int) DB::table('order_purchase_allocations')
                ->where('id', $allocation['id'])
                ->value('version'),
            'receipt_line_id' => DB::table('stock_lots')->where('id', $receipt['lot_ids'][0])->value('receipt_line_id'),
            'quantity' => 1,
        ])
            ->assertCreated()
            ->json('data');

        $view = $this->preparation($order);
        $this->assertSame(1, $view['lines'][0]['held_quantity']);
        $this->assertSame(2, $view['lines'][0]['awaiting_stock_quantity']);
        $this->assertSame('receipt', $view['lines'][0]['sources'][0]['origin']);
        $this->pick($order, $held['reservation_id'])->assertCreated()->assertJsonPath('data.status', 'partial');
        $this->ready($order)->assertUnprocessable()->assertJsonValidationErrors('lines', 'error.fields');
        // Units already issued for the order can no longer be handed back as a simple receipt release.
        $this->op('POST', 'receipt-allocations/'.$held['id'].'/release', [
            'version' => $held['version'],
            'reason' => 'Test release attempt',
        ])->assertUnprocessable();
        $this->assertSame(1, app(OrderPurchases::class)->receivedQuantity($line));
    }

    public function test_serialised_units_leave_only_with_the_exact_identifiers_scanned(): void
    {
        $o = $this->warehouseOrder(2, 'serial', ['TEST-SERIAL-1', 'TEST-SERIAL-2']);
        $source = $this->preparation($o['order'])['lines'][0]['sources'][0];
        $this->assertSame(['TEST-SERIAL-1', 'TEST-SERIAL-2'], $source['identifiers']);
        $this->pick($o['order'], $source['reservation_id'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identifiers', 'error.fields');
        $this->pick($o['order'], $source['reservation_id'], ['TEST-SERIAL-1', 'TEST-SERIAL-9'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('identifiers', 'error.fields');
        $this->pick($o['order'], $source['reservation_id'], ['TEST-SERIAL-2', 'TEST-SERIAL-1'])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.picks.0.identifiers', ['TEST-SERIAL-1', 'TEST-SERIAL-2']);
        $this->assertSame(2, DB::table('inventory_units')->where('status', 'consumed')->count());
    }

    public function test_an_anomaly_blocks_readiness_until_resolved_and_withdraws_an_earlier_readiness(): void
    {
        $o = $this->warehouseOrder(1);
        $view = $this->preparation($o['order']);
        $line = $view['lines'][0]['id'];
        $anomaly = $this->op('POST', 'orders/'.$o['order']['id'].'/preparation/anomalies', [
            'version' => $view['version'],
            'order_line_id' => $line,
            'kind' => 'damaged',
            'quantity' => 1,
            'note' => 'Box crushed on the shelf (test).',
        ])
            ->assertCreated()
            ->json('data');
        $this->assertSame('anomaly', $this->preparation($o['order'])['status']);
        $this->pick($o['order'], $view['lines'][0]['sources'][0]['reservation_id'])->assertCreated();
        $this->ready($o['order'])->assertUnprocessable()->assertJsonValidationErrors('anomalies', 'error.fields');
        $this->client($o['client']);
        $customer = $this->getJson('/api/v1/orders/'.$o['order']['id'])
            ->assertOk()
            ->assertJsonPath('data.preparation_status', 'in_progress')
            ->json('data');
        $this->assertStringNotContainsString('crushed', json_encode($customer));

        $this->operationUser('stock');
        $this->op('POST', 'preparation-anomalies/'.$anomaly['id'].'/resolve', [
            'version' => $anomaly['version'],
            'note' => 'Replacement packaging checked (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        $this->op('POST', 'preparation-anomalies/'.$anomaly['id'].'/resolve', [
            'version' => $anomaly['version'] + 1,
            'note' => 'Again',
        ])->assertUnprocessable();
        $this->assertSame('partial', $this->preparation($o['order'])['status']);
        $this->ready($o['order'])->assertOk()->assertJsonPath('data.status', 'ready');

        $this->op('POST', 'orders/'.$o['order']['id'].'/preparation/anomalies', [
            'version' => $this->preparation($o['order'])['version'],
            'order_line_id' => $line,
            'kind' => 'mismatch',
            'quantity' => 1,
            'note' => 'Label does not match the parcel (test).',
        ])->assertCreated();
        $after = $this->preparation($o['order']);
        $this->assertSame('anomaly', $after['status']);
        $this->assertNull($after['prepared_at']);
        $this->assertTrue(
            DB::table('order_events')
                ->where('action', 'preparation_anomaly')
                ->where('data', 'like', '%"readiness_withdrawn":true%')
                ->exists(),
        );
    }

    public function test_rights_versions_unpaid_orders_and_the_queue(): void
    {
        $o = $this->warehouseOrder(1);
        $view = $this->preparation($o['order']);
        $reservation = $view['lines'][0]['sources'][0]['reservation_id'];
        $this->pick($o['order'], $reservation, [], $view['version'] + 1)->assertStatus(409);
        $this->op('GET', 'preparations?status=not_started')
            ->assertOk()
            ->assertJsonPath('data.0.order_id', $o['order']['id'])
            ->assertJsonPath('data.0.required_quantity', 1);
        $this->op('GET', 'preparations?status=ready')->assertOk()->assertJsonCount(0, 'data');

        $this->operationUser('catalogue');
        $this->op('GET', 'orders/'.$o['order']['id'].'/preparation')->assertForbidden();
        $this->pick($o['order'], $reservation, [], $view['version'])->assertForbidden();
        $this->operationUser('manager');
        $this->op('GET', 'orders/'.$o['order']['id'].'/preparation')->assertOk();
        $this->pick($o['order'], $reservation, [], $view['version'])->assertForbidden();

        // The generic stock issue route still refuses a paid order's reservation, even to the owner.
        $this->operationUser();
        $this->op('POST', 'inventory/reservations/'.$reservation.'/issue', [
            'sale_reference' => 'TEST',
            'sale_verified' => true,
            'document_id' => $this->evidence($o['ctx']['supplier'], 'stock_evidence')['id'],
        ])->assertUnprocessable();

        // An unpaid order cannot be picked: its stock is only held for the payment window.
        $this->client($o['client']);
        $quote = $this->quote($this->fill([[$o['ctx']['offer'], 1]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => DB::table('delivery_slot_rules')->value('id'),
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $unpaid = $this->order($quote, '2000')->assertCreated()->json('data');
        $this->operationUser('stock');
        $this->pick($unpaid, DB::table('order_lines')->where('order_id', $unpaid['id'])->value('reservation_id'))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order', 'error.fields');
    }

    private function replace(array $order, string $pickId, int $quantity, array $identifiers = [])
    {
        return $this->op('POST', 'orders/'.$order['id'].'/preparation/picks/'.$pickId.'/replace', [
            'version' => $this->preparation($order)['version'],
            'quantity' => $quantity,
            'identifiers' => $identifiers,
            'condition' => 'damaged',
            'location_id' => DB::table('stock_locations')->value('id'),
            'note' => 'Corner crushed after picking (test).',
        ]);
    }

    public function test_a_damaged_issued_unit_is_set_aside_and_replaced_under_the_same_terms(): void
    {
        $o = $this->warehouseOrder(2);
        $this->operationUser('stock');
        $source = $this->preparation($o['order'])['lines'][0]['sources'][0];
        $this->pick($o['order'], $source['reservation_id'])->assertCreated();
        $this->ready($o['order'], 1)->assertOk();
        $pick = $this->preparation($o['order'])['lines'][0]['picks'][0];

        $this->replace($o['order'], $pick['id'], 3)->assertUnprocessable();
        $view = $this->replace($o['order'], $pick['id'], 1)
            ->assertCreated()
            ->assertJsonPath('data.status', 'partial')
            ->assertJsonPath('data.prepared_at', null)
            ->assertJsonPath('data.lines.0.prepared_quantity', 1)
            ->assertJsonPath('data.lines.0.picks.0.returned_quantity', 1)
            ->json('data');
        $replacement = $view['lines'][0]['sources'][0];
        $this->assertSame('replacement', $replacement['origin']);
        $this->assertSame(1, $replacement['quantity']);
        // The damaged unit is back in stock but set aside; the replacement is held for the paid line.
        $this->assertSame(1, (int) DB::table('inventory_balances')->where('condition', 'damaged')->sum('on_hand'));
        $this->assertSame(1, (int) DB::table('inventory_balances')->where('condition', 'available')->sum('reserved'));
        $this->assertSame(1, DB::table('inventory_movements')->where('kind', 'preparation_return')->count());

        $this->pick($o['order'], $replacement['reservation_id'])
            ->assertCreated()
            ->assertJsonPath('data.lines.0.prepared_quantity', 2);
        $this->ready($o['order'], 1)->assertOk()->assertJsonPath('data.status', 'ready');
        $this->assertSame(
            ['quantity' => 2, 'identifiers' => []],
            app(OrderPreparation::class)->issued($o['order']['id'])[$o['order']['lines'][0]['id']],
        );
        // The same pick cannot give back more than it issued.
        $this->replace($o['order'], $pick['id'], 2)->assertUnprocessable();
    }

    public function test_a_serialised_replacement_swaps_only_the_returned_serial(): void
    {
        $o = $this->warehouseOrder(1, 'serial', ['TEST-SN-1', 'TEST-SN-2']);
        $this->operationUser('stock');
        $source = $this->preparation($o['order'])['lines'][0]['sources'][0];
        $this->pick($o['order'], $source['reservation_id'], $source['identifiers'])->assertCreated();
        $pick = $this->preparation($o['order'])['lines'][0]['picks'][0];
        $this->replace($o['order'], $pick['id'], 1, ['TEST-SN-UNKNOWN'])->assertUnprocessable();
        $view = $this->replace($o['order'], $pick['id'], 1, $pick['identifiers'])->assertCreated()->json('data');
        $replacement = $view['lines'][0]['sources'][0];
        $this->assertNotSame($pick['identifiers'], $replacement['identifiers']);
        $this->pick($o['order'], $replacement['reservation_id'], $replacement['identifiers'])->assertCreated();
        $issued = app(OrderPreparation::class)->issued($o['order']['id']);
        $this->assertSame($replacement['identifiers'], $issued[$o['order']['lines'][0]['id']]['identifiers']);
        $this->assertSame(
            'on_hand',
            DB::table('inventory_units')->where('identifier', $pick['identifiers'][0])->value('status'),
        );
    }

    public function test_preparation_records_are_protected_in_the_database(): void
    {
        $o = $this->warehouseOrder(1);
        $view = $this->preparation($o['order']);
        $this->pick($o['order'], $view['lines'][0]['sources'][0]['reservation_id'])->assertCreated();
        $pick = DB::table('order_preparation_picks')->first();
        foreach (
            [
                fn () => DB::table('order_preparation_picks')
                    ->where('id', $pick->id)
                    ->update(['quantity' => 2]),
                fn () => DB::table('order_preparation_picks')->where('id', $pick->id)->delete(),
                fn () => DB::table('order_preparation_picks')->insert([
                    ...(array) $pick,
                    'id' => $this->fixtureId(),
                    'reservation_id' => DB::table('inventory_reservations')->where('status', '!=', 'consumed')->value('id') ??
                        $this->fixtureId(),
                ]),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('A preparation record was rewritten.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('order_preparation_picks')->count());
            }
        }
    }
}
