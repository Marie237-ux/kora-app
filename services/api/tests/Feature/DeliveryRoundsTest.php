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

final class DeliveryRoundsTest extends TestCase
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

    /** A second paid order of the same customer, one unit from the same warehouse. */
    private function anotherOrder(array $o): array
    {
        $this->client($o['client']);
        $quote = $this->quote($this->fill([[$o['ctx']['offer'], 1]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => DB::table('delivery_slot_rules')->value('id'),
            'date' => now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $order = $this->order($quote, '2000')->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $tx = 'TEST-'.$this->fixtureId();
        $this->gateway->paid($tx, $attempt['id'], 2000);
        $this->notify([
            'transaction_id' => $tx,
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();

        return $order;
    }

    /** Two prepared orders, each with a mission assigned to the courier, and a round for tomorrow. */
    private function day(User $courier): array
    {
        $o = $this->warehouseOrder(2);
        $second = $this->anotherOrder($o);
        $this->readyOrder($o['order'], 2);
        $this->readyOrder($second, 1);
        $dispatcher = $this->operationUser('dispatch');
        $missions = [];
        foreach ([$o['order'], $second] as $order) {
            $m = $this->op('POST', 'orders/'.$order['id'].'/missions')
                ->assertCreated()
                ->json('data');
            $missions[] = $this->op('POST', 'missions/'.$m['id'].'/assign', [
                'version' => $m['version'],
                'courier_id' => $courier->id,
                'acceptance_required' => false,
            ])->json('data');
        }
        $date = now('Africa/Douala')->addDay()->toDateString();
        $round = $this->op('POST', 'rounds', [
            'courier_id' => $courier->id,
            'date' => $date,
            'mission_ids' => array_column($missions, 'id'),
        ])
            ->assertCreated()
            ->json('data');

        return [
            ...$o,
            'second' => $second,
            'missions' => $missions,
            'round' => $round,
            'date' => $date,
            'dispatcher' => $dispatcher,
        ];
    }

    public function test_the_courier_follows_the_day_step_by_step(): void
    {
        $courier = $this->courier('KORA-R01');
        $d = $this->day($courier);
        $steps = $d['round']['steps'];
        // One stop at the depot for both orders, then each customer.
        $this->assertSame(['pickup', 'delivery', 'delivery'], array_column($steps, 'kind'));
        $this->assertCount(2, $steps[0]['missions']);
        $this->assertSame('warehouse', $steps[0]['place']['type']);
        $this->assertSame(1, $d['round']['current_step']);
        $this->assertSame(2, $d['round']['next_step']);
        $this->assertSame(
            [
                'steps_total' => 3,
                'steps_done' => 0,
                'deliveries_total' => 2,
                'deliveries_done' => 0,
                'collections_total' => 0,
                'collections_done' => 0,
                'trips_total' => 0,
                'trips_done' => 0,
                'parcels_on_board' => 0,
            ],
            $d['round']['progress'],
        );

        $this->actAs($courier);
        $day = $this->courierCall('GET', 'day?date='.$d['date'])
            ->assertOk()
            ->json('data');
        $this->assertSame($d['round']['id'], $day['id']);
        $this->assertStringContainsString('3', $day['steps'][0]['instruction']);
        $this->assertStringContainsString(DB::table('warehouses')->value('name'), $day['steps'][0]['instruction']);
        $this->assertStringContainsString('Akwa', $day['steps'][1]['instruction']);
        $this->assertSame('pickup', $day['steps'][0]['missions'][0]['action']);
        $this->assertSame('pickup_first', $day['steps'][1]['missions'][0]['action']);
        $this->assertNotNull($day['steps'][1]['place']['contact_phone']);
        // No stop identifiers, codes or prices on the courier's phone.
        $json = json_encode($day);
        $this->assertStringNotContainsString('stop_id', $json);
        $this->assertStringNotContainsString('handover_code', $json);
        $this->assertStringNotContainsString('unit_price', $json);
        // Today has no round for this courier.
        $this->courierCall('GET', 'day?date='.now('Africa/Douala')->toDateString())
            ->assertOk()
            ->assertJsonPath('data', null);

        // The depot step is done only once both missions are on board.
        $first = $day['steps'][0]['missions'][0];
        $this->courierCall('POST', 'missions/'.$first['mission_id'].'/pickup', [
            'version' => $first['version'],
            'packages' => $first['packages'],
        ])->assertOk();
        $day = $this->courierCall('GET', 'day?date='.$d['date'])->json('data');
        $this->assertSame('todo', $day['steps'][0]['state']);
        $this->assertSame(1, $day['current_step']);
        $second = $day['steps'][0]['missions'][1];
        $this->courierCall('POST', 'missions/'.$second['mission_id'].'/pickup', [
            'version' => $second['version'],
            'packages' => $second['packages'],
        ])->assertOk();
        $day = $this->courierCall('GET', 'day?date='.$d['date'])->json('data');
        $this->assertSame('done', $day['steps'][0]['state']);
        $this->assertSame(2, $day['current_step']);
        $this->assertSame(3, $day['progress']['parcels_on_board']);
        $this->assertSame('handover', $day['steps'][1]['missions'][0]['action']);

        // Deliver the current step with the customer's code.
        $target = $day['steps'][1]['missions'][0];
        $view = $this->courierCall('GET', 'missions/'.$target['mission_id'])->json('data');
        $this->client($d['client']);
        $order = DB::table('delivery_missions')->where('id', $target['mission_id'])->value('order_id');
        $code = $this->getJson('/api/v1/orders/'.$order)->json('data.delivery.mission.handover_code');
        $this->actAs($courier);
        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', [
            'version' => $view['version'],
            'code' => $code,
            'packages' => $view['packages'],
            'items' => collect($view['items'])
                ->map(
                    fn ($i) => [
                        'order_line_id' => $i['order_line_id'],
                        'quantity' => $i['quantity'],
                        'identifiers' => $i['identifiers'],
                    ],
                )
                ->all(),
        ])->assertOk();
        $day = $this->courierCall('GET', 'day?date='.$d['date'])->json('data');
        $this->assertSame('done', $day['steps'][1]['state']);
        $this->assertArrayNotHasKey('contact_phone', $day['steps'][1]['place']);
        $this->assertSame(3, $day['current_step']);
        $this->assertNull($day['next_step']);
        $this->assertSame(1, $day['progress']['deliveries_done']);
    }

    public function test_reassignment_reorder_replan_and_cancellation(): void
    {
        $courier = $this->courier('KORA-R02');
        $other = $this->courier('KORA-R03');
        $d = $this->day($courier);
        $this->actAs($d['dispatcher']);
        $round = $d['round'];
        $stops = DB::table('delivery_round_stops')->where('round_id', $round['id'])->orderBy('sequence')->get();

        // A delivery cannot be placed before its pickup.
        $deliveryFirst = [$stops[2]->id, $stops[0]->id, $stops[1]->id, $stops[3]->id];
        $this->op('PUT', 'rounds/'.$round['id'].'/stops', [
            'version' => $round['version'],
            'stop_ids' => $deliveryFirst,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('stop_ids', 'error.fields');
        $this->op('PUT', 'rounds/'.$round['id'].'/stops', [
            'version' => $round['version'],
            'stop_ids' => [$stops[0]->id],
        ])->assertUnprocessable();
        $swapped = [$stops[0]->id, $stops[1]->id, $stops[3]->id, $stops[2]->id];
        $round = $this->op('PUT', 'rounds/'.$round['id'].'/stops', [
            'version' => $round['version'],
            'stop_ids' => $swapped,
        ])
            ->assertOk()
            ->json('data');
        $this->assertSame(
            [$stops[3]->mission_id, $stops[2]->mission_id],
            [$round['steps'][1]['missions'][0]['mission_id'], $round['steps'][2]['missions'][0]['mission_id']],
        );
        $this->assertSame('manual', DB::table('delivery_round_stops')->where('id', $stops[3]->id)->value('origin'));

        // A mission given to another courier leaves this courier's day.
        $moved = $d['missions'][1];
        $current = $this->op('GET', 'missions/'.$moved['id'])->json('data');
        $this->op('POST', 'missions/'.$moved['id'].'/assign', [
            'version' => $current['version'],
            'courier_id' => $other->id,
            'acceptance_required' => false,
        ])->assertOk();
        $round = $this->op('GET', 'rounds/'.$round['id'])->json('data');
        $removed = collect($round['steps'])->first(
            fn ($s) => $s['kind'] === 'delivery' && $s['missions'][0]['mission_id'] === $moved['id'],
        );
        $this->assertSame('removed', $removed['state']);
        $this->assertSame(2, $round['progress']['steps_total']);
        // It cannot be added to this courier's round any more, nor to two rounds at once.
        $this->op('PUT', 'rounds/'.$round['id'].'/missions', [
            'version' => $round['version'],
            'mission_ids' => [$d['missions'][0]['id'], $moved['id']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mission_ids', 'error.fields');
        $this->op('POST', 'rounds', [
            'courier_id' => $other->id,
            'date' => $d['date'],
            'mission_ids' => [$moved['id']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mission_ids', 'error.fields');

        // Replanning keeps done steps first and drops the mission that left.
        $this->actAs($courier);
        $mine = $this->courierCall('GET', 'missions/'.$d['missions'][0]['id'])->json('data');
        $this->courierCall('POST', 'missions/'.$mine['id'].'/pickup', [
            'version' => $mine['version'],
            'packages' => $mine['packages'],
        ])->assertOk();
        $this->actAs($d['dispatcher']);
        $round = $this->op('PUT', 'rounds/'.$round['id'].'/missions', [
            'version' => $round['version'],
            'mission_ids' => [$d['missions'][0]['id']],
        ])
            ->assertOk()
            ->json('data');
        $this->assertSame(['pickup', 'delivery'], array_column($round['steps'], 'kind'));
        $this->assertSame(['done', 'todo'], array_column($round['steps'], 'state'));
        $this->op('POST', 'rounds', [
            'courier_id' => $other->id,
            'date' => $d['date'],
            'mission_ids' => [$moved['id']],
        ])->assertCreated();
        $this->op('POST', 'rounds', [
            'courier_id' => $courier->id,
            'date' => $d['date'],
            'mission_ids' => [$d['missions'][0]['id']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date', 'error.fields');

        $this->op('POST', 'rounds/'.$round['id'].'/cancel', [
            'version' => $round['version'],
            'reason' => 'Rain (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->actAs($courier);
        $this->courierCall('GET', 'day?date='.$d['date'])
            ->assertOk()
            ->assertJsonPath('data', null);
        $this->getJson('/api/v1/operations/rounds')->assertForbidden();

        // A cancelled round is history.
        try {
            DB::transaction(fn () => DB::table('delivery_round_stops')->where('round_id', $round['id'])->delete());
            $this->fail('A cancelled round lost its stops.');
        } catch (QueryException) {
            $this->assertSame(2, DB::table('delivery_round_stops')->where('round_id', $round['id'])->count());
        }
    }
}
