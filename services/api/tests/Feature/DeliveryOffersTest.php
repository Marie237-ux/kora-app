<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class DeliveryOffersTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Midday in Douala, same date: the payment-window travels below must not cross midnight and shift
        // "tomorrow" away from the weekday of the slot rule created before them.
        $this->travelTo(CarbonImmutable::now('Africa/Douala')->setTime(12, 0));
        $this->useFakeGateway();
    }

    /**
     * Delivery network with the south shop's offer raised to 6 000 XAF (eligible) and the north one at 1 000.
     * Test-only offer values: they are not KORA's commercial terms.
     */
    private function campaign(string $coverage = 'full', string $budget = '100000'): array
    {
        $n = $this->network();
        $this->inventoryPolicy();
        $n['rule'] = $this->slotRule();
        foreach ([$n['second']['offer'], $n['first']['offer']] as $i => $offer) {
            $price = $i === 0 ? 6000 : 1000;
            DB::table('offers')
                ->where('id', $offer)
                ->update(['selling_price' => $price, 'reference_price' => $price]);
        }
        $offer = $this->op('POST', 'promotions/delivery-offers', [
            'name' => ['fr' => 'Trois premières livraisons (test)', 'en' => 'First three deliveries (test)'],
            'starts_at' => now()->subMinute()->toIso8601String(),
            'ends_at' => null,
            'zone_ids' => [$n['south']['id']],
            'min_unit_price' => '5000',
            'uses_per_customer' => 3,
            'coverage' => $coverage,
            'budget' => $budget,
            'funded_by' => 'kora',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft')
            ->json('data');
        $n['offer'] = $this->op('POST', 'promotions/delivery-offers/'.$offer['id'].'/activate', [
            'version' => $offer['version'],
        ])
            ->assertOk()
            ->json('data');

        return $n;
    }

    private function quoteFor(array $offers, string $district = 'Akwa'): array
    {
        $cart = $this->fill($offers);

        return $this->quote($cart, $this->address($district))->assertCreated()->json('data');
    }

    private function holdSlot(array $n, array $quote): array
    {
        return $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $n['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
    }

    private function buy(array $n, array $quote, string $total): array
    {
        $quote = $this->holdSlot($n, $quote);
        $order = $this->order($quote, $total)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-'.$order['number'], $attempt['id'], (int) $total);
        $client = auth()->user();
        $this->notify([
            'transaction_id' => 'TX-'.$order['number'],
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->client($client);

        return $order;
    }

    public function test_three_deliveries_are_offered_only_with_an_item_of_5000_and_at_most_three_times(): void
    {
        $n = $this->campaign();
        $this->client();
        // A 1 000 XAF item does not qualify, whatever the cart total: the threshold is a unit price.
        $this->assertNull($this->quoteFor([[$n['first']['offer'], 10]])['promotion']);
        $cart = $this->getJson('/api/v1/cart')->json('data');
        $this->api('DELETE', 'cart/lines/'.$cart['lines'][0]['id'], ['version' => $cart['version']])->assertOk();

        for ($i = 1; $i <= 3; $i++) {
            $quote = $this->quoteFor([[$n['second']['offer'], 1]]);
            $this->assertSame(
                ['full', '1500', '0', 3 - $i],
                [
                    $quote['promotion']['coverage'],
                    $quote['promotion']['discount'],
                    $quote['payable_delivery'],
                    $quote['promotion']['uses_left_after'],
                ],
            );
            $order = $this->buy($n, $quote, '6000');
            $this->assertSame('0', $order['delivery_total']);
        }
        $this->assertNull($this->quoteFor([[$n['second']['offer'], 1]])['promotion']);
        $this->assertSame(3, DB::table('delivery_offer_usages')->where('status', 'consumed')->count());

        $this->operationUser('manager');
        $this->op('GET', 'promotions/delivery-offers')->assertOk()->assertJsonPath('data.0.consumed', '4500');
        $this->op('POST', 'promotions/delivery-offers/'.$n['offer']['id'].'/pause', [
            'version' => $n['offer']['version'],
        ])->assertForbidden();
    }

    public function test_base_coverage_states_the_supplements_still_due(): void
    {
        $n = $this->campaign('base');
        $this->client();
        // Two shops to Akwa: base 1 500 + extra collection 500 + route 700 = 2 700. Only the base is offered.
        $quote = $this->quoteFor([[$n['second']['offer'], 1], [$n['first']['offer'], 1]]);
        $this->assertSame(
            ['base', '1500', '1200'],
            [$quote['promotion']['coverage'], $quote['promotion']['discount'], $quote['payable_delivery']],
        );
        $this->assertSame('2700', $quote['total']);
        // Items 6 000 + 1 000, delivery still due 1 200.
        $order = $this->buy($n, $quote, '8200');
        $this->assertSame(['1200', 'base'], [$order['delivery_total'], $order['delivery']['promotion']['coverage']]);
    }

    public function test_the_budget_is_reserved_during_payment_and_released_when_the_order_expires(): void
    {
        $n = $this->campaign('full', '1500');
        $first = $this->client();
        $quote = $this->quoteFor([[$n['second']['offer'], 1]]);
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $n['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $this->order($quote, '6000')->assertCreated();

        // The whole budget is reserved by the pending order: nobody else gets it meanwhile.
        $this->client();
        $this->assertNull($this->quoteFor([[$n['second']['offer'], 1]])['promotion']);
        $this->operationUser();
        $this->op('GET', 'promotions/delivery-offers')
            ->assertJsonPath('data.0.reserved', '1500')
            ->assertJsonPath('data.0.available', '0');

        // An accepted total computed with an advantage that is no longer available is refused.
        $this->travel(11)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertSame('released', DB::table('delivery_offer_usages')->value('status'));
        $this->client();
        $this->assertSame('1500', $this->quoteFor([[$n['second']['offer'], 1]])['promotion']['discount']);
        $this->client($first);
    }

    public function test_an_ended_offer_no_longer_applies_and_active_offers_cannot_be_edited(): void
    {
        $n = $this->campaign();
        $offer = $n['offer'];
        $this->op('PUT', 'promotions/delivery-offers/'.$offer['id'], [
            'version' => $offer['version'],
            'name' => ['fr' => 'X', 'en' => 'X'],
            'starts_at' => now()->toIso8601String(),
            'ends_at' => null,
            'zone_ids' => [$n['south']['id']],
            'min_unit_price' => '1',
            'uses_per_customer' => 99,
            'coverage' => 'full',
            'budget' => '999999',
            'funded_by' => 'kora',
        ])->assertUnprocessable();
        $this->op('POST', 'promotions/delivery-offers/'.$offer['id'].'/end', ['version' => $offer['version']])
            ->assertOk()
            ->assertJsonPath('data.status', 'ended');
        $this->client();
        $this->assertNull($this->quoteFor([[$n['second']['offer'], 1]])['promotion']);
    }

    public function test_the_threshold_uses_the_paid_unit_price_selected_lines_and_the_destination_zone(): void
    {
        $n = $this->campaign();
        DB::table('offers')
            ->where('id', $n['second']['offer'])
            ->update(['selling_price' => 4999]);
        $this->client();
        $cart = $this->fill([[$n['second']['offer'], 2]]);
        $address = $this->address('Akwa');
        // A reference price of 6 000 and a cart total above 5 000 do not qualify a 4 999 unit.
        $this->quote($cart, $address)->assertCreated()->assertJsonPath('data.promotion', null);

        DB::table('offers')
            ->where('id', $n['second']['offer'])
            ->update(['selling_price' => 5000]);
        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $line = $cart['lines'][0];
        $this->api('POST', 'cart/accept-changes', [
            'version' => $cart['version'],
            'lines' => [['id' => $line['id'], 'conditions_hash' => $line['conditions_hash']]],
        ])->assertOk();
        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $this->quote($cart, $address)
            ->assertCreated()
            ->assertJsonPath('data.promotion.min_unit_price', '5000')
            ->assertJsonPath('data.promotion.threshold_basis', 'paid_unit_price')
            // Two 3 kg items also incur the configured 1 000 XAF weight supplement, fully covered.
            ->assertJsonPath('data.promotion.discount', '2500');
        $this->quote($cart, $this->address('Bonabéri'))->assertCreated()->assertJsonPath('data.promotion', null);

        $this->api('PUT', 'cart/lines/'.$line['id'], [
            'version' => $cart['version'],
            'quantity' => 2,
            'selected' => false,
        ])->assertOk();
        $cart = $this->fill([[$n['first']['offer'], 1]]);
        $this->quote($cart, $address)->assertCreated()->assertJsonPath('data.promotion', null);
    }

    public function test_a_changed_campaign_requires_a_new_quote_even_when_the_discount_is_identical(): void
    {
        $n = $this->campaign();
        $client = $this->client();
        $quote = $this->holdSlot($n, $this->quoteFor([[$n['second']['offer'], 1]]));
        $this->operationUser();
        $paused = $this->op('POST', 'promotions/delivery-offers/'.$n['offer']['id'].'/pause', [
            'version' => $n['offer']['version'],
        ])
            ->assertOk()
            ->json('data');
        $updated = $this->op('PUT', 'promotions/delivery-offers/'.$paused['id'], [
            ...$paused,
            'min_unit_price' => '5500',
        ])
            ->assertOk()
            ->json('data');
        $this->op('POST', 'promotions/delivery-offers/'.$updated['id'].'/activate', [
            'version' => $updated['version'],
        ])->assertOk();

        $this->client($client);
        $this->order($quote, '6000')->assertUnprocessable()->assertJsonValidationErrors('quote', 'error.fields');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('delivery_offer_usages', 0);
        $fresh = $this->quote($this->getJson('/api/v1/cart')->json('data'), $this->address('Akwa'))
            ->assertCreated()
            ->json('data');
        $this->order($this->holdSlot($n, $fresh), '6000')->assertCreated();
    }

    public function test_a_paused_campaign_cannot_reduce_its_budget_below_reserved_or_consumed_advantages(): void
    {
        $n = $this->campaign();
        $client = $this->client();
        $quote = $this->holdSlot($n, $this->quoteFor([[$n['second']['offer'], 1]]));
        $order = $this->order($quote, '6000')->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->operationUser();
        $paused = $this->op('POST', 'promotions/delivery-offers/'.$n['offer']['id'].'/pause', [
            'version' => $n['offer']['version'],
        ])
            ->assertOk()
            ->json('data');
        $this->op('PUT', 'promotions/delivery-offers/'.$paused['id'], [...$paused, 'budget' => '1499'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('budget', 'error.fields');
        $this->op('PUT', 'promotions/delivery-offers/'.$paused['id'], [...$paused, 'budget' => '1500'])
            ->assertOk()
            ->assertJsonPath('data.available', '0');

        // Pausing or editing never removes the advantage already promised to a pending order.
        $this->gateway->paid('TX-PAUSED', $attempt['id'], 6000);
        $this->notify([
            'transaction_id' => 'TX-PAUSED',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->operationUser();
        $paused = $this->op('GET', 'promotions/delivery-offers')->assertOk()->json('data.0');
        $this->assertSame(['1500', '0'], [$paused['consumed'], $paused['reserved']]);
        $this->op('PUT', 'promotions/delivery-offers/'.$paused['id'], [...$paused, 'budget' => '1499'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('budget', 'error.fields');
        $this->client($client);
    }

    public function test_a_late_payment_keeps_the_promised_price_and_reports_a_budget_overrun_once(): void
    {
        $n = $this->campaign('full', '1500');
        $first = $this->client();
        $quote = $this->holdSlot($n, $this->quoteFor([[$n['second']['offer'], 1]]));
        $order = $this->order($quote, '6000')->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->travel(11)->minutes();
        $this->artisan('payments:reconcile')->assertSuccessful();
        $this->assertDatabaseHas('delivery_offer_usages', ['order_id' => $order['id'], 'status' => 'released']);

        $this->client();
        $this->buy($n, $this->quoteFor([[$n['second']['offer'], 1]]), '6000');
        $this->gateway->paid('TX-LATE-PROMO', $attempt['id'], 6000);
        $fields = [
            'transaction_id' => 'TX-LATE-PROMO',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ];
        $this->notify($fields)->assertOk();
        $this->notify($fields)->assertOk();
        $this->assertDatabaseHas('orders', [
            'id' => $order['id'],
            'status' => 'paid',
            'delivery_total' => 0,
            'total' => 6000,
        ]);
        $this->assertSame(2, DB::table('delivery_offer_usages')->where('status', 'consumed')->count());
        $this->assertSame(1, DB::table('payment_incidents')->where('kind', 'promotion_budget_exceeded')->count());
        $this->operationUser();
        $this->op('GET', 'promotions/delivery-offers')
            ->assertOk()
            ->assertJsonPath('data.0.consumed', '3000')
            ->assertJsonPath('data.0.available', '0');
        $this->client($first);
        $this->getJson('/api/v1/orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.total', '6000');
    }

    public function test_campaign_dates_are_compared_as_instants_and_activation_requires_recent_confirmation(): void
    {
        $n = $this->campaign();
        $paused = $this->op('POST', 'promotions/delivery-offers/'.$n['offer']['id'].'/pause', [
            'version' => $n['offer']['version'],
        ])
            ->assertOk()
            ->json('data');
        $future = $this->op('PUT', 'promotions/delivery-offers/'.$paused['id'], [
            ...$paused,
            'starts_at' => now()->addHour()->setTimezone('Africa/Douala')->toIso8601String(),
            'ends_at' => now()->addHours(2)->setTimezone('Africa/Douala')->toIso8601String(),
        ])
            ->assertOk()
            ->json('data');
        $this->withSession(['auth.password_confirmed_at' => now()->subHour()->timestamp]);
        $this->op('POST', 'promotions/delivery-offers/'.$future['id'].'/activate', [
            'version' => $future['version'],
        ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->op('POST', 'promotions/delivery-offers/'.$future['id'].'/activate', [
            'version' => $future['version'],
        ])->assertOk();
        $this->client();
        $cart = $this->fill([[$n['second']['offer'], 1]]);
        $address = $this->address('Akwa');
        $this->quote($cart, $address)->assertCreated()->assertJsonPath('data.promotion', null);
        $this->travel(61)->minutes();
        $this->quote($cart, $address)->assertCreated()->assertJsonPath('data.promotion.discount', '1500');
        $this->travel(60)->minutes();
        $this->quote($cart, $address)->assertCreated()->assertJsonPath('data.promotion', null);
    }

    public function test_an_invoice_keeps_the_gross_delivery_and_promotion_snapshot_after_the_campaign_changes(): void
    {
        $n = $this->campaign('base');
        $this->client();
        $order = $this->buy($n, $this->quoteFor([[$n['second']['offer'], 1], [$n['first']['offer'], 1]]), '8200');
        $this->operationUser();
        $invoice = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'customer-documents/'.$invoice['id'].'/issue', [
            'version' => $invoice['version'],
        ])->assertOk();
        $snapshot = json_decode(DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot'), true);
        $this->assertSame(
            ['2700', '1500', '1200'],
            [
                $snapshot['delivery']['gross_total'],
                $snapshot['delivery']['promotion']['discount'],
                $snapshot['delivery']['total'],
            ],
        );
        $this->op('POST', 'promotions/delivery-offers/'.$n['offer']['id'].'/end', [
            'version' => $n['offer']['version'],
        ])->assertOk();
        $this->assertSame(
            $snapshot,
            json_decode(DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot'), true),
        );
        foreach (['fr', 'en'] as $locale) {
            $this->get('/api/v1/operations/customer-documents/'.$invoice['id'].'/pdf?locale='.$locale)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }
}
