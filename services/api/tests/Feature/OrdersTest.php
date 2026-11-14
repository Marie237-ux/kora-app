<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class OrdersTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    public function test_an_order_freezes_its_lines_and_needs_the_exact_accepted_total_once_per_quote(): void
    {
        $c = $this->checkout(false);
        $this->order($c['quote'])->assertUnprocessable()->assertJsonValidationErrors('slot', 'error.fields');
        $c['quote'] = $this->api('POST', 'cart/delivery-quotes/'.$c['quote']['id'].'/slot', [
            'version' => $c['quote']['version'],
            'rule_id' => $c['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
        $this->order($c['quote'], '2400')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('accepted_total', 'error.fields');
        $key = $this->fixtureId();
        $body = [
            'quote_id' => $c['quote']['id'],
            'quote_version' => $c['quote']['version'],
            'accepted_total' => '2500',
        ];
        $order = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $body)
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.items_total', '1000')
            ->assertJsonPath('data.delivery_total', '1500')
            ->assertJsonPath('data.total', '2500')
            ->assertJsonPath('data.payable', true)
            ->assertJsonPath('data.lines.0.shop.name', 'Test shop')
            ->json('data');
        $this->assertMatchesRegularExpression('/^CMD-\d{7}$/', $order['number']);
        $this->assertArrayNotHasKey('internal_lines', $order);
        $this->assertStringNotContainsString('reference_price', json_encode($order));
        // A replay returns the same order; a new request on the same quote cannot create a second one.
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/orders', $body)
            ->assertJsonPath('data.id', $order['id']);
        $this->order($c['quote'])->assertConflict();
        $this->assertSame(1, DB::table('orders')->count());

        DB::table('offers')
            ->where('id', $c['n']['second']['offer'])
            ->update(['selling_price' => 9999]);
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.unit_price', '1000');
    }

    public function test_only_a_matching_server_check_confirms_payment_and_purchased_lines_leave_the_cart(): void
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->assertSame('pending', $attempt['status']);
        $this->assertSame('https://payment.test/pay/'.$attempt['id'], $attempt['payment_url']);
        $this->assertSame(2500, $this->gateway->started[0]['amount']);
        // Asking again while the page is still usable offers the same attempt, not a second charge.
        $this->assertSame($attempt['id'], $this->pay($order)['id']);
        $this->assertCount(1, $this->gateway->started);

        // Browser return: the provider does not confirm yet, so nothing changes.
        $this->api('POST', 'orders/'.$order['id'].'/payments/'.$attempt['id'].'/check', [
            'transaction_id' => 'TX-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'awaiting_payment');

        $fields = ['transaction_id' => 'TX-1', 'payment_ref' => $attempt['id'], 'status' => '1', 'amount' => '2500'];
        // A forged body claiming success is refused before any check.
        $this->notify([...$fields, 'sign' => 'forged'])->assertForbidden();
        $this->gateway->paid('TX-1', $attempt['id'], 2500);
        $this->notify([...$fields, 'sign' => 'valid-test-signature'])
            ->assertOk()
            ->assertSee('received');
        $this->notify([...$fields, 'sign' => 'valid-test-signature'])->assertOk();

        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.payment.status', 'confirmed')
            ->assertJsonPath('data.payment.payment_url', null);
        $this->assertSame(
            1,
            DB::table('order_events')->where('order_id', $order['id'])->where('action', 'paid')->count(),
        );
        $this->assertSame(
            'confirmed',
            DB::table('delivery_quotes')->where('id', $c['quote']['id'])->value('slot_status'),
        );
        $cart = $this->getJson('/api/v1/cart')->json('data');
        $this->assertSame([$c['n']['first']['offer']], array_column($cart['lines'], 'offer_id'));
        $this->assertSame(0, DB::table('payment_incidents')->count());
        $this->assertSame(1, DB::table('payment_notifications')->where('signature_valid', false)->count());
        // Notifications keep a fingerprint only: no phone number, name or raw body is stored.
        $this->assertFalse(DB::getSchemaBuilder()->hasColumn('payment_notifications', 'fields'));
    }

    public function test_mismatched_test_mode_or_reused_payments_are_put_under_review_not_confirmed(): void
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-LOW', $attempt['id'], 2400);
        $this->api('POST', 'orders/'.$order['id'].'/payments/'.$attempt['id'].'/check', [
            'transaction_id' => 'TX-LOW',
        ])
            ->assertJsonPath('data.status', 'payment_review')
            ->assertJsonPath('data.payment.status', 'to_verify');
        $this->assertSame('amount_mismatch', DB::table('payment_incidents')->value('kind'));
        // No new charge is requested while a payment is being clarified.
        $this->api('POST', 'orders/'.$order['id'].'/payments')->assertUnprocessable();

        $other = $this->order($this->checkoutAgain($c))->assertCreated()->json('data');
        $test = $this->pay($other);
        $this->gateway->paid('TX-TEST', $test['id'], 2500, testmode: true);
        $this->api('POST', 'orders/'.$other['id'].'/payments/'.$test['id'].'/check', [
            'transaction_id' => 'TX-TEST',
        ])->assertJsonPath('data.status', 'payment_review');
        $this->assertTrue(DB::table('payment_incidents')->where('kind', 'testmode_payment')->exists());

        // A transaction that carries another reference is never attached to this order.
        $this->gateway->paid('TX-ELSE', $this->fixtureId(), 2500);
        $this->api('POST', 'orders/'.$other['id'].'/payments/'.$test['id'].'/check', [
            'transaction_id' => 'TX-ELSE',
        ]);
        $this->assertTrue(DB::table('payment_incidents')->where('kind', 'reference_mismatch')->exists());
        $this->assertSame(0, DB::table('orders')->where('status', 'paid')->count());
    }

    /** A second quote on the same, unchanged cart, with its own slot hold. */
    private function checkoutAgain(array $c): array
    {
        $quote = $this->quote($this->getJson('/api/v1/cart')->json('data'), $this->address('Akwa'))->json('data');

        return $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $c['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
    }

    public function test_a_genuine_second_payment_opens_an_overpayment_incident(): void
    {
        config(['kora.payment_attempt_reuse_minutes' => 1]);
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $first = $this->pay($order);
        $this->travel(2)->minutes();
        $second = $this->pay($order);
        $this->assertNotSame($first['id'], $second['id']);

        $this->gateway->paid('TX-A', $first['id'], 2500);
        $this->gateway->paid('TX-B', $second['id'], 2500);
        $this->notify([
            'transaction_id' => 'TX-A',
            'payment_ref' => $first['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->notify([
            'transaction_id' => 'TX-B',
            'payment_ref' => $second['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();

        $this->assertSame('paid', DB::table('orders')->where('id', $order['id'])->value('status'));
        $this->assertSame(2, DB::table('payment_attempts')->where('status', 'confirmed')->count());
        $this->assertSame(['overpayment'], DB::table('payment_incidents')->pluck('kind')->all());
    }

    public function test_an_unavailable_provider_charges_nothing_and_leaves_the_order_payable(): void
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $this->gateway->configured = false;
        $this->api('POST', 'orders/'.$order['id'].'/payments')->assertUnprocessable();
        $this->gateway->configured = true;
        $this->gateway->refuse = true;
        $this->api('POST', 'orders/'.$order['id'].'/payments')->assertUnprocessable();
        $this->assertSame(
            ['provider_not_configured', 'provider_refused'],
            DB::table('payment_attempts')
                ->orderBy('created_at')
                ->orderBy('failure_reason')
                ->pluck('failure_reason')
                ->all(),
        );
        $this->assertCount(0, $this->gateway->started);
        $this->gateway->refuse = false;
        $this->assertSame('pending', $this->pay($order)['status']);
    }

    public function test_expiry_gives_stock_and_slot_back_and_a_late_payment_is_still_handled(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 5);
        $zone = $this->zone('SOUTH', ['Akwa']);
        $this->point($zone, ['warehouse_id' => $location['warehouse_id']]);
        $this->profile($ctx, 1000);
        $this->grid([[$zone, 1000]]);
        $this->inventoryPolicy();
        $rule = $this->slotRule();

        $client = $this->client();
        $quote = $this->quote($this->fill([[$ctx['offer'], 2]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $rule['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $order = $this->order($quote, '3000')->assertCreated()->json('data');
        $this->assertSame(2, (int) DB::table('inventory_balances')->sum('reserved'));
        $attempt = $this->pay($order);

        $this->travel(11)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('expired', DB::table('orders')->where('id', $order['id'])->value('status'));
        $this->assertSame(0, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertSame('released', DB::table('delivery_quotes')->where('id', $quote['id'])->value('slot_status'));

        $this->gateway->paid('TX-LATE', $attempt['id'], 3000);
        $this->notify([
            'transaction_id' => 'TX-LATE',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->assertSame('paid', DB::table('orders')->where('id', $order['id'])->value('status'));
        $this->assertTrue(DB::table('order_events')->where('action', 'paid_after_expiry')->exists());
        $this->assertSame('reserved', DB::table('order_lines')->value('supply_status'));
        $this->assertSame(2, (int) DB::table('inventory_balances')->sum('reserved'));
        // A verified payment retains its real stock; the unpaid cart timeout must not release it again.
        $this->travel(11)->minutes();
        $this->artisan('inventory:expire')->assertSuccessful();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame(2, (int) DB::table('inventory_balances')->sum('reserved'));
        $paidLine = DB::table('order_lines')->where('order_id', $order['id'])->first();
        $this->assertDatabaseHas('inventory_reservations', [
            'id' => $paidLine->reservation_id,
            'order_line_id' => $paidLine->id,
            'status' => 'active',
        ]);
        $this->assertSame('confirmed', DB::table('delivery_quotes')->where('id', $quote['id'])->value('slot_status'));
        $this->client($client);
        $this->getJson('/api/v1/orders/'.$order['id'])->assertJsonPath('data.status', 'paid');
    }

    public function test_orders_are_private_and_internal_detail_needs_a_permission(): void
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $this->client(User::factory()->create(['roles' => ['client']]));
        $this->getJson('/api/v1/orders/'.$order['id'])->assertNotFound();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->op('GET', 'orders')->assertForbidden();

        $this->operationUser('support');
        $detail = $this->op('GET', 'orders/'.$order['id'])
            ->assertOk()
            ->json('data');
        $this->assertSame(1000, $detail['internal_lines'][0]['internal']['reference_price']);
        $this->op('POST', 'payments/'.$this->fixtureId().'/check')->assertForbidden();
    }

    public function test_a_wrong_amount_is_closed_only_by_a_verified_refund_and_the_order_becomes_payable_again(): void
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-LOW', $attempt['id'], 2400);
        $this->api('POST', 'orders/'.$order['id'].'/payments/'.$attempt['id'].'/check', [
            'transaction_id' => 'TX-LOW',
        ]);
        $incident = DB::table('payment_incidents')->first();

        $this->operationUser('accountant');
        // Money was received: a note cannot close the incident.
        $this->op('POST', 'payment-incidents/'.$incident->id.'/resolve', [
            'outcome' => 'dismissed',
            'note' => 'Test',
        ])->assertUnprocessable();
        // Only the 2 400 really received can be returned, not the 2 500 that was asked.
        $this->refund($order, '2401', $incident->id)->assertUnprocessable();
        $refund = $this->refund($order, '2400', $incident->id)->assertCreated()->json('data');
        $this->refund($order, '1')->assertUnprocessable();
        $this->assertSame('requested', $refund['status']);
        $this->assertSame('open', DB::table('payment_incidents')->value('status'));

        $this->transfer($refund, '2300', 'MM-TEST-1')->assertUnprocessable();
        $this->transfer($refund, '2400', 'MM-TEST-1')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->assertSame(
            ['resolved', 'refunded'],
            [DB::table('payment_incidents')->value('status'), DB::table('payment_incidents')->value('resolution')],
        );
        $this->assertSame('reversed', DB::table('payment_attempts')->where('id', $attempt['id'])->value('status'));

        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.payable', true)
            ->assertJsonPath('data.refunds.0.status', 'paid')
            ->assertJsonPath('data.refunds.0.amount', '2400');
        $this->assertSame('pending', $this->pay($order)['status']);
    }

    public function test_refunds_never_exceed_the_money_collected_and_a_transfer_proves_one_refund_only(): void
    {
        config(['kora.payment_attempt_reuse_minutes' => 1]);
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $first = $this->pay($order);
        $this->travel(2)->minutes();
        $second = $this->pay($order);
        $this->gateway->paid('TX-A', $first['id'], 2500);
        $this->gateway->paid('TX-B', $second['id'], 2500);
        $this->notify(['transaction_id' => 'TX-A', 'payment_ref' => $first['id'], 'sign' => 'valid-test-signature']);
        $this->notify(['transaction_id' => 'TX-B', 'payment_ref' => $second['id'], 'sign' => 'valid-test-signature']);
        $overpayment = DB::table('payment_incidents')->where('kind', 'overpayment')->value('id');

        $this->operationUser('accountant');
        $refund = $this->refund($order, '2500', $overpayment)->assertCreated()->json('data');
        $other = $this->refund($order, '2500')->assertCreated()->json('data');
        $this->refund($order, '1')->assertUnprocessable();
        $this->op('POST', 'refunds/'.$other['id'].'/cancel', ['reason' => 'Test cancellation'])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->refund($order, '1')->assertCreated();

        $this->transfer($refund, '2500', 'MM-TEST-2')->assertOk();
        $this->assertSame('resolved', DB::table('payment_incidents')->where('id', $overpayment)->value('status'));
        $this->assertSame('paid', DB::table('orders')->value('status'));
        $last = DB::table('customer_refunds')->where('status', 'requested')->first();
        // The same mobile money reference cannot prove a second refund.
        $this->transfer((array) $last, '1', 'MM-TEST-2')->assertConflict();
    }

    public function test_an_incomplete_provider_answer_needs_a_documented_human_confirmation(): void
    {
        $c = $this->checkout();
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->checks['TX-PART'] = [
            'state' => 'confirmed',
            'provider_status' => '1',
            'amount' => null,
            'currency' => null,
            'reference' => $attempt['id'],
            'testmode' => false,
        ];
        $this->api('POST', 'orders/'.$order['id'].'/payments/'.$attempt['id'].'/check', [
            'transaction_id' => 'TX-PART',
        ])->assertJsonPath('data.status', 'payment_review');
        $incident = DB::table('payment_incidents')->where('kind', 'verification_incomplete')->value('id');

        $this->operationUser('support');
        $this->op('POST', 'payment-incidents/'.$incident.'/resolve', [
            'outcome' => 'confirmed_after_review',
            'note' => 'Test',
        ])->assertForbidden();

        $this->operationUser('accountant');
        $this->op('POST', 'payment-incidents/'.$incident.'/resolve', [
            'outcome' => 'confirmed_after_review',
            'note' => 'Checked in the provider dashboard (test)',
        ])->assertUnprocessable();
        $this->op('POST', 'payment-incidents/'.$incident.'/resolve', [
            'outcome' => 'confirmed_after_review',
            'note' => 'Checked in the provider dashboard (test)',
            'evidence_id' => $this->refundEvidence(),
        ])
            ->assertOk()
            ->assertJsonPath('data.resolution', 'confirmed_after_review');
        $this->assertSame('paid', DB::table('orders')->value('status'));
        $this->assertSame(2500, (int) DB::table('payment_attempts')->value('received_amount'));
    }
}
