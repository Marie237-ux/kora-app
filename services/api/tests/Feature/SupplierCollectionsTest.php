<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class SupplierCollectionsTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, PreparedOrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function actAs(User $user): void
    {
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')
            ->withSession(['mfa_verified' => true, 'auth.password_confirmed_at' => now()->timestamp])
            ->withHeaders(['Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);
    }

    private function courierCall(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->json(
            $method,
            '/api/v1/courier/'.$path,
            $data,
        );
    }

    private function courier(string $badge): User
    {
        $user = User::factory()->create(['roles' => ['courier']]);
        $this->operationUser();
        $this->op('POST', 'couriers', [
            'user_id' => $user->id,
            'public_name' => 'Test '.$badge,
            'badge' => $badge,
        ])->assertCreated();

        return $user;
    }

    /** A paid, prepared order from the depot, and the shop of its offer opened as a located collection point. */
    private function setting(): array
    {
        $o = $this->warehouseOrder(1);
        $this->readyOrder($o['order'], 1);
        $this->operationUser();
        $point = $this->point(
            ['id' => DB::table('delivery_zones')->value('id')],
            [
                'shop_id' => $o['ctx']['shop'],
                'district' => 'Akwa',
                'landmark' => 'Test landmark only',
                'latitude' => 4.0511,
                'longitude' => 9.7679,
            ],
        );
        $purchase = DB::table('purchase_orders')->first();
        $line = DB::table('purchase_lines')->where('purchase_order_id', $purchase->id)->first();

        return [
            ...$o,
            'point' => $point,
            'purchase' => $purchase,
            'line' => $line,
            'warehouse' => $o['location']['warehouse_id'],
        ];
    }

    private function collection(array $s, int $quantity)
    {
        return $this->op('POST', 'purchases/'.$s['purchase']->id.'/collections', [
            'shop_id' => $s['ctx']['shop'],
            'warehouse_id' => $s['warehouse'],
            'lines' => [['purchase_line_id' => $s['line']->id, 'quantity' => $quantity]],
        ]);
    }

    private function assign(array $item, User $courier, string $path = 'collections', array $extra = []): array
    {
        return $this->op('POST', $path.'/'.$item['id'].'/assign', [
            'version' => $item['version'],
            'courier_id' => $courier->id,
            'acceptance_required' => false,
            ...$extra,
        ])
            ->assertOk()
            ->json('data');
    }

    public function test_a_shop_collection_and_a_delivery_share_the_courier_day(): void
    {
        $courier = $this->courier('KORA-C01');
        $s = $this->setting();
        $this->assertSame(4.0511, $s['point']['latitude']);
        $this->assertSame('Akwa', $s['point']['district']);

        $dispatcher = $this->operationUser('dispatch');
        $collection = $this->collection($s, 2)
            ->assertCreated()
            ->assertJsonPath('data.status', 'unassigned')
            ->assertJsonPath('data.lines.0.expected_quantity', 2)
            ->assertJsonPath('data.pickup.district', 'Akwa')
            ->json('data');
        $this->assertStringNotContainsString('unit_cost', json_encode($collection));
        $open = (int) $s['line']->quantity - (int) $s['line']->received_quantity - 2;
        $this->collection($s, $open + 1)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines', 'error.fields');
        $collection = $this->assign($collection, $courier);
        $mission = $this->op('POST', 'orders/'.$s['order']['id'].'/missions')
            ->assertCreated()
            ->json('data');
        $mission = $this->assign($mission, $courier, 'missions');

        $date = now('Africa/Douala')->addDay()->toDateString();
        $round = $this->op('POST', 'rounds', [
            'courier_id' => $courier->id,
            'date' => $date,
            'mission_ids' => [$mission['id']],
            'collection_ids' => [$collection['id']],
        ])
            ->assertCreated()
            ->json('data');
        // Collect at the located shop first, then the depot (pick up, drop), then the customer.
        $this->assertSame(['collect', 'pickup', 'drop', 'delivery'], array_column($round['steps'], 'kind'));

        $this->actAs($courier);
        $day = $this->courierCall('GET', 'day?date='.$date)
            ->assertOk()
            ->json('data');
        $collect = $day['steps'][0];
        $this->assertSame('collect', $collect['missions'][0]['action']);
        $this->assertSame('collect_first', $day['steps'][2]['missions'][0]['action']);
        $this->assertStringContainsString('2 × ', $collect['instruction']);
        $this->assertStringContainsString('Test landmark only', $collect['instruction']);
        $this->assertSame(9.7679, $collect['place']['longitude']);
        $this->assertStringNotContainsString('unit_cost', json_encode($day));

        $view = $this->courierCall('GET', 'collections/'.$collection['id'])
            ->assertOk()
            ->json('data');
        $lines = [['purchase_line_id' => $s['line']->id, 'quantity' => 1]];
        $this->courierCall('POST', 'collections/'.$view['id'].'/collect', [
            'version' => $view['version'],
            'packages' => 1,
            'lines' => $lines,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note', 'error.fields');
        $view = $this->courierCall('POST', 'collections/'.$view['id'].'/collect', [
            'version' => $view['version'],
            'packages' => 1,
            'lines' => $lines,
            'note' => 'Shop had only one unit left (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'collected')
            ->assertJsonPath('data.shortfall', true)
            ->assertJsonPath('data.holding_goods', true)
            ->json('data');
        $day = $this->courierCall('GET', 'day?date='.$date)->json('data');
        $this->assertSame('done', $day['steps'][0]['state']);
        $this->assertSame('drop', $day['steps'][2]['missions'][0]['action']);
        $this->assertSame(1, $day['progress']['parcels_on_board']);

        $this->courierCall('POST', 'collections/'.$view['id'].'/drop', [
            'version' => $view['version'],
            'packages' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('packages', 'error.fields');
        $this->courierCall('POST', 'collections/'.$view['id'].'/drop', [
            'version' => $view['version'],
            'packages' => 1,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'dropped');
        $day = $this->courierCall('GET', 'day?date='.$date)->json('data');
        $this->assertSame(['done', 'todo', 'done', 'todo'], array_column($day['steps'], 'state'));
        $this->assertSame(
            ['collections_total' => 1, 'collections_done' => 1],
            array_intersect_key($day['progress'], array_flip(['collections_total', 'collections_done'])),
        );
        // The courier cannot close the collection: receiving is the stock team's job, with evidence.
        $this->courierCall('POST', 'collections/'.$view['id'].'/received', ['version' => 1])->assertNotFound();

        $this->operationUser();
        $receipt = $this->receipt(
            $s['ctx'],
            ['id' => $s['purchase']->id, 'lines' => [['id' => $s['line']->id]]],
            $s['location'],
            1,
        );
        $this->operationUser('stock');
        $current = DB::table('supplier_collections')->where('id', $collection['id'])->first();
        $this->op('POST', 'collections/'.$collection['id'].'/received', [
            'version' => $current->version,
            'receipt_id' => $this->fixtureId(),
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('receipt_id', 'error.fields');
        $this->op('POST', 'collections/'.$collection['id'].'/received', [
            'version' => $current->version,
            'receipt_id' => $receipt['id'],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.receipt_id', $receipt['id']);
        $this->actAs($dispatcher);
        // The unit the shop lacked is open again for a later collection.
        $this->collection($s, $open + 1)->assertCreated();
    }

    public function test_reassignment_takes_goods_over_and_incidents_cancel_or_retry(): void
    {
        $first = $this->courier('KORA-C02');
        $second = $this->courier('KORA-C03');
        $s = $this->setting();
        $this->operationUser('dispatch');
        $collection = $this->assign($this->collection($s, 2)->json('data'), $first);

        $this->actAs($first);
        $collected = $this->courierCall('POST', 'collections/'.$collection['id'].'/collect', [
            'version' => $collection['version'],
            'packages' => 2,
            'lines' => [['purchase_line_id' => $s['line']->id, 'quantity' => 2]],
        ])
            ->assertOk()
            ->assertJsonPath('data.shortfall', false)
            ->json('data');

        $this->operationUser('dispatch');
        $this->op('POST', 'collections/'.$collected['id'].'/cancel', [
            'version' => $collected['version'],
            'reason' => 'Test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection', 'error.fields');
        $this->op('POST', 'collections/'.$collected['id'].'/assign', [
            'version' => $collected['version'],
            'courier_id' => $second->id,
            'acceptance_required' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note', 'error.fields');
        $this->assign($collected, $second, 'collections', ['note' => 'Handed over at the depot gate (test).']);

        $this->actAs($first);
        $this->courierCall('GET', 'collections/'.$collected['id'])->assertNotFound();
        $this->courierCall('POST', 'collections/'.$collected['id'].'/drop', [
            'version' => $collected['version'],
            'packages' => 2,
        ])->assertNotFound();

        $this->actAs($second);
        $view = $this->courierCall('GET', 'collections/'.$collected['id'])
            ->assertOk()
            ->assertJsonPath('data.take_over_from_courier', true)
            ->json('data');
        $this->courierCall('POST', 'collections/'.$view['id'].'/collect', [
            'version' => $view['version'],
            'packages' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('packages', 'error.fields');
        $view = $this->courierCall('POST', 'collections/'.$view['id'].'/collect', [
            'version' => $view['version'],
            'packages' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.holding_goods', true)
            ->json('data');
        $this->courierCall('POST', 'collections/'.$view['id'].'/drop', [
            'version' => $view['version'],
            'packages' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'dropped');
        $this->assertTrue(DB::table('supplier_collection_events')->where('action', 'parcels_taken_over')->exists());
        // Counted quantities stay as counted at the shop.
        $this->assertSame(2, (int) DB::table('supplier_collection_lines')->value('collected_quantity'));

        // Shop closed: retried, then cancelled before anything was collected.
        $this->operationUser('dispatch');
        $other = $this->assign($this->collection($s, 1)->assertCreated()->json('data'), $first);
        $this->actAs($first);
        $other = $this->courierCall('POST', 'collections/'.$other['id'].'/incident', [
            'version' => $other['version'],
            'kind' => 'shop_closed',
            'note' => 'Shutters down at 10:00 (test).',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'incident')
            ->json('data');
        $this->operationUser('dispatch');
        $other = $this->op('POST', 'collections/'.$other['id'].'/retry', [
            'version' => $other['version'],
            'note' => 'Shop opens at 11:00 (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->json('data');
        $this->op('POST', 'collections/'.$other['id'].'/cancel', [
            'version' => $other['version'],
            'reason' => 'Supplier postponed (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        // Rights: couriers and other roles do not manage collections; stock cannot dispatch.
        $this->actAs($second);
        $this->getJson('/api/v1/operations/collections')->assertForbidden();
        $this->operationUser('catalogue');
        $this->op('GET', 'collections')->assertForbidden();
        $this->operationUser('stock');
        $this->op('POST', 'collections/'.$other['id'].'/assign', [
            'version' => 1,
            'courier_id' => $first->id,
            'acceptance_required' => false,
        ])->assertForbidden();

        // History is protected in the database.
        foreach (
            [
                fn () => DB::table('supplier_collections')->where('id', $other['id'])->delete(),
                fn () => DB::table('supplier_collection_lines')
                    ->where('collection_id', $collected['id'])
                    ->update(['collected_quantity' => 1]),
                fn () => DB::table('supplier_collection_events')->delete(),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('A collection record was rewritten.');
            } catch (QueryException) {
                $this->assertSame(2, DB::table('supplier_collections')->count());
            }
        }
    }

    public function test_collection_point_location_is_optional_and_kept_when_omitted(): void
    {
        $this->operationUser();
        $zone = $this->zone('SOUTH', ['Akwa']);
        $ctx = $this->fixtureCatalogue();
        $base = [
            'zone_id' => $zone['id'],
            'shop_id' => $ctx['shop'],
            'name' => 'Test point',
            'address' => 'Test only',
            'priority' => 0,
            'active' => true,
        ];
        $this->op('POST', 'delivery/collection-points', [...$base, 'latitude' => 4.05])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('latitude', 'error.fields');
        $point = $this->op('POST', 'delivery/collection-points', [
            ...$base,
            'district' => 'Akwa',
            'latitude' => 4.05,
            'longitude' => 9.7,
        ])
            ->assertCreated()
            ->json('data');
        $this->op('PUT', 'delivery/collection-points/'.$point['id'], [
            ...$base,
            'version' => $point['version'],
            'name' => 'Renamed',
        ])
            ->assertOk()
            ->assertJsonPath('data.district', 'Akwa')
            ->assertJsonPath('data.latitude', 4.05);
        $this->op('PUT', 'delivery/collection-points/'.$point['id'], [
            ...$base,
            'version' => $point['version'] + 1,
            'latitude' => null,
            'longitude' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.latitude', null);
    }
}
