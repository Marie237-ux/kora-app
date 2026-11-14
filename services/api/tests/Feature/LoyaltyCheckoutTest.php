<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\LoyaltyCheckoutFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class LoyaltyCheckoutTest extends TestCase
{
    use DeliveryFixtures, LoyaltyCheckoutFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function card()
    {
        return $this->getJson('/api/v1/account/loyalty')->assertOk();
    }

    public function test_a_server_quote_reserves_nothing_and_only_the_exact_accepted_total_reserves_the_points(): void
    {
        $this->client();
        $this->getJson('/api/v1/account/loyalty/redemption-policy')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.rules', null);
        $c = $this->loyaltyCheckout(1);
        $q = $this->pointsQuote($c)
            ->assertCreated()
            ->assertJsonPath('data.discount', '500')
            ->assertJsonPath('data.total', '2000')
            ->assertJsonPath('data.points_reserved', false)
            ->json('data');
        $this->card()
            ->assertJsonPath('data.card.available_points', '100')
            ->assertJsonPath('data.card.reserved_points', '0');
        $this->assertDatabaseCount('loyalty_redemptions', 0);
        $this->pointsOrder($c, $q, ['accepted_total' => '1999'])->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $order = $this->pointsOrder($c, $q)
            ->assertCreated()
            ->assertJsonPath('data.items_total', '500')
            ->assertJsonPath('data.lines.0.line_total', '500')
            ->assertJsonPath('data.loyalty.status', 'held')
            ->json('data');
        $this->card()
            ->assertJsonPath('data.card.available_points', '0')
            ->assertJsonPath('data.card.reserved_points', '100');
        foreach (['loss_reason', 'budget_xaf', 'purchase_unit'] as $secret) {
            $this->assertStringNotContainsString($secret, json_encode($order));
            $this->assertStringNotContainsString($secret, json_encode($q));
        }
        $otherClient = $this->client();
        $this->api('POST', 'account/loyalty/card')->assertCreated();
        $other = $this->loyaltyCart([...$c, 'client' => $otherClient], 1);
        $this->pointsOrder($other, $q)->assertNotFound();
        $this->assertDatabaseCount('orders', 1);
    }

    public function test_verified_payment_consumes_once_and_invoice_totals_include_the_frozen_line_discount(): void
    {
        $c = $this->loyaltyCheckout(1);
        $q = $this->pointsQuote($c)->assertCreated()->json('data');
        $order = $this->pointsOrder($c, $q)->assertCreated()->json('data');
        $attempt = $this->payPointsOrder($order);
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.available_points', '0')
            ->assertJsonPath('data.card.reserved_points', '0');
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.loyalty.consumed_points', '100');
        $this->notify([
            'transaction_id' => 'TEST-REDEEM-'.$order['id'],
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->assertSame(1, DB::table('loyalty_movements')->where('kind', 'used')->count());
        $this->operationUser();
        $invoice = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'customer-documents/'.$invoice['id'].'/issue', [
            'version' => $invoice['version'],
        ])->assertOk();
        $snapshot = json_decode(DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot'), true);
        $this->assertSame('500', $snapshot['lines'][0]['line_total']);
        $this->assertSame('500', $snapshot['lines'][0]['loyalty_discount']);
        $this->assertSame('2000', $snapshot['totals']['total']);
        foreach (['fr', 'en'] as $locale) {
            $this->get('/api/v1/operations/customer-documents/'.$invoice['id'].'/pdf?locale='.$locale)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
        // Trusted fulfilment fixture: points accrue on 500 net items, not 1 000 before discount or 2 000 including delivery.
        DB::table('orders')
            ->where('id', $order['id'])
            ->update(['delivered_at' => now()]);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->client($c['client']);
        $this->getJson('/api/v1/account/loyalty/grants')->assertOk();
        $earned = DB::table('loyalty_grants')->where('order_id', $order['id'])->first();
        $this->assertSame(500, (int) $earned->eligible_amount);
        $this->assertSame(0, (int) $earned->points);
    }

    public function test_expiration_restores_the_original_points_once_and_releases_the_monetary_budget(): void
    {
        $c = $this->loyaltyCheckout(1, 100, ['budget_xaf' => '500']);
        $q = $this->pointsQuote($c)->assertCreated()->json('data');
        $this->pointsOrder($c, $q)->assertCreated();
        $this->travel(2)->hours();
        $this->card()
            ->assertJsonPath('data.card.available_points', '100')
            ->assertJsonPath('data.card.reserved_points', '0');
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()->assertJsonPath('data.card.available_points', '100');
        $this->assertSame(1, DB::table('loyalty_movements')->where('kind', 'released')->count());
        $this->operationUser();
        $this->op('GET', 'loyalty/redemption-policy')
            ->assertOk()
            ->assertJsonPath('data.reserved_budget', '0')
            ->assertJsonPath('data.used_budget', '0');
    }

    public function test_partial_returns_restore_the_exact_spent_points_and_do_not_refund_the_discount_as_cash(): void
    {
        $c = $this->loyaltyCheckout(3);
        $q = $this->pointsQuote($c)->assertCreated()->json('data');
        $order = $this->pointsOrder($c, $q)->assertCreated()->assertJsonPath('data.items_total', '2500')->json('data');
        $this->payPointsOrder($order);
        $this->operationUser();
        $first = $this->refund($order, '834')->assertCreated()->json('data');
        $this->transfer($first, '834', 'TEST-REDEEM-RETURN-1')->assertOk();
        $body = [
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => ['fr' => 'Retour de test.', 'en' => 'Test return.'],
        ];
        $this->op('POST', 'loyalty/refunds/'.$first['id'].'/lines', $body)
            ->assertOk()
            ->assertJsonPath('data.items_amount', '834')
            ->assertJsonPath('data.restored_points', '33');
        $this->op('POST', 'loyalty/refunds/'.$first['id'].'/lines', $body)->assertConflict();
        $second = $this->refund($order, '1666')->assertCreated()->json('data');
        $this->transfer($second, '1666', 'TEST-REDEEM-RETURN-2')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$second['id'].'/lines', [
            ...$body,
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('data.items_amount', '1666')
            ->assertJsonPath('data.restored_points', '67');
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.available_points', '100');
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.loyalty.restored_points', '100');
        $this->operationUser();
        $this->op('GET', 'loyalty/redemption-policy')->assertOk()->assertJsonPath('data.used_budget', '500');
    }

    public function test_changed_policy_and_expired_quotation_cannot_silently_change_the_accepted_price(): void
    {
        $c = $this->loyaltyCheckout(1);
        $q = $this->pointsQuote($c)->assertCreated()->json('data');
        $this->operationUser();
        $this->saveRedemption([], 2)->assertOk();
        $this->client($c['client']);
        $this->pointsOrder($c, $q)->assertUnprocessable();
        $this->assertDatabaseCount('orders', 0);
        $new = $this->pointsQuote($c)->assertCreated()->json('data');
        $this->travel(11)->minutes();
        $this->pointsOrder($c, $new)->assertUnprocessable();
        $this->assertDatabaseCount('loyalty_redemptions', 0);
    }

    public function test_caps_minimum_payment_and_loss_approval_are_enforced_by_the_server(): void
    {
        $c = $this->loyaltyCheckout(1, 100, ['allow_negative_margin' => false, 'loss_reason' => null]);
        $this->pointsQuote($c)->assertUnprocessable();
        $this->operationUser();
        $this->saveRedemption(['max_discount_basis_points' => 1000], 2)->assertOk();
        $this->client($c['client']);
        $this->pointsQuote($c)->assertUnprocessable();
        $this->operationUser();
        $this->saveRedemption(['minimum_payable_xaf' => '2500'], 3)->assertOk();
        $this->client($c['client']);
        $this->pointsQuote($c)->assertUnprocessable();
        $this->operationUser('support');
        $this->saveRedemption([], 4)->assertForbidden();
        $this->op('GET', 'loyalty/redemption-policy')->assertForbidden();
    }

    public function test_a_late_payment_cannot_consume_points_already_spent_on_another_paid_order(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30:00', 'Africa/Douala'));
        $c = $this->loyaltyCheckout(1);
        $q = $this->pointsQuote($c)->assertCreated()->json('data');
        $old = $this->pointsOrder($c, $q)->assertCreated()->json('data');
        $attempt = $this->pay($old);
        $this->travel(2)->hours();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $c2 = $this->loyaltyCart($c, 1);
        $q2 = $this->pointsQuote($c2)->assertCreated()->json('data');
        $other = $this->pointsOrder($c2, $q2)->assertCreated()->json('data');
        $this->payPointsOrder($other);
        $this->gateway->paid('TEST-REDEEM-LATE', $attempt['id'], (int) $old['total']);
        $this->notify([
            'transaction_id' => 'TEST-REDEEM-LATE',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$old['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.total', '2000')
            ->assertJsonPath('data.loyalty.status', 'review_required')
            ->assertJsonPath('data.loyalty.consumed_points', '0');
        $this->assertSame(100, (int) DB::table('loyalty_redemptions')->sum('consumed_points'));
        $this->assertDatabaseHas('payment_incidents', [
            'order_id' => $old['id'],
            'kind' => 'loyalty_points_unavailable_after_payment',
            'status' => 'open',
        ]);
        DB::table('orders')
            ->where('id', $old['id'])
            ->update(['delivered_at' => now()]);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->assertSame(0, DB::table('loyalty_grants')->where('order_id', $old['id'])->count());
        $incident = DB::table('payment_incidents')
            ->where('order_id', $old['id'])
            ->where('kind', 'loyalty_points_unavailable_after_payment')
            ->first();
        $this->operationUser();
        $this->op('POST', 'payment-incidents/'.$incident->id.'/resolve', [
            'outcome' => 'dismissed',
            'note' => 'KORA accepte la remise promise dans cette simulation, sans débiter les points déjà dépensés.',
        ])->assertOk();
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$old['id'])
            ->assertOk()
            ->assertJsonPath('data.loyalty.status', 'covered')
            ->assertJsonPath('data.loyalty.consumed_points', '0')
            ->assertJsonPath('data.total', '2000');
    }

    public function test_a_late_verified_payment_keeps_its_price_and_reports_a_monetary_budget_overrun(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-04 23:30:00', 'Africa/Douala'));
        $c = $this->loyaltyCheckout(1, 200, ['budget_xaf' => '500']);
        $old = $this->pointsOrder($c, $this->pointsQuote($c)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $attempt = $this->pay($old);
        $this->travel(2)->hours();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $c2 = $this->loyaltyCart($c, 1);
        $other = $this->pointsOrder($c2, $this->pointsQuote($c2)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $this->payPointsOrder($other);
        $this->gateway->paid('TEST-REDEEM-LATE-BUDGET', $attempt['id'], 2000);
        $this->notify([
            'transaction_id' => 'TEST-REDEEM-LATE-BUDGET',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->assertSame(200, (int) DB::table('loyalty_redemptions')->sum('consumed_points'));
        $this->assertDatabaseHas('payment_incidents', [
            'order_id' => $old['id'],
            'kind' => 'loyalty_budget_exceeded_after_payment',
        ]);
        $this->client($c['client']);
        $this->getJson('/api/v1/orders/'.$old['id'])
            ->assertOk()
            ->assertJsonPath('data.total', '2000');
        $this->operationUser();
        $this->op('GET', 'loyalty/redemption-policy')->assertOk()->assertJsonPath('data.used_budget', '1000');
        $this->saveRedemption(['budget_xaf' => '999'], 2)->assertUnprocessable();
        $this->saveRedemption(['budget_xaf' => '1000'], 2)->assertOk();
    }

    public function test_a_hold_protects_original_point_validity_until_payment_but_release_does_not_resurrect_expired_points(): void
    {
        $c = $this->loyaltyCheckout(1);
        // An existing lot is approaching expiry; the programme still grants whole-day validity to new lots.
        DB::table('loyalty_grants')
            ->where('account_id', $c['card']['id'])
            ->update(['expires_at' => now()->addMinute()]);
        $order = $this->pointsOrder($c, $this->pointsQuote($c)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $this->travel(2)->minutes();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->payPointsOrder($order);
        $this->assertDatabaseHas('loyalty_redemptions', [
            'order_id' => $order['id'],
            'status' => 'consumed',
            'consumed_points' => 100,
        ]);
        $this->assertSame(0, DB::table('payment_incidents')->where('order_id', $order['id'])->count());
    }

    public function test_verified_supplier_refunds_pin_fractional_cash_and_restore_spent_points_automatically(): void
    {
        $c = $this->loyaltyCheckout(3);
        $order = $this->pointsOrder($c, $this->pointsQuote($c)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $this->payPointsOrder($order);
        $this->operationUser();
        $invoice = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'customer-documents/'.$invoice['id'].'/issue', [
            'version' => $invoice['version'],
        ])->assertOk();
        $line = $order['lines'][0]['id'];
        $case = $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $line)->value('supply_version'),
            'missing_quantity' => 1,
            'message' => ['fr' => 'Rupture de test.', 'en' => 'Test shortage.'],
            'reference' => 'TEST-REDEEM-SUPPLY',
        ])
            ->assertOk()
            ->json('data');
        $this->client($c['client']);
        $case = $this->api('POST', 'orders/'.$order['id'].'/supply-cases/'.$case['id'].'/choice', [
            'version' => $case['version'],
            'choice' => 'refund_missing',
            'accepted_refund_amount' => '834',
        ])
            ->assertOk()
            ->json('data');
        $this->assertDatabaseHas('loyalty_refund_lines', [
            'refund_id' => $case['refund_id'],
            'status' => 'pending',
            'amount' => 834,
        ]);
        $this->operationUser();
        $this->transfer(['id' => $case['refund_id']], '834', 'TEST-REDEEM-SUPPLY-REFUND')->assertOk();
        $this->assertDatabaseHas('loyalty_refund_lines', [
            'refund_id' => $case['refund_id'],
            'status' => 'paid',
            'restored_points' => 33,
        ]);
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.available_points', '33');
        $this->operationUser();
        $firstCredit = $this->op('POST', 'refunds/'.$case['refund_id'].'/credit-note')
            ->assertCreated()
            ->assertJsonPath('data.total', '834')
            ->json('data');
        $this->op('POST', 'customer-documents/'.$firstCredit['id'].'/issue', [
            'version' => $firstCredit['version'],
        ])->assertOk();
        $next = $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $line)->value('supply_version'),
            'missing_quantity' => 2,
            'message' => ['fr' => 'Rupture finale de test.', 'en' => 'Final test shortage.'],
            'reference' => 'TEST-REDEEM-SUPPLY-2',
        ])
            ->assertOk()
            ->json('data');
        $this->client($c['client']);
        $next = $this->api('POST', 'orders/'.$order['id'].'/supply-cases/'.$next['id'].'/choice', [
            'version' => $next['version'],
            'choice' => 'refund_missing',
            'accepted_refund_amount' => '4166',
        ])
            ->assertOk()
            ->json('data');
        $this->operationUser();
        $this->transfer(['id' => $next['refund_id']], '4166', 'TEST-REDEEM-SUPPLY-REFUND-2')->assertOk();
        $secondCredit = $this->op('POST', 'refunds/'.$next['refund_id'].'/credit-note')
            ->assertCreated()
            ->assertJsonPath('data.total', '4166')
            ->json('data');
        $this->op('POST', 'customer-documents/'.$secondCredit['id'].'/issue', [
            'version' => $secondCredit['version'],
        ])->assertOk();
        $this->assertSame(
            5000,
            (int) DB::table('customer_documents')->where('invoice_id', $invoice['id'])->sum('total'),
        );
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.available_points', '100');
    }

    public function test_cash_refund_is_not_blocked_when_genuinely_earned_points_were_spent_on_another_order(): void
    {
        $c = $this->loyaltyCheckout(3, 0, ['points_per_unit' => 30]);
        $source = $this->order($c['quote'], (string) (3000 + (int) $c['quote']['total']))
            ->assertCreated()
            ->json('data');
        $this->payPointsOrder($source);
        DB::table('orders')
            ->where('id', $source['id'])
            ->update(['delivered_at' => now()]);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.available_points', '30');
        $next = $this->loyaltyCart($c, 1);
        $redeemed = $this->pointsOrder($next, $this->pointsQuote($next, 30)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $this->payPointsOrder($redeemed);
        $this->operationUser();
        $refund = $this->refund($source, '1000')->assertCreated()->json('data');
        $this->transfer($refund, '1000', 'TEST-REDEEM-GENUINE-RETURN')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', [
            'lines' => [['order_line_id' => $source['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => [
                'fr' => 'Retour de test après utilisation des gains.',
                'en' => 'Test return after earned points were spent.',
            ],
        ])->assertOk();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.balance', '-10')->assertJsonPath('data.card.available_points', '0');
        $this->assertDatabaseHas('customer_refunds', ['id' => $refund['id'], 'status' => 'paid', 'amount' => 1000]);
    }

    public function test_delivery_promotion_threshold_uses_the_unit_price_after_loyalty_discount(): void
    {
        $c = $this->loyaltyCheckout(1, 100, ['combine_delivery_promotions' => true]);
        $this->operationUser();
        $campaign = $this->op('POST', 'promotions/delivery-offers', [
            'name' => ['fr' => 'Offre fictive', 'en' => 'Test offer'],
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => null,
            'zone_ids' => [$c['n']['south']['id']],
            'min_unit_price' => '1000',
            'uses_per_customer' => 3,
            'coverage' => 'full',
            'budget' => '10000',
            'funded_by' => 'kora',
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'promotions/delivery-offers/'.$campaign['id'].'/activate', [
            'version' => $campaign['version'],
        ])->assertOk();
        $this->client($c['client']);
        $q = $this->pointsQuote($c)
            ->assertCreated()
            ->assertJsonPath('data.delivery_total', '1500')
            ->assertJsonPath('data.delivery_promotion', null)
            ->json('data');
        $this->pointsOrder($c, $q)
            ->assertCreated()
            ->assertJsonPath('data.total', '2000')
            ->assertJsonPath('data.delivery.promotion', null);
    }

    public function test_an_expired_unpaid_hold_does_not_revive_the_expired_point_lot(): void
    {
        $c = $this->loyaltyCheckout(1);
        DB::table('loyalty_grants')
            ->where('account_id', $c['card']['id'])
            ->update(['expires_at' => now()->addMinute()]);
        $this->pointsOrder($c, $this->pointsQuote($c)->assertCreated()->json('data'))->assertCreated();
        $this->travel(2)->hours();
        $this->card()
            ->assertJsonPath('data.card.available_points', '0')
            ->assertJsonPath('data.card.reserved_points', '0');
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()->assertJsonPath('data.card.available_points', '0');
    }

    public function test_new_restoration_rules_do_not_rewrite_the_terms_of_a_paid_order(): void
    {
        $c = $this->loyaltyCheckout(1);
        $order = $this->pointsOrder($c, $this->pointsQuote($c)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $this->payPointsOrder($order);
        $this->operationUser();
        $this->saveRedemption(['restore_points_on_refund' => false], 2)->assertOk();
        $refund = $this->refund($order, '500')->assertCreated()->json('data');
        $this->transfer($refund, '500', 'TEST-REDEEM-FROZEN-RESTORE')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', [
            'lines' => [['order_line_id' => $order['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => [
                'fr' => 'Retour sous les conditions initiales.',
                'en' => 'Return under the original terms.',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.restored_points', '100');
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.available_points', '100');
        $this->getJson('/api/v1/account/loyalty/redemption-policy')
            ->assertOk()
            ->assertJsonPath('data.rules.restore_points_on_refund', false);
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.loyalty.rules.restore_points_on_refund', true);
    }

    public function test_initial_earning_waits_for_the_verified_refund_of_cancelled_supplier_units(): void
    {
        $c = $this->loyaltyCheckout(3);
        $order = $this->pointsOrder($c, $this->pointsQuote($c)->assertCreated()->json('data'))
            ->assertCreated()
            ->json('data');
        $this->payPointsOrder($order);
        $this->operationUser();
        $line = $order['lines'][0]['id'];
        $case = $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $line)->value('supply_version'),
            'missing_quantity' => 1,
            'message' => ['fr' => 'Rupture de test.', 'en' => 'Test shortage.'],
            'reference' => 'TEST-REDEEM-PENDING',
        ])
            ->assertOk()
            ->json('data');
        $this->client($c['client']);
        $case = $this->api('POST', 'orders/'.$order['id'].'/supply-cases/'.$case['id'].'/choice', [
            'version' => $case['version'],
            'choice' => 'refund_missing',
            'accepted_refund_amount' => '834',
        ])
            ->assertOk()
            ->json('data');
        // Trusted fixture for handover of the remaining units; the cancelled unit is still awaiting its refund.
        DB::table('orders')
            ->where('id', $order['id'])
            ->update(['delivered_at' => now()]);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->assertSame(0, DB::table('loyalty_grants')->where('order_id', $order['id'])->count());
        $this->operationUser();
        $this->transfer(['id' => $case['refund_id']], '834', 'TEST-REDEEM-PENDING-REFUND')->assertOk();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $grant = DB::table('loyalty_grants')->where('order_id', $order['id'])->first();
        $this->assertSame(1666, (int) $grant->award_base_amount);
        $this->assertSame(10, (int) $grant->points);
    }
}
