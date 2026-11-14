<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class DeliveryTest extends TestCase
{
    use DeliveryFixtures, ProcurementFixtures, RefreshDatabase;

    public function test_districts_match_without_accents_and_belong_to_a_single_active_zone(): void
    {
        $this->operationUser();
        $zone = $this->zone('SOUTH', ['Bonapriso', 'Akwa']);
        $this->op('POST', 'delivery/zones', [
            'code' => 'OTHER',
            'name' => ['fr' => 'Autre', 'en' => 'Other'],
            'districts' => ['AKWA '],
            'active' => true,
        ])->assertUnprocessable();
        $this->op('POST', 'delivery/zones', [
            'code' => 'DUP',
            'name' => ['fr' => 'Doublon', 'en' => 'Duplicate'],
            'districts' => ['Deido', 'déïdo'],
            'active' => true,
        ])->assertUnprocessable();

        $this->client();
        $this->getJson('/api/v1/delivery/zones')->assertOk()->assertJsonPath('data.0.id', $zone['id']);
        $served = $this->address('  bonaprisó ');
        $this->assertTrue($served['served']);
        $this->assertSame($zone['id'], $served['zone']['id']);
        $this->assertFalse($this->address('Quartier inconnu')['served']);
    }

    public function test_addresses_are_private_versioned_and_archived_instead_of_deleted(): void
    {
        $owner = $this->client();
        $address = $this->address('Akwa');
        $this->api('PUT', 'account/addresses/'.$address['id'], [
            'version' => $address['version'] + 5,
            'label' => 'Bureau',
            'district' => 'Akwa',
            'landmark' => 'Test landmark only',
            'contact_name' => 'Test client',
            'contact_phone' => '+237 600 000 000',
        ])->assertConflict();
        $this->api('PUT', 'account/addresses/'.$address['id'], [
            'version' => $address['version'],
            'label' => 'Bureau',
            'district' => 'Akwa',
            'landmark' => 'Test landmark only',
            'contact_name' => 'Test client',
            'contact_phone' => 'not a phone',
        ])->assertUnprocessable();

        $this->client();
        $this->getJson('/api/v1/account/addresses')->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', 'account/addresses/'.$address['id'].'/archive', ['version' => 1])->assertNotFound();

        $this->client($owner);
        $this->api('POST', 'account/addresses/'.$address['id'].'/archive', ['version' => 1])
            ->assertOk()
            ->assertJsonPath('data.archived', true);
        $this->getJson('/api/v1/account/addresses')->assertOk()->assertJsonCount(0, 'data');
        $this->assertNotNull(DB::table('customer_addresses')->where('id', $address['id'])->value('archived_at'));
    }

    public function test_quote_counts_real_collection_points_route_and_mass_once_with_exact_total(): void
    {
        $n = $this->network();
        $this->client();
        $address = $this->address('Bonapriso');
        $cart = $this->fill([[$n['first']['offer'], 2], [$n['second']['offer'], 1]]);
        $quote = $this->quote($cart, $address)
            ->assertCreated()
            ->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.reasons', [])
            ->assertJsonPath('data.demo_amounts', true)
            ->assertJsonPath('data.collection_count', 2)
            ->assertJsonPath('data.ready_after_minutes', 90)
            ->json('data');
        // Base 1 500 + one extra collection 500 + route north→south 700 + 7 kg bracket 1 000.
        $this->assertSame('3700', $quote['total']);
        $this->assertSame(
            [
                ['code' => 'base', 'quantity' => 1, 'amount' => '1500'],
                ['code' => 'extra_collections', 'quantity' => 1, 'amount' => '500'],
                ['code' => 'route', 'quantity' => 1, 'amount' => '700'],
                ['code' => 'mass', 'quantity' => 7000, 'amount' => '1000'],
                ['code' => 'bulky', 'quantity' => 0, 'amount' => '0'],
                ['code' => 'fragile', 'quantity' => 0, 'amount' => '0'],
            ],
            $quote['breakdown'],
        );
        $this->assertSame([0, 0], array_column($quote['lines'], 'from_warehouse'));
        $stored = DB::table('delivery_quotes')->where('id', $quote['id'])->first();
        $this->assertSame($n['grid']['id'], $stored->grid_id);
        // Customer view never exposes collection point identities or stock locations.
        $this->assertStringNotContainsString('Test point', json_encode($quote));
        $this->assertArrayNotHasKey('points', $quote);
    }

    public function test_a_quote_is_never_zero_by_default_when_data_is_missing(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $south = $this->zone('SOUTH', ['Akwa']);
        $this->point($south, ['shop_id' => $ctx['shop']]);
        $this->supplierReady($ctx);

        $client = $this->client();
        $address = $this->address('Akwa');
        $cart = $this->fill([[$ctx['offer'], 1]]);
        // Default fixture profile: unknown mass and a transport class that cannot be delivered.
        $this->quote($cart, $address)
            ->assertCreated()
            ->assertJsonPath('data.status', 'unserviceable')
            ->assertJsonPath('data.total', null)
            ->assertJsonPath('data.slots', []);

        $this->operationUser();
        $this->profile($ctx, null);
        $this->client($client);
        $missing = $this->quote($cart, $address)->assertCreated()->json('data');
        $this->assertSame('confirmation_required', $missing['status']);
        $this->assertEqualsCanonicalizing(['mass_unknown', 'grid_missing'], $missing['reasons']);
        $this->assertNull($missing['total']);

        $this->assertSame(
            ['zone_not_served'],
            array_values(
                array_diff($this->quote($cart, $this->address('Ailleurs'))->json('data.reasons'), ['mass_unknown']),
            ),
        );
    }

    public function test_depot_stock_is_collected_from_the_depot_point_with_its_preparation_delay(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 10);
        $south = $this->zone('SOUTH', ['Akwa']);
        $this->point($south, ['warehouse_id' => $location['warehouse_id']]);
        $this->profile($ctx, 1000, 'fragile');
        $this->grid([[$south, 1000]]);

        $this->client();
        $quote = $this->quote($this->fill([[$ctx['offer'], 3]]), $this->address('Akwa'))
            ->assertCreated()
            ->assertJsonPath('data.status', 'quoted')
            ->assertJsonPath('data.collection_count', 1)
            ->assertJsonPath('data.ready_after_minutes', 120)
            ->assertJsonPath('data.lines.0.from_warehouse', 3)
            ->assertJsonPath('data.lines.0.from_supplier', 0)
            ->json('data');
        // Base 1 000 + fragile 800; no extra collection and no route supplement inside the zone.
        $this->assertSame('1800', $quote['total']);
    }

    public function test_published_grids_are_frozen_and_a_new_grid_does_not_rewrite_past_quotes(): void
    {
        $n = $this->network();
        $this->op('PUT', 'delivery/grids/'.$n['grid']['id'], [
            'version' => $n['grid']['version'],
            'notes' => '',
            'rules' => [
                ...self::RULES,
                'zones' => [['zone_id' => $n['south']['id'], 'base_fee' => 1]],
                'route_supplements' => [],
            ],
        ])->assertUnprocessable();
        $this->op('POST', 'delivery/grids', [
            'notes' => '',
            'rules' => [
                ...self::RULES,
                'mass_brackets' => [['max_grams' => 5000, 'amount' => 0], ['max_grams' => 1000, 'amount' => 1]],
                'zones' => [['zone_id' => $n['south']['id'], 'base_fee' => 1]],
                'route_supplements' => [],
            ],
        ])->assertUnprocessable();

        $client = $this->client();
        $address = $this->address('Akwa');
        $cart = $this->fill([[$n['second']['offer'], 1]]);
        $before = $this->quote($cart, $address)->assertJsonPath('data.total', '1500')->json('data');

        $this->operationUser();
        $next = $this->grid([[$n['south'], 2500]]);
        $this->assertSame('retired', DB::table('delivery_grids')->where('id', $n['grid']['id'])->value('status'));

        $this->client($client);
        $this->getJson('/api/v1/cart/delivery-quotes/'.$before['id'])
            ->assertOk()
            ->assertJsonPath('data.total', '1500');
        $after = $this->quote($cart, $address)->assertJsonPath('data.total', '2500')->json('data');
        $this->assertSame($next['id'], DB::table('delivery_quotes')->where('id', $after['id'])->value('grid_id'));
    }

    public function test_the_last_place_of_a_slot_is_held_once_and_released_when_the_quote_expires(): void
    {
        $n = $this->network();
        $tomorrow = CarbonImmutable::now('Africa/Douala')->addDay();
        $rule = $this->op('POST', 'delivery/slot-rules', [
            'zone_id' => null,
            'weekday' => $tomorrow->dayOfWeekIso,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'capacity' => 1,
            'cutoff_minutes' => 0,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');

        $first = $this->client();
        $cartA = $this->fill([[$n['second']['offer'], 1]]);
        $quoteA = $this->quote($cartA, $this->address('Akwa'))->json('data');
        $slot = collect($quoteA['slots'])->firstWhere('date', $tomorrow->toDateString());
        $this->assertSame(
            ['rule_id' => $rule['id'], 'remaining' => 1],
            ['rule_id' => $slot['rule_id'], 'remaining' => $slot['remaining']],
        );
        $hold = ['version' => $quoteA['version'], 'rule_id' => $rule['id'], 'date' => $tomorrow->toDateString()];
        $this->api('POST', 'cart/delivery-quotes/'.$quoteA['id'].'/slot', $hold)
            ->assertOk()
            ->assertJsonPath('data.slot.status', 'held');

        $second = $this->client();
        $quoteB = $this->quote($this->fill([[$n['second']['offer'], 1]]), $this->address('Akwa'))->json('data');
        $this->assertNull(collect($quoteB['slots'])->firstWhere('rule_id', $rule['id']));
        $this->api('POST', 'cart/delivery-quotes/'.$quoteB['id'].'/slot', [
            ...$hold,
            'version' => $quoteB['version'],
        ])->assertUnprocessable();
        // Another account cannot read or use someone else's quote.
        $this->getJson('/api/v1/cart/delivery-quotes/'.$quoteA['id'])->assertNotFound();

        $this->travel(31)->minutes();
        $this->client($first);
        $this->getJson('/api/v1/cart/delivery-quotes/'.$quoteA['id'])
            ->assertOk()
            ->assertJsonPath('data.expired', true)
            ->assertJsonPath('data.slot.status', 'expired');
        $this->client($second);
        $fresh = $this->quote($this->getJson('/api/v1/cart')->json('data'), $this->address('Akwa'))->json('data');
        $this->api('POST', 'cart/delivery-quotes/'.$fresh['id'].'/slot', [...$hold, 'version' => $fresh['version']])
            ->assertOk()
            ->assertJsonPath('data.slot.status', 'held');
        $this->api('POST', 'cart/delivery-quotes/'.$quoteB['id'].'/slot', [
            ...$hold,
            'version' => $quoteB['version'],
        ])->assertUnprocessable();
    }

    public function test_cut_off_closures_and_cart_changes_close_slots_honestly(): void
    {
        $n = $this->network();
        $tomorrow = CarbonImmutable::now('Africa/Douala')->addDay();
        $rule = $this->op('POST', 'delivery/slot-rules', [
            'zone_id' => $n['south']['id'],
            'weekday' => $tomorrow->dayOfWeekIso,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'capacity' => 5,
            'cutoff_minutes' => 0,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'delivery/slot-closures', [
            'date' => $tomorrow->toDateString(),
            'rule_id' => null,
            'capacity' => 0,
            'reason' => 'Test closure only',
        ])->assertCreated();

        $client = $this->client();
        $cart = $this->fill([[$n['second']['offer'], 1]]);
        $quote = $this->quote($cart, $this->address('Akwa'))->json('data');
        $this->assertNull(collect($quote['slots'])->firstWhere('date', $tomorrow->toDateString()));

        // A preparation delay longer than the time left before the slot hides it as well.
        $this->operationUser();
        DB::table('delivery_slot_closures')->delete();
        $this->supplierReady($n['second'], 60 * 24 * 3, 1);
        $this->client($client);
        $slow = $this->quote($cart, $this->address('Akwa'))->json('data');
        $this->assertNull(collect($slow['slots'])->firstWhere('rule_id', $rule['id']));

        $this->operationUser();
        $this->supplierReady($n['second'], 60, 2);
        $this->client($client);
        $open = $this->quote($cart, $this->address('Akwa'))->json('data');
        $this->assertSame(5, collect($open['slots'])->firstWhere('rule_id', $rule['id'])['remaining']);
        $quote = $this->quote($cart, $this->address('Akwa'))->json('data');
        $this->fill([[$n['first']['offer'], 1]]);
        $this->getJson('/api/v1/cart/delivery-quotes/'.$quote['id'])
            ->assertJsonPath('data.stale', true)
            ->assertJsonPath('data.slots', []);
        $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $rule['id'],
            'date' => $tomorrow->toDateString(),
        ])->assertUnprocessable();
    }

    public function test_delivery_settings_require_their_permission(): void
    {
        $this->operationUser('dispatch');
        $this->op('GET', 'delivery/zones')->assertOk();
        $this->op('POST', 'delivery/zones', [
            'code' => 'X',
            'name' => ['fr' => 'X', 'en' => 'X'],
            'districts' => ['X'],
            'active' => true,
        ])->assertForbidden();
        $this->client();
        $this->op('GET', 'delivery/grids')->assertForbidden();
        $this->api('POST', 'cart/delivery-quotes', [
            'cart_version' => $this->getJson('/api/v1/cart')->json('data.version'),
            'address_id' => $this->fixtureId(),
        ])->assertNotFound();
    }
}
