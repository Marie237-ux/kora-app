<?php

namespace Tests\Support;

use App\Models\User;

/** Delivery set-up shared by the delivery and order tests; requires ProcurementFixtures. */
trait DeliveryFixtures
{
    /** Test-only amounts, flagged as demo: they are not KORA tariffs. */
    protected const RULES = [
        'demo' => true,
        'extra_collection_fee' => 500,
        'mass_brackets' => [['max_grams' => 5000, 'amount' => 0], ['max_grams' => 20000, 'amount' => 1000]],
        'bulky_fee' => 2000,
        'fragile_fee' => 800,
        'validity_minutes' => 30,
        'warehouse_preparation_minutes' => 120,
    ];

    protected function client(?User $user = null): User
    {
        $user ??= User::factory()->create(['roles' => ['client']]);
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeaders([
            'Origin' => 'http://localhost:4321',
            'Accept' => 'application/json',
        ]);

        return $user;
    }

    protected function api(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', $this->fixtureId())->json($method, '/api/v1/'.$path, $data);
    }

    protected function zone(string $code, array $districts): array
    {
        return $this->op('POST', 'delivery/zones', [
            'code' => $code,
            'name' => ['fr' => 'Zone '.$code, 'en' => 'Zone '.$code],
            'districts' => $districts,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function point(array $zone, array $owner): array
    {
        return $this->op('POST', 'delivery/collection-points', [
            ...$owner,
            'zone_id' => $zone['id'],
            'name' => 'Test point',
            'address' => 'Test only, not a real address',
            'priority' => 0,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function grid(array $zones, array $routes = []): array
    {
        $grid = $this->op('POST', 'delivery/grids', [
            'notes' => 'Test grid',
            'rules' => [
                ...self::RULES,
                'zones' => array_map(fn ($z) => ['zone_id' => $z[0]['id'], 'base_fee' => $z[1]], $zones),
                'route_supplements' => $routes,
            ],
        ])
            ->assertCreated()
            ->json('data');

        return $this->op('POST', 'delivery/grids/'.$grid['id'].'/publish', [
            'version' => $grid['version'],
            'reviewed' => true,
        ])
            ->assertOk()
            ->json('data');
    }

    protected function profile(array $ctx, ?int $grams, string $class = 'standard'): void
    {
        $this->op('PUT', 'inventory/profiles/'.$ctx['variant'], [
            'version' => 1,
            'tracking_mode' => 'none',
            'requires_expiry' => false,
            'logistics' => [
                'mass_grams' => $grams,
                'length_mm' => null,
                'width_mm' => null,
                'height_mm' => null,
                'transport_class' => $class,
                'handling' => ['fr' => '', 'en' => ''],
            ],
        ])->assertOk();
    }

    protected function supplierReady(array $ctx, int $minutes = 60, ?int $version = null): void
    {
        $this->op('PUT', 'supplier-availability/'.$ctx['offer'], [
            'version' => $version,
            'quantity' => null,
            'verified_at' => now()->subMinute()->toIso8601String(),
            'valid_until' => now()->addDays(2)->toIso8601String(),
            'preparation_minutes' => $minutes,
            'contact' => 'Test contact only',
            'reference' => 'TEST ONLY',
        ])->assertOk();
    }

    protected function address(string $district): array
    {
        return $this->api('POST', 'account/addresses', [
            'label' => 'Maison',
            'district' => $district,
            'landmark' => 'Test landmark only',
            'contact_name' => 'Test client',
            'contact_phone' => '+237 600 000 000',
        ])
            ->assertCreated()
            ->json('data');
    }

    protected function fill(array $offers): array
    {
        foreach ($offers as [$offer, $quantity]) {
            $this->api('POST', 'cart/lines', [
                'version' => $this->getJson('/api/v1/cart')->json('data.version'),
                'offer_id' => $offer,
                'quantity' => $quantity,
                'selected' => true,
            ])->assertCreated();
        }

        return $this->getJson('/api/v1/cart')->assertOk()->json('data');
    }

    protected function quote(array $cart, array $address)
    {
        return $this->api('POST', 'cart/delivery-quotes', [
            'cart_version' => $cart['version'],
            'address_id' => $address['id'],
        ]);
    }

    /** Two supplier-collected offers from two shops, one point outside the destination zone. */
    protected function network(): array
    {
        $this->operationUser();
        $first = $this->fixtureCatalogue();
        $second = $this->fixtureCatalogue();
        $north = $this->zone('NORTH', ['Bonabéri']);
        $south = $this->zone('SOUTH', ['Bonapriso', 'Akwa']);
        $this->point($north, ['shop_id' => $first['shop']]);
        $this->point($south, ['shop_id' => $second['shop']]);
        $this->profile($first, 2000);
        $this->profile($second, 3000);
        $this->supplierReady($first, 60);
        $this->supplierReady($second, 90);
        $grid = $this->grid(
            [[$north, 1200], [$south, 1500]],
            [['from_zone_id' => $north['id'], 'to_zone_id' => $south['id'], 'amount' => 700]],
        );

        return compact('first', 'second', 'north', 'south', 'grid');
    }
}
