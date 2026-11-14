<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class OrderSupplyTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function paid(int $quantity = 1, bool $promotion = false): array
    {
        $c = $this->checkout(false);
        if ($quantity > 1) {
            $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
            $line = collect($cart['lines'])->firstWhere('selected', true);
            $this->api('PUT', 'cart/lines/'.$line['id'], [
                'version' => $cart['version'],
                'quantity' => $quantity,
                'selected' => true,
            ])->assertOk();
        }
        if ($promotion) {
            $this->operationUser();
            $offer = $this->op('POST', 'promotions/delivery-offers', [
                'name' => ['fr' => 'Livraison offerte (test)', 'en' => 'Delivery offered (test)'],
                'starts_at' => now()->subMinute()->toIso8601String(),
                'ends_at' => null,
                'zone_ids' => [$c['n']['south']['id']],
                'min_unit_price' => '1000',
                'uses_per_customer' => 3,
                'coverage' => 'full',
                'budget' => '1500',
                'funded_by' => 'kora',
            ])
                ->assertCreated()
                ->json('data');
            $this->op('POST', 'promotions/delivery-offers/'.$offer['id'].'/activate', [
                'version' => $offer['version'],
            ])->assertOk();
            $this->client($c['client']);
        }
        $quote = $this->quote($this->getJson('/api/v1/cart')->json('data'), $this->address('Akwa'))
            ->assertCreated()
            ->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $c['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
        $total = (string) ($quantity * 1000 + (int) $quote['payable_delivery']);
        $order = $this->order($quote, $total)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-SUPPLY', $attempt['id'], (int) $total);
        $this->notify([
            'transaction_id' => 'TX-SUPPLY',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->client($c['client']);
        $c['order'] = $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()
            ->json('data');
        $c['line'] = $c['order']['lines'][0]['id'];

        return $c;
    }

    private function requestSupply(array $c, ?string $key = null)
    {
        return $this->op(
            'POST',
            'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/supplier-reservations',
            [
                'version' => (int) DB::table('order_lines')->where('id', $c['line'])->value('supply_version'),
                'expires_at' => now()->addHour()->toIso8601String(),
            ],
            $key,
        );
    }

    private function report(array $c, int $missing = 1): array
    {
        return $this->op('POST', 'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $c['line'])->value('supply_version'),
            'missing_quantity' => $missing,
            'message' => ['fr' => 'Article manquant (test)', 'en' => 'Missing item (test)'],
            'reference' => 'TEST SUPPLIER CALL',
        ])
            ->assertOk()
            ->json('data');
    }

    private function choice(array $c, array $case, string $choice, ?string $amount = null, ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? $this->fixtureId())->postJson(
            '/api/v1/orders/'.$c['order']['id'].'/supply-cases/'.$case['id'].'/choice',
            [
                'version' => $case['version'],
                'choice' => $choice,
                'accepted_refund_amount' => $amount,
            ],
        );
    }

    public function test_paid_order_requests_are_linked_and_only_a_supplier_confirmation_marks_them_confirmed(): void
    {
        $c = $this->paid();
        $this->assertFalse($c['order']['lines'][0]['supplier_confirmation_valid']);
        $this->operationUser('procurement');
        $reservation = $this->requestSupply($c)
            ->assertCreated()
            ->assertJsonPath('data.supplier_confirmed', false)
            ->json('data');
        $this->assertDatabaseHas('supplier_reservations', [
            'id' => $reservation['id'],
            'purpose_id' => $c['line'],
            'order_line_id' => $c['line'],
        ]);
        $this->requestSupply($c)->assertUnprocessable();
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST EXTERNAL CONFIRMATION',
        ])
            ->assertOk()
            ->assertJsonPath('data.supplier_confirmed', true)
            ->assertJsonPath('data.confirmed_quantity', 1);
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/request-release', [
            'reference' => 'TEST RELEASE WITHOUT CHOICE',
        ])->assertUnprocessable();
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.supply_status', 'supplier_confirmed')
            ->assertJsonPath('data.lines.0.supplier_confirmation_valid', true);
        $this->assertDatabaseCount('purchase_orders', 0);
    }

    public function test_partial_confirmation_opens_one_choice_and_the_customer_can_wait_for_a_new_confirmation(): void
    {
        $c = $this->paid(2);
        $this->operationUser();
        $reservation = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST PARTIAL',
            'confirmed_quantity' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'partly_confirmed');
        $this->client($c['client']);
        $view = $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertOk()
            ->json('data');
        $case = $view['supply_cases'][0];
        $this->assertSame(
            [1, '1000', '0'],
            [$case['missing_quantity'], $case['refund_amount'], $case['delivery_refund']],
        );
        $this->choice($c, $case, 'wait')->assertOk()->assertJsonPath('data.status', 'waiting');
        $this->assertDatabaseCount('customer_refunds', 0);
        $this->travel(61)->minutes();
        $this->artisan('orders:expire-supply')->assertSuccessful();
        $this->client($c['client']);
        $case = $this->getJson('/api/v1/orders/'.$c['order']['id'])->json('data.supply_cases.0');
        $this->choice($c, $case, 'wait')->assertOk();
        $this->operationUser();
        $next = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('POST', 'supplier-reservations/'.$next['id'].'/confirm', [
            'reference' => 'TEST NOW AVAILABLE',
        ])->assertOk();
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertJsonPath('data.supply_cases.0.status', 'resolved')
            ->assertJsonPath('data.lines.0.supplier_confirmation_valid', true);
    }

    public function test_partial_refund_keeps_the_sale_and_other_units_and_needs_a_verified_transfer(): void
    {
        $c = $this->paid(2);
        $this->operationUser();
        $invoice = $this->op('POST', 'orders/'.$c['order']['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'customer-documents/'.$invoice['id'].'/issue', [
            'version' => $invoice['version'],
        ])->assertOk();
        $invoiceSnapshot = DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot');
        $reservation = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST PARTIAL',
            'confirmed_quantity' => 1,
        ])->assertOk();
        $this->client($c['client']);
        $case = $this->getJson('/api/v1/orders/'.$c['order']['id'])->json('data.supply_cases.0');
        $key = $this->fixtureId();
        $result = $this->choice($c, $case, 'refund_missing', '1000', $key)->assertOk()->json('data');
        $this->choice($c, $case, 'refund_missing', '1000', $key)
            ->assertOk()
            ->assertJsonPath('data.refund_id', $result['refund_id']);
        $this->assertDatabaseCount('customer_refunds', 1);
        $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertJsonPath('data.refunds.0.status', 'requested')
            ->assertJsonPath('data.lines.0.cancelled_quantity', 1)
            ->assertJsonPath('data.lines.0.supplier_confirmation_valid', true);
        $this->operationUser('accountant');
        $this->op('POST', 'refunds/'.$result['refund_id'].'/cancel', [
            'reason' => 'TEST CANNOT ERASE CUSTOMER CHOICE',
        ])->assertUnprocessable();
        $this->transfer(['id' => $result['refund_id']], '1000', 'TEST-SUPPLY-REFUND')->assertOk();
        $this->op('POST', 'refunds/'.$result['refund_id'].'/credit-note')
            ->assertCreated()
            ->assertJsonPath('data.total', '1000');
        $this->assertSame(
            $invoiceSnapshot,
            DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot'),
        );
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertJsonPath('data.supply_cases.0.status', 'refunded')
            ->assertJsonPath('data.lines.0.supply_status', 'partially_cancelled')
            ->assertJsonPath('data.total', $c['order']['total'])
            ->assertJsonPath('data.closed_at', null);
        $this->assertDatabaseHas('order_lines', ['id' => $c['line'], 'quantity' => 2, 'line_total' => 2000]);
    }

    public function test_refunding_all_unavailable_units_returns_net_delivery_and_releases_the_slot(): void
    {
        $c = $this->paid();
        $this->operationUser();
        $case = $this->report($c);
        $this->assertSame(['2500', '1500'], [$case['refund_amount'], $case['delivery_refund']]);
        $this->client($c['client']);
        $this->choice($c, $case, 'refund_missing', '1000')->assertUnprocessable();
        $result = $this->choice($c, $case, 'refund_missing', '2500')->assertOk()->json('data');
        $this->operationUser('accountant');
        $this->transfer(['id' => $result['refund_id']], '2500', 'TEST-FULL-SUPPLY-REFUND')->assertOk();
        $quoteId = DB::table('orders')->where('id', $c['order']['id'])->value('quote_id');
        $this->assertDatabaseHas('delivery_quotes', ['id' => $quoteId, 'slot_status' => 'released']);
        $this->client($c['client']);
        $view = $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertOk()
            ->json('data');
        $this->assertNotNull($view['closed_at']);
        $this->assertSame(
            ['paid', 'cancelled', '2500'],
            [$view['status'], $view['lines'][0]['supply_status'], $view['total']],
        );
    }

    public function test_cases_are_owned_versioned_and_private_and_generic_routes_cannot_bypass_the_link(): void
    {
        $c = $this->paid();
        $this->operationUser();
        $case = $this->report($c);
        $this->op('POST', 'supplier-reservations', [
            'offer_id' => $c['n']['second']['offer'],
            'purpose_id' => $c['line'],
            'quantity' => 1,
            'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertUnprocessable();
        $this->client();
        $this->choice($c, $case, 'wait')->assertNotFound();
        $this->client($c['client']);
        $this->choice($c, [...$case, 'version' => $case['version'] + 1], 'wait')->assertConflict();
        $this->getJson('/api/v1/orders/'.$c['order']['id'].'?locale=en')
            ->assertOk()
            ->assertJsonPath('data.supply_cases.0.message', 'Missing item (test)');
        $view = $this->getJson('/api/v1/orders/'.$c['order']['id'])->json('data');
        $this->assertStringNotContainsString('TEST SUPPLIER CALL', json_encode($view));
        $this->assertStringNotContainsString('internal_snapshot', json_encode($view));
        $this->requestSupply($c)->assertForbidden();
    }

    public function test_expiry_is_visible_immediately_and_processing_it_twice_does_not_duplicate_the_case(): void
    {
        $c = $this->paid();
        $this->operationUser();
        $reservation = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST EXPIRES',
        ])->assertOk();
        $this->travel(61)->minutes();
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertJsonPath('data.lines.0.supplier_confirmation_valid', false)
            ->assertJsonPath('data.lines.0.supply_status', 'confirmation_expired');
        $this->artisan('orders:expire-supply')->assertSuccessful();
        $this->artisan('orders:expire-supply')->assertSuccessful();
        $this->assertDatabaseCount('order_supply_cases', 1);
        $this->assertSame(1, DB::table('order_events')->where('action', 'supplier_confirmation_expired')->count());
        $this->operationUser();
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST TOO LATE',
        ])->assertUnprocessable();
    }

    public function test_a_fully_refunded_cancelled_order_restores_its_consumed_delivery_advantage_once(): void
    {
        $c = $this->paid(1, true);
        $this->assertDatabaseHas('delivery_offer_usages', ['order_id' => $c['order']['id'], 'status' => 'consumed']);
        $this->operationUser();
        $case = $this->report($c);
        $this->assertSame(['1000', '0'], [$case['refund_amount'], $case['delivery_refund']]);
        $this->client($c['client']);
        $result = $this->choice($c, $case, 'refund_missing', '1000')->assertOk()->json('data');
        $this->operationUser('accountant');
        $this->transfer(['id' => $result['refund_id']], '1000', 'TEST-PROMO-SUPPLY-REFUND')->assertOk();
        $this->assertDatabaseHas('delivery_offer_usages', ['order_id' => $c['order']['id'], 'status' => 'released']);
        $this->assertSame(
            1,
            DB::table('order_events')->where('action', 'supply_order_cancelled_and_refunded')->count(),
        );
        $this->operationUser();
        $this->op('GET', 'promotions/delivery-offers')->assertOk()->assertJsonPath('data.0.available', '1500');
    }

    public function test_supplier_quantity_planning_counts_only_units_actually_confirmed_in_a_partial_answer(): void
    {
        $c = $this->paid(2);
        $this->operationUser();
        DB::table('supplier_availability')
            ->where('offer_id', $c['n']['second']['offer'])
            ->update(['quantity' => 10]);
        $reservation = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('GET', 'inventory/offers/'.$c['n']['second']['offer'].'/plan?quantity=1')
            ->assertOk()
            ->assertJsonPath('data.supplier_quantity_reported', 8);
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST ONE UNIT',
            'confirmed_quantity' => 1,
        ])->assertOk();
        $this->op('GET', 'inventory/offers/'.$c['n']['second']['offer'].'/plan?quantity=1')
            ->assertOk()
            ->assertJsonPath('data.supplier_quantity_reported', 9);
    }

    public function test_supplier_processing_refuses_unpaid_orders_and_wrong_lines(): void
    {
        $c = $this->checkout();
        $c['order'] = $this->order($c['quote'])->assertCreated()->json('data');
        $c['line'] = $c['order']['lines'][0]['id'];
        $this->operationUser();
        $this->requestSupply($c)->assertUnprocessable();
        $c['line'] = $this->fixtureId();
        $this->op('POST', 'orders/'.$c['order']['id'].'/lines/'.$c['line'].'/supplier-reservations', [
            'version' => 1,
            'expires_at' => now()->addHour()->toIso8601String(),
        ])->assertNotFound();
        $this->assertDatabaseCount('supplier_reservations', 0);
    }

    public function test_successive_shortages_preserve_prior_refunds_and_refund_delivery_only_once(): void
    {
        $c = $this->paid(2);
        $this->operationUser();
        $reservation = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'TEST FIRST SHORTAGE',
            'confirmed_quantity' => 1,
        ])->assertOk();
        $this->client($c['client']);
        $case = $this->getJson('/api/v1/orders/'.$c['order']['id'])->json('data.supply_cases.0');
        $first = $this->choice($c, $case, 'refund_missing', '1000')->assertOk()->json('data');
        $this->operationUser('accountant');
        $this->transfer(['id' => $first['refund_id']], '1000', 'TEST-FIRST-SHORTAGE-REFUND')->assertOk();
        $this->travel(61)->minutes();
        $this->artisan('orders:expire-supply')->assertSuccessful();
        $this->client($c['client']);
        $view = $this->getJson('/api/v1/orders/'.$c['order']['id'])
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $view['supply_cases']);
        $this->assertSame('1000', $view['supply_cases'][0]['refund_amount']);
        $last = $view['supply_cases'][1];
        $this->assertSame(['3500', '2500'], [$last['refund_amount'], $last['delivery_refund']]);
        $second = $this->choice($c, $last, 'refund_missing', '3500')->assertOk()->json('data');
        $this->operationUser('accountant');
        $this->transfer(['id' => $second['refund_id']], '3500', 'TEST-SECOND-SHORTAGE-REFUND')->assertOk();
        $this->assertSame(
            4500,
            (int) DB::table('customer_refunds')->where('order_id', $c['order']['id'])->sum('amount'),
        );
        $this->assertNotNull(DB::table('orders')->where('id', $c['order']['id'])->value('closed_at'));
        $this->assertSame(
            1,
            DB::table('order_events')->where('action', 'supply_order_cancelled_and_refunded')->count(),
        );
    }

    public function test_a_customer_refund_supersedes_an_unconfirmed_request_for_the_old_quantity(): void
    {
        $c = $this->paid(2);
        $this->operationUser();
        $case = $this->report($c, 1);
        $old = $this->requestSupply($c)->assertCreated()->json('data');
        $this->client($c['client']);
        $this->choice($c, $case, 'refund_missing', '1000')->assertOk();
        $this->assertDatabaseHas('supplier_reservations', ['id' => $old['id'], 'status' => 'superseded']);
        $this->operationUser();
        $next = $this->requestSupply($c)->assertCreated()->json('data');
        $this->op('POST', 'supplier-reservations/'.$old['id'].'/confirm', [
            'reference' => 'TEST STALE ANSWER',
        ])->assertConflict();
        $this->op('POST', 'supplier-reservations/'.$next['id'].'/confirm', ['reference' => 'TEST REMAINING UNIT'])
            ->assertOk()
            ->assertJsonPath('data.confirmed_quantity', 1);
        $this->assertDatabaseHas('order_lines', ['id' => $c['line'], 'cancelled_quantity' => 1]);
    }
}
