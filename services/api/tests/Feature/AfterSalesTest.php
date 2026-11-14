<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class AfterSalesTest extends TestCase
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

    private function customer(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->json(
            $method,
            '/api/v1/account/'.$path,
            $data,
        );
    }

    /** Two units from the depot, really handed over by a courier with the customer's code. */
    private function delivered(): array
    {
        $o = $this->warehouseOrder(2);
        $this->readyOrder($o['order'], 1);
        $courier = User::factory()->create(['roles' => ['courier']]);
        $this->operationUser();
        $this->op('POST', 'couriers', [
            'user_id' => $courier->id,
            'public_name' => 'Test',
            'badge' => 'KORA-S01',
        ])->assertCreated();
        $mission = $this->op('POST', 'orders/'.$o['order']['id'].'/missions')->json('data');
        $mission = $this->op('POST', 'missions/'.$mission['id'].'/assign', [
            'version' => $mission['version'],
            'courier_id' => $courier->id,
            'acceptance_required' => false,
        ])->json('data');
        $this->client($o['client']);
        $code = $this->getJson('/api/v1/orders/'.$o['order']['id'])->json('data.delivery.mission.handover_code');
        $this->actAs($courier);
        $call = fn (string $path, array $data) => $this->withHeader('Idempotency-Key', (string) Str::uuid())->json(
            'POST',
            '/api/v1/courier/missions/'.$mission['id'].'/'.$path,
            $data,
        );
        $view = $call('pickup', ['version' => $mission['version'], 'packages' => 1])
            ->assertOk()
            ->json('data');
        $call('handover', [
            'version' => $view['version'],
            'code' => $code,
            'packages' => 1,
            'items' => [['order_line_id' => $o['order']['lines'][0]['id'], 'quantity' => 2, 'identifiers' => []]],
        ])->assertOk();
        $this->client($o['client']);

        return [...$o, 'line' => $o['order']['lines'][0]['id']];
    }

    public function test_a_returned_item_is_received_controlled_and_refunded_before_the_case_closes(): void
    {
        $d = $this->delivered();
        $this->customer('GET', 'purchases')
            ->assertOk()
            ->assertJsonPath('data.0.lines.0.delivered_quantity', 2)
            ->assertJsonPath('data.0.lines.0.open_cases', 0);
        $this->customer('POST', 'after-sales', [
            'order_line_id' => $d['line'],
            'kind' => 'damaged',
            'quantity' => 3,
            'identifiers' => [],
            'description' => 'Too many',
        ])->assertUnprocessable();
        $case = $this->customer('POST', 'after-sales', [
            'order_line_id' => $d['line'],
            'kind' => 'damaged',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Screen cracked when opening the box (test).',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data');
        $this->assertStringStartsWith('SAV-', $case['number']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->post('/api/v1/account/after-sales/'.$case['id'].'/attachments', [
                'file' => UploadedFile::fake()->createWithContent('crack.pdf', "%PDF-1.4\n% Test photo only.\n%%EOF"),
            ])
            ->assertCreated()
            ->assertJsonPath('data.messages.0.attachment', true);

        // Support: internal note never shown to the customer, remedy proposed with responsibility.
        $agent = $this->operationUser('support');
        $staff = $this->op('GET', 'after-sales/'.$case['id'])
            ->assertOk()
            ->assertJsonPath('data.reported_quickly', true)
            ->json('data');
        $this->get(
            '/api/v1/operations/after-sales/'.$case['id'].'/attachments/'.$staff['messages'][0]['id'],
        )->assertOk();
        $this->op('POST', 'after-sales/'.$case['id'].'/messages', [
            'body' => 'Supplier batch known for weak packaging (internal test).',
            'visibility' => 'internal',
        ])->assertCreated();
        $staff = $this->op('POST', 'after-sales/'.$case['id'].'/assign', [
            'version' => $staff['version'],
            'assignee_id' => $agent->id,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_review')
            ->json('data');
        $this->op('POST', 'after-sales/'.$case['id'].'/proposal', [
            'version' => $staff['version'],
            'resolution' => 'return_refund',
            'amount' => 1001,
            'responsibility' => 'supplier',
            'responsibility_note' => 'Packaging defect (test).',
            'message' => 'Return it and we refund you.',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('amount', 'error.fields');
        $this->op('POST', 'after-sales/'.$case['id'].'/proposal', [
            'version' => $staff['version'],
            'resolution' => 'return_refund',
            'responsibility' => 'supplier',
            'responsibility_note' => 'Packaging defect (test).',
            'message' => 'Return it and we refund you.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'proposed')
            ->assertJsonPath('data.proposal.amount', '1000');

        $this->client($d['client']);
        $mine = $this->customer('GET', 'after-sales/'.$case['id'])
            ->assertOk()
            ->json('data');
        $json = json_encode($mine);
        $this->assertStringNotContainsString('internal test', $json);
        $this->assertStringNotContainsString('Packaging defect', $json);
        $this->assertSame(explode(' ', $agent->name)[0], $mine['contact']);
        $this->customer('POST', 'after-sales/'.$case['id'].'/decision', [
            'version' => $mine['version'],
            'accept' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted');

        // Refund only after the item is back; the item is quarantined until controlled.
        $this->operationUser();
        $current = fn () => DB::table('after_sales_cases')->value('version');
        $this->op('POST', 'after-sales/'.$case['id'].'/refund', ['version' => $current()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('case', 'error.fields');
        $available = fn () => (int) DB::table('inventory_balances')->where('condition', 'available')->sum('on_hand');
        $before = $available();
        $this->operationUser('stock');
        $staff = $this->op('POST', 'after-sales/'.$case['id'].'/returns', [
            'version' => $current(),
            'location_id' => $d['location']['id'],
            'quantity' => 1,
            'identifiers' => [],
        ])
            ->assertCreated()
            ->assertJsonPath('data.returns.0.control', 'pending')
            ->json('data');
        $this->assertSame($before, $available());
        $this->assertSame(1, (int) DB::table('inventory_balances')->where('condition', 'returned')->sum('on_hand'));
        $this->assertSame(1, DB::table('inventory_movements')->where('kind', 'customer_return')->count());

        $this->operationUser();
        $staff = $this->op('POST', 'after-sales/'.$case['id'].'/refund', ['version' => $current()])
            ->assertOk()
            ->json('data');
        $this->op('POST', 'after-sales/'.$case['id'].'/resolve', [
            'version' => $current(),
            'message' => 'Done',
        ])->assertUnprocessable();
        $this->transfer(['id' => $staff['refund_id']], '1000', 'TEST-SAV-REFUND')->assertOk();
        $this->op('POST', 'after-sales/'.$case['id'].'/resolve', ['version' => $current(), 'message' => 'Done'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('case', 'error.fields');
        $this->operationUser('stock');
        $return = $staff['returns'][0];
        $this->op('POST', 'after-sales-returns/'.$return['id'].'/control', [
            'version' => $return['version'],
            'result' => 'resellable',
            'note' => 'Unit tested, only the box was damaged (test).',
        ])->assertOk();
        $this->assertSame($before + 1, $available());

        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$case['id'].'/resolve', [
            'version' => $current(),
            'message' => 'Refund sent, thank you for your patience.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        $this->client($d['client']);
        $this->customer('GET', 'after-sales/'.$case['id'])
            ->assertJsonPath('data.refund_status', 'paid')
            ->assertJsonPath('data.status', 'resolved');
        $this->customer('POST', 'after-sales/'.$case['id'].'/messages', ['body' => 'Thanks'])->assertCreated();
    }

    public function test_an_exchange_is_fetched_and_delivered_by_a_courier_before_the_case_closes(): void
    {
        $d = $this->delivered();
        $case = $this->customer('POST', 'after-sales', [
            'order_line_id' => $d['line'],
            'kind' => 'breakdown',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Stopped working after two days (test).',
        ])->json('data');
        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$case['id'].'/proposal', [
            'version' => $case['version'],
            'resolution' => 'exchange',
            'responsibility' => 'supplier',
            'responsibility_note' => 'Known defect (test).',
            'message' => 'We exchange it.',
        ])->assertOk();
        $this->client($d['client']);
        $this->customer('POST', 'after-sales/'.$case['id'].'/decision', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'accept' => true,
        ])->assertOk();

        // Dispatch plans the return pickup; the stock team issues the exchange item from stock.
        $courier = User::factory()->create(['roles' => ['courier']]);
        $owner = $this->operationUser();
        $this->op('POST', 'couriers', [
            'user_id' => $courier->id,
            'public_name' => 'Test',
            'badge' => 'KORA-S02',
        ])->assertCreated();
        $warehouse = $d['location']['warehouse_id'];
        $pickup = $this->op('POST', 'after-sales/'.$case['id'].'/pickup-trips', ['warehouse_id' => $warehouse])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'pickup')
            ->json('data');
        $this->op('POST', 'after-sales/'.$case['id'].'/pickup-trips', [
            'warehouse_id' => $warehouse,
        ])->assertUnprocessable();
        $issued = DB::table('inventory_movements')->where('kind', 'sale_issue')->count();
        $exchange = $this->op('POST', 'after-sales/'.$case['id'].'/exchange-shipments', [
            'warehouse_id' => $warehouse,
            'packages' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'delivery')
            ->json('data');
        $this->assertSame($issued + 1, DB::table('inventory_movements')->where('kind', 'sale_issue')->count());
        foreach ([$pickup, $exchange] as $trip) {
            $this->op('POST', 'after-sales-trips/'.$trip['id'].'/assign', [
                'version' => $trip['version'],
                'courier_id' => $courier->id,
            ])->assertOk();
        }
        $date = now('Africa/Douala')->addDay()->toDateString();
        $round = $this->op('POST', 'rounds', [
            'courier_id' => $courier->id,
            'date' => $date,
            'trip_ids' => [$pickup['id'], $exchange['id']],
        ])
            ->assertCreated()
            ->json('data');
        $this->assertSame(['pickup', 'pickup', 'delivery', 'delivery'], array_column($round['steps'], 'kind'));
        $this->assertSame('take', $round['steps'][0]['missions'][0]['action']);
        $this->assertStringContainsString($case['number'], $round['steps'][0]['instruction']);

        $this->actAs($courier);
        $call = fn (string $trip, string $action, array $data) => $this->withHeader(
            'Idempotency-Key',
            (string) Str::uuid(),
        )->json('POST', '/api/v1/courier/trips/'.$trip.'/'.$action, $data);
        $version = fn (string $trip) => (int) DB::table('after_sales_trips')->where('id', $trip)->value('version');
        $call($pickup['id'], 'take', [
            'version' => $version($pickup['id']),
            'packages' => 1,
            'quantity' => 2,
            'identifiers' => [],
        ])->assertUnprocessable();
        $call($pickup['id'], 'take', [
            'version' => $version($pickup['id']),
            'packages' => 1,
            'quantity' => 1,
            'identifiers' => [],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit');
        $call($exchange['id'], 'take', ['version' => $version($exchange['id']), 'packages' => 1])->assertOk();
        $call($pickup['id'], 'drop', ['version' => $version($pickup['id']), 'packages' => 1])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $call($exchange['id'], 'handover', ['version' => $version($exchange['id']), 'code' => '000000'])
            ->assertUnprocessable()
            ->assertJsonPath('data.attempts_left', 4);

        // The return reaches stock and is found defective; the case cannot close before the exchange is handed over.
        $this->operationUser('stock');
        $staff = $this->op('POST', 'after-sales/'.$case['id'].'/returns', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'location_id' => $d['location']['id'],
            'quantity' => 1,
            'identifiers' => [],
        ])->json('data');
        $this->op('POST', 'after-sales-returns/'.$staff['returns'][0]['id'].'/control', [
            'version' => $staff['returns'][0]['version'],
            'result' => 'defective',
            'note' => 'Does not power on (test).',
        ])->assertOk();
        $this->assertSame(1, (int) DB::table('inventory_balances')->where('condition', 'damaged')->sum('on_hand'));
        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$case['id'].'/resolve', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'message' => 'Done',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('case', 'error.fields');

        $this->client($d['client']);
        $code = collect($this->customer('GET', 'after-sales/'.$case['id'])->json('data.trips'))->firstWhere(
            'direction',
            'delivery',
        )['handover_code'];
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->actAs($courier);
        $call($exchange['id'], 'handover', ['version' => $version($exchange['id']), 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->getJson('/api/v1/courier/day?date='.$date)
            ->assertJsonPath('data.progress.trips_done', 2);

        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$case['id'].'/resolve', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'message' => 'Your exchange item was delivered.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        $this->assertSame(
            $owner->id,
            (int) DB::table('after_sales_trips')->where('id', $pickup['id'])->value('created_by'),
        );
    }

    public function test_cases_are_private_rejected_with_a_reason_and_protected(): void
    {
        $d = $this->delivered();
        $case = $this->customer('POST', 'after-sales', [
            'order_line_id' => $d['line'],
            'kind' => 'other',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Changed my mind (test).',
        ])->json('data');

        // Another customer sees nothing; staff without support rights are refused.
        $this->client();
        $this->customer('GET', 'after-sales/'.$case['id'])->assertNotFound();
        $this->customer('POST', 'after-sales', [
            'order_line_id' => $d['line'],
            'kind' => 'other',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Not my order',
        ])->assertNotFound();
        $this->operationUser('catalogue');
        $this->op('GET', 'after-sales')->assertForbidden();
        $this->operationUser('stock');
        $this->op('POST', 'after-sales/'.$case['id'].'/reject', [
            'version' => 1,
            'message' => 'No',
        ])->assertForbidden();

        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$case['id'].'/reject', [
            'version' => $case['version'],
            'message' => 'Unopened returns are accepted within the shop policy only (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->op('POST', 'after-sales/'.$case['id'].'/proposal', [
            'version' => $case['version'] + 1,
            'resolution' => 'refund',
            'responsibility' => 'customer',
            'responsibility_note' => 'Test',
            'message' => 'Test',
        ])->assertUnprocessable();

        foreach (
            [
                fn () => DB::table('after_sales_cases')->delete(),
                fn () => DB::table('after_sales_cases')->update(['description' => 'Rewritten']),
                fn () => DB::table('after_sales_messages')->update(['body' => 'Rewritten']),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('An after-sales record was rewritten.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('after_sales_cases')->count());
            }
        }
    }
}
