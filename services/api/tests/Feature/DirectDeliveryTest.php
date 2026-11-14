<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

/** Units not in a KORA depot, bought at a shop and received there on the way to the customer. */
final class DirectDeliveryTest extends TestCase
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

    /**
     * A paid order of 3 units that KORA does not hold: confirmed by the supplier, bought for the customer and
     * allocated to the line, with an "on the road" location to receive them into.
     */
    private function boughtOrder(): array
    {
        $n = $this->network();
        $this->inventoryPolicy();
        $rule = $this->slotRule();
        $client = $this->client();
        $quote = $this->quote($this->fill([[$n['second']['offer'], 3]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $rule['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $total = (string) (3000 + (int) $quote['payable_delivery']);
        $order = $this->order($quote, $total)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TX-DIRECT', $attempt['id'], (int) $total);
        $this->notify([
            'transaction_id' => 'TX-DIRECT',
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
            'reference' => 'TEST CONFIRMATION',
        ])->assertOk();
        $purchase = $this->purchase($n['second'], $this->contract($n['second']), 3, true, 'customer_needs');
        $this->op('POST', 'orders/'.$order['id'].'/lines/'.$line.'/purchase-allocations', [
            'version' => (int) DB::table('order_lines')->where('id', $line)->value('supply_version'),
            'purchase_line_id' => $purchase['lines'][0]['id'],
            'quantity' => 3,
        ])->assertCreated();
        $transit = $this->location();

        return compact('n', 'client', 'order', 'purchase', 'transit', 'line');
    }

    private function direct(array $b, ?int $supervisor = null)
    {
        return $this->op('POST', 'purchases/'.$b['purchase']['id'].'/direct-collections', [
            'order_id' => $b['order']['id'],
            'shop_id' => $b['n']['second']['shop'],
            'transit_location_id' => $b['transit']['id'],
            ...$supervisor ? ['supervisor_id' => $supervisor] : [],
        ]);
    }

    /** Stock team declares the order ready with no depot parcel, then dispatch sends the courier. */
    private function dispatch(array $b, User $courier): array
    {
        $this->operationUser('stock');
        $view = $this->op('GET', 'orders/'.$b['order']['id'].'/preparation')
            ->assertJsonPath('data.lines.0.direct_collection_quantity', 3)
            ->assertJsonPath('data.lines.0.awaiting_stock_quantity', 0)
            ->json('data');
        $this->op('POST', 'orders/'.$b['order']['id'].'/preparation/ready', [
            'version' => $view['version'],
            'packages' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');
        $this->operationUser('dispatch');
        $mission = $this->op('POST', 'orders/'.$b['order']['id'].'/missions')
            ->assertCreated()
            ->assertJsonPath('data.packages', 0)
            ->json('data');

        return $this->op('POST', 'missions/'.$mission['id'].'/assign', [
            'version' => $mission['version'],
            'courier_id' => $courier->id,
            'acceptance_required' => false,
        ])->json('data');
    }

    private function photo(string $missionId, string $collectionId)
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->post(
            '/api/v1/courier/missions/'.$missionId.'/shop-photos',
            [
                'collection_id' => $collectionId,
                'file' => UploadedFile::fake()->createWithContent(
                    'slip.pdf',
                    "%PDF-1.4\n% Test shop slip only.\n%%EOF",
                ),
            ],
        );
    }

    public function test_the_courier_receives_at_the_shop_and_delivers_straight_to_the_customer(): void
    {
        $courier = $this->courier('KORA-D01');
        $b = $this->boughtOrder();
        $this->operationUser('stock');
        $view = $this->op('GET', 'orders/'.$b['order']['id'].'/preparation')->json('data');
        // Without a direct collection, nothing covers the units: zero depot parcels is refused.
        $this->op('POST', 'orders/'.$b['order']['id'].'/preparation/ready', [
            'version' => $view['version'],
            'packages' => 0,
        ])->assertUnprocessable();

        $this->operationUser('dispatch');
        $collection = $this->direct($b)
            ->assertCreated()
            ->assertJsonPath('data.destination.type', 'customer')
            ->assertJsonPath('data.lines.0.expected_quantity', 3)
            ->json('data');
        $this->direct($b)->assertUnprocessable()->assertJsonValidationErrors('order_id', 'error.fields');
        $mission = $this->dispatch($b, $courier);

        $date = now('Africa/Douala')->addDay()->toDateString();
        $this->op('POST', 'rounds', [
            'courier_id' => $courier->id,
            'date' => $date,
            'mission_ids' => [$mission['id']],
        ])->assertCreated();
        $this->actAs($courier);
        $day = $this->courierCall('GET', 'day?date='.$date)
            ->assertOk()
            ->json('data');
        $this->assertSame(['collect', 'delivery'], array_column($day['steps'], 'kind'));
        $this->assertSame('shop_receipt', $day['steps'][0]['missions'][0]['action']);
        $this->assertSame($collection['id'], $day['steps'][0]['missions'][0]['collection_id']);
        $this->assertStringContainsString('3 × ', $day['steps'][0]['instruction']);
        $this->assertStringContainsString($b['order']['number'], $day['steps'][0]['instruction']);
        $this->assertSame('pickup_first', $day['steps'][1]['missions'][0]['action']);
        $this->courierCall('GET', 'missions/'.$mission['id'])->assertJsonPath(
            'data.shop_receipts.0.receiver',
            'courier',
        );
        // Nothing to take at the depot for this order.
        $this->courierCall('POST', 'missions/'.$mission['id'].'/pickup', [
            'version' => $mission['version'],
            'packages' => 0,
        ])->assertUnprocessable();

        $document = $this->photo($mission['id'], $collection['id'])->assertCreated()->json('data');
        $lines = [['purchase_line_id' => $b['purchase']['lines'][0]['id'], 'quantity' => 2, 'identifiers' => []]];
        $receipt = fn (array $lines, array $extra = []) => $this->courierCall(
            'POST',
            'missions/'.$mission['id'].'/shop-receipts',
            [
                'collection_id' => $collection['id'],
                'version' => $collection['version'],
                'document_id' => $document['id'],
                'packages' => 1,
                'lines' => $lines,
                ...$extra,
            ],
        );
        $receipt($lines)->assertUnprocessable()->assertJsonValidationErrors('note', 'error.fields');
        $lines[0]['quantity'] = 3;
        $view = $receipt($lines)
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.packages', 1)
            ->assertJsonPath('data.holding_parcels', true)
            ->assertJsonCount(0, 'data.shop_receipts')
            ->json('data');

        // Received with the photo into the "on the road" location, then issued to the order on the spot.
        $receiptId = DB::table('supplier_collections')->value('receipt_id');
        $this->assertSame(
            $b['transit']['id'],
            DB::table('goods_receipts')->where('id', $receiptId)->value('location_id'),
        );
        $this->assertSame($document['id'], DB::table('goods_receipts')->where('id', $receiptId)->value('document_id'));
        $this->assertSame($courier->id, (int) DB::table('supplier_collections')->value('received_by'));
        $this->assertSame(3, (int) DB::table('order_preparation_picks')->where('origin', 'receipt')->sum('quantity'));
        $this->assertSame(
            0,
            (int) DB::table('inventory_balances')->where('location_id', $b['transit']['id'])->sum('on_hand'),
        );
        $this->assertSame(1, DB::table('inventory_movements')->where('kind', 'sale_issue')->count());
        $day = $this->courierCall('GET', 'day?date='.$date)->json('data');
        $this->assertSame(['done', 'todo'], array_column($day['steps'], 'state'));
        $this->assertSame('handover', $day['steps'][1]['missions'][0]['action']);

        $this->client($b['client']);
        $code = $this->getJson('/api/v1/orders/'.$b['order']['id'])->json('data.delivery.mission.handover_code');
        $this->actAs($courier);
        $this->courierCall('POST', 'missions/'.$mission['id'].'/handover', [
            'version' => $view['version'],
            'code' => $code,
            'packages' => 1,
            'items' => [['order_line_id' => $b['line'], 'quantity' => 3, 'identifiers' => []]],
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
        $this->assertNotNull(DB::table('orders')->where('id', $b['order']['id'])->value('delivered_at'));
    }

    public function test_a_designated_manager_receives_and_the_courier_cannot_hand_over_before(): void
    {
        $courier = $this->courier('KORA-D02');
        $b = $this->boughtOrder();
        $manager = $this->operationUser('stock');
        $this->operationUser('dispatch');
        $this->direct($b, User::factory()->create(['roles' => ['client']])->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('supervisor_id', 'error.fields');
        $collection = $this->direct($b, $manager->id)->assertCreated()->json('data');
        $mission = $this->dispatch($b, $courier);

        $this->actAs($courier);
        $this->courierCall('GET', 'missions/'.$mission['id'])
            ->assertJsonPath('data.shop_receipts.0.receiver', 'supervisor')
            ->assertJsonPath('data.shop_receipts.0.supervisor_name', $manager->name);
        $document = $this->photo($mission['id'], $collection['id'])->assertCreated()->json('data');
        $lines = [['purchase_line_id' => $b['purchase']['lines'][0]['id'], 'quantity' => 3, 'identifiers' => []]];
        $this->courierCall('POST', 'missions/'.$mission['id'].'/shop-receipts', [
            'collection_id' => $collection['id'],
            'version' => $collection['version'],
            'document_id' => $document['id'],
            'packages' => 1,
            'lines' => $lines,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('collection', 'error.fields');
        // A courier cannot photograph for another order's collection.
        $this->photo($mission['id'], $this->fixtureId())->assertNotFound();

        $this->actAs($manager);
        $this->op('POST', 'collections/'.$collection['id'].'/direct-receipt', [
            'version' => $collection['version'],
            'document_id' => $this->evidence($b['n']['second']['supplier'], 'stock_evidence')['id'],
            'packages' => 2,
            'lines' => $lines,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'received')
            ->assertJsonPath('data.received_by', $manager->id);
        $this->assertSame(2, (int) DB::table('delivery_missions')->value('packages'));
        $this->assertSame('in_transit', DB::table('delivery_missions')->value('status'));
        // Depot-collection actions never apply to a direct collection.
        $this->operationUser('dispatch');
        $this->op('POST', 'collections/'.$collection['id'].'/assign', [
            'version' => $collection['version'] + 1,
            'courier_id' => $courier->id,
            'acceptance_required' => false,
        ])->assertUnprocessable();
    }
}
