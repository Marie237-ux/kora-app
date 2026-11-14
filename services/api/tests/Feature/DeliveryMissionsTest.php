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

final class DeliveryMissionsTest extends TestCase
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

    private function courierCall(string $method, string $path, array $data = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid())->json(
            $method,
            '/api/v1/courier/'.$path,
            $data,
        );
    }

    /** A courier account with its public profile, registered by the owner. */
    private function courier(string $badge, array $roles = ['courier']): User
    {
        $user = User::factory()->create(['roles' => $roles, 'two_factor_confirmed_at' => now()]);
        $this->operationUser();
        $this->op('POST', 'couriers', [
            'user_id' => $user->id,
            'public_name' => 'Test '.$badge,
            'badge' => $badge,
        ])->assertCreated();

        return $user;
    }

    /** Paid, prepared order with a mission assigned to the courier; the dispatcher stays signed in. */
    private function assigned(
        User $courier,
        int $quantity = 2,
        bool $acceptance = false,
        array $identifiers = [],
    ): array {
        $o = $this->warehouseOrder($quantity, $identifiers ? 'serial' : 'none', $identifiers);
        $this->readyOrder($o['order'], 2);
        $dispatcher = $this->operationUser('dispatch');
        $mission = $this->op('POST', 'orders/'.$o['order']['id'].'/missions')
            ->assertCreated()
            ->json('data');
        $mission = $this->op('POST', 'missions/'.$mission['id'].'/assign', [
            'version' => $mission['version'],
            'courier_id' => $courier->id,
            'acceptance_required' => $acceptance,
        ])
            ->assertOk()
            ->json('data');

        return [...$o, 'mission' => $mission, 'dispatcher' => $dispatcher];
    }

    private function code(array $o): string
    {
        $this->client($o['client']);

        return $this->getJson('/api/v1/orders/'.$o['order']['id'])
            ->assertOk()
            ->json('data.delivery.mission.handover_code');
    }

    private function control(array $view, ?string $code = null, ?int $packages = null): array
    {
        return [
            'version' => $view['version'],
            ...$code === null ? [] : ['code' => $code],
            'packages' => $packages ?? $view['packages'],
            'items' => array_map(
                fn ($i) => [
                    'order_line_id' => $i['order_line_id'],
                    'quantity' => $i['quantity'],
                    'identifiers' => $i['identifiers'],
                ],
                $view['items'],
            ),
        ];
    }

    private function wrong(string $code): string
    {
        return str_pad((string) (((int) $code + 1) % 1000000), 6, '0', STR_PAD_LEFT);
    }

    public function test_a_prepared_order_is_picked_up_then_delivered_with_the_customer_code(): void
    {
        $courier = $this->courier('KORA-L01');
        $o = $this->assigned($courier);
        $this->assertSame('accepted', $o['mission']['status']);
        $this->assertSame(2, $o['mission']['packages']);
        $this->assertSame('warehouse', $o['mission']['custody']);

        $code = $this->code($o);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $code);
        $this->getJson('/api/v1/orders/'.$o['order']['id'])
            ->assertJsonPath('data.delivery.mission.status', 'scheduled')
            ->assertJsonPath('data.delivery.mission.courier.badge', 'KORA-L01')
            ->assertJsonPath('data.delivery.mission.reference', $o['mission']['number']);

        $this->actAs($courier);
        $view = $this->courierCall('GET', 'missions')->assertOk()->json('data.0');
        $this->assertSame($o['mission']['id'], $view['id']);
        $this->assertSame('Akwa', $view['address']['district']);
        $this->assertSame(2, $view['items'][0]['quantity']);
        $this->assertSame('warehouse', $view['pickup']['from']);
        $this->assertSame([DB::table('warehouses')->value('code')], $view['pickup']['warehouses']);
        // The courier never sees the code, prices or costs.
        $json = json_encode($view);
        $this->assertStringNotContainsString($code, $json);
        $this->assertStringNotContainsString('unit_price', $json);
        $this->assertStringNotContainsString('unit_cost', $json);

        $this->courierCall('POST', 'missions/'.$view['id'].'/pickup', [
            'version' => $view['version'],
            'packages' => 1,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('packages', 'error.fields');
        $view = $this->courierCall('POST', 'missions/'.$view['id'].'/pickup', [
            'version' => $view['version'],
            'packages' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.holding_parcels', true)
            ->json('data');

        // A short delivery is an incident, not a delivery: the code is not even tried.
        $short = $this->control($view, $code);
        $short['items'][0]['quantity'] = 1;
        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', $short)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items', 'error.fields');
        $this->assertSame(0, DB::table('delivery_handover_attempts')->count());

        // A wrong code is refused but counted, even though the request fails.
        $key = (string) Str::uuid();
        $failed = $this->courierCall(
            'POST',
            'missions/'.$view['id'].'/handover',
            $this->control($view, $this->wrong($code)),
            $key,
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code', 'error.fields')
            ->assertJsonPath('data.attempts_left', 4)
            ->json('data.mission');
        $this->courierCall(
            'POST',
            'missions/'.$view['id'].'/handover',
            $this->control($view, $this->wrong($code)),
            $key,
        )
            ->assertUnprocessable()
            ->assertJsonPath('data.attempts_left', 4);
        $this->assertSame(1, DB::table('delivery_handover_attempts')->where('accepted', false)->count());
        $this->assertSame(1, (int) DB::table('delivery_missions')->value('code_failures'));

        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', $this->control($failed, $code))
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonMissingPath('data.address');
        $this->assertNotNull(DB::table('orders')->where('id', $o['order']['id'])->value('delivered_at'));
        $this->assertSame('code', DB::table('delivery_missions')->value('delivery_method'));
        $this->assertNull(DB::table('delivery_missions')->value('custodian_id'));
        $this->courierCall('GET', 'missions?scope=history')->assertOk()->assertJsonCount(1, 'data');

        $this->client($o['client']);
        $this->getJson('/api/v1/orders/'.$o['order']['id'])
            ->assertJsonPath('data.delivery.mission.status', 'delivered')
            ->assertJsonPath('data.delivery.mission.handover_code', null)
            ->assertJsonPath('data.delivery.mission.courier', null);
    }

    public function test_the_code_locks_and_an_alternative_handover_needs_another_person(): void
    {
        // The founder may both dispatch and deliver: supervision still needs someone else.
        $courier = $this->courier('KORA-L02', ['courier', 'dispatch']);
        $o = $this->assigned($courier, 1, false, ['TEST-IMEI-1']);
        $code = $this->code($o);
        $this->actAs($courier);
        $view = $this->courierCall('GET', 'missions/'.$o['mission']['id'])
            ->assertOk()
            ->json('data');
        $this->assertSame(['TEST-IMEI-1'], $view['items'][0]['identifiers']);
        $view = $this->courierCall('POST', 'missions/'.$view['id'].'/pickup', [
            'version' => $view['version'],
            'packages' => 2,
        ])
            ->assertOk()
            ->json('data');
        $wrongSerial = $this->control($view, $code);
        $wrongSerial['items'][0]['identifiers'] = ['TEST-IMEI-9'];
        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', $wrongSerial)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('items', 'error.fields');
        for ($i = 4; $i >= 0; $i--) {
            $view = $this->courierCall(
                'POST',
                'missions/'.$view['id'].'/handover',
                $this->control($view, $this->wrong($code)),
            )
                ->assertUnprocessable()
                ->assertJsonPath('data.attempts_left', $i)
                ->json('data.mission');
        }
        $this->assertTrue($view['code']['locked']);
        // Even the right code no longer works once locked.
        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', $this->control($view, $code))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code', 'error.fields');
        $this->client($o['client']);
        $this->getJson('/api/v1/orders/'.$o['order']['id'])->assertJsonPath(
            'data.delivery.mission.handover_code',
            null,
        );

        $this->actAs($courier);
        $view = $this->courierCall('POST', 'missions/'.$view['id'].'/alternative', [
            ...$this->control($view),
            'reason' => 'code_locked',
            'note' => 'Customer could not read the SMS (test).',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_approval')
            ->json('data');
        $this->op('POST', 'missions/'.$view['id'].'/alternative/approve', [
            'version' => $view['version'],
            'note' => 'Self approval attempt',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mission', 'error.fields');

        $this->actAs($o['dispatcher']);
        $this->op('POST', 'missions/'.$view['id'].'/alternative/approve', [
            'version' => $view['version'],
            'note' => 'Called the customer back, identity confirmed (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered')
            ->assertJsonPath('data.delivery_method', 'alternative')
            ->assertJsonPath('data.alternatives.0.status', 'approved')
            ->assertJsonMissingPath('data.code.value');
        $this->assertNotNull(DB::table('orders')->value('delivered_at'));
    }

    public function test_a_reassigned_mission_cannot_be_completed_from_the_previous_courier_device(): void
    {
        $first = $this->courier('KORA-L03');
        $second = $this->courier('KORA-L04');
        $o = $this->assigned($first);
        $code = $this->code($o);
        $this->actAs($first);
        $key = (string) Str::uuid();
        $stale = $this->courierCall(
            'POST',
            'missions/'.$o['mission']['id'].'/pickup',
            [
                'version' => $o['mission']['version'],
                'packages' => 2,
            ],
            $key,
        )
            ->assertOk()
            ->json('data');

        $this->actAs($o['dispatcher']);
        $this->op('POST', 'missions/'.$stale['id'].'/assign', [
            'version' => $stale['version'],
            'courier_id' => $second->id,
            'acceptance_required' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('note', 'error.fields');
        $this->op('POST', 'missions/'.$stale['id'].'/assign', [
            'version' => $stale['version'],
            'courier_id' => $second->id,
            'acceptance_required' => false,
            'note' => 'Parcels handed over at Akwa roundabout (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->assertJsonPath('data.custody', 'courier')
            ->assertJsonPath('data.custodian_id', $first->id);

        // The old device, back online: no view, no replayed data, no handover.
        $this->actAs($first);
        $this->courierCall('GET', 'missions/'.$stale['id'])->assertNotFound();
        $this->courierCall('GET', 'missions')->assertOk()->assertJsonCount(0, 'data');
        $this->courierCall(
            'POST',
            'missions/'.$stale['id'].'/pickup',
            ['version' => $o['mission']['version'], 'packages' => 2],
            $key,
        )->assertNotFound();
        $this->courierCall(
            'POST',
            'missions/'.$stale['id'].'/handover',
            $this->control($stale, $code),
        )->assertNotFound();
        $this->assertNull(DB::table('orders')->value('delivered_at'));

        $this->actAs($second);
        $view = $this->courierCall('GET', 'missions/'.$stale['id'])
            ->assertOk()
            ->assertJsonPath('data.pickup.from', 'courier')
            ->json('data');
        $view = $this->courierCall('POST', 'missions/'.$view['id'].'/pickup', [
            'version' => $view['version'],
            'packages' => 2,
        ])
            ->assertOk()
            ->json('data');
        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', $this->control($view, $code))
            ->assertOk()
            ->assertJsonPath('data.status', 'delivered');
        $this->assertTrue(
            DB::table('delivery_mission_events')
                ->where('action', 'parcels_picked_up')
                ->where('data', 'like', '%"from":"courier"%')
                ->exists(),
        );
    }

    public function test_acceptance_incidents_return_to_warehouse_and_cancellation(): void
    {
        $courier = $this->courier('KORA-L05');
        $o = $this->assigned($courier, 1, true);
        $this->assertSame('assigned', $o['mission']['status']);
        $this->actAs($courier);
        $this->courierCall('POST', 'missions/'.$o['mission']['id'].'/pickup', [
            'version' => $o['mission']['version'],
            'packages' => 2,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mission', 'error.fields');
        $this->courierCall('POST', 'missions/'.$o['mission']['id'].'/decline', [
            'version' => $o['mission']['version'],
            'reason' => 'Motorbike at the garage (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined');
        $this->courierCall('GET', 'missions/'.$o['mission']['id'])->assertNotFound();

        $this->actAs($o['dispatcher']);
        $mission = $this->op('GET', 'missions/'.$o['mission']['id'])
            ->assertJsonPath('data.status', 'unassigned')
            ->json('data');
        $mission = $this->op('POST', 'missions/'.$mission['id'].'/assign', [
            'version' => $mission['version'],
            'courier_id' => $courier->id,
            'acceptance_required' => true,
            'sequence' => 3,
        ])->json('data');
        $this->actAs($courier);
        $view = $this->courierCall('POST', 'missions/'.$mission['id'].'/accept', ['version' => $mission['version']])
            ->assertOk()
            ->assertJsonPath('data.sequence', 3)
            ->json('data');
        $view = $this->courierCall('POST', 'missions/'.$view['id'].'/pickup', [
            'version' => $view['version'],
            'packages' => 2,
        ])->json('data');
        $view = $this->courierCall('POST', 'missions/'.$view['id'].'/incident', [
            'version' => $view['version'],
            'kind' => 'customer_absent',
            'note' => 'Nobody at the gate, phone off (test).',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'incident')
            ->json('data');
        $this->client($o['client']);
        $customer = $this->getJson('/api/v1/orders/'.$o['order']['id'])
            ->assertJsonPath('data.delivery.mission.status', 'delayed')
            ->json('data');
        $this->assertStringNotContainsString('phone off', json_encode($customer));

        // While a mission is live, problems go through the mission, not the preparation.
        $this->operationUser('stock');
        $this->op('POST', 'orders/'.$o['order']['id'].'/preparation/anomalies', [
            'version' => $this->preparation($o['order'])['version'],
            'order_line_id' => $o['order']['lines'][0]['id'],
            'kind' => 'damaged',
            'quantity' => 1,
            'note' => 'Late anomaly (test).',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order', 'error.fields');

        $this->actAs($o['dispatcher']);
        $mission = $this->op('GET', 'missions/'.$view['id'])->json('data');
        $this->op('POST', 'missions/'.$mission['id'].'/cancel', [
            'version' => $mission['version'],
            'reason' => 'Test',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('mission', 'error.fields');
        $mission = $this->op('POST', 'missions/'.$mission['id'].'/incident-resolution', [
            'version' => $mission['version'],
            'action' => 'return_to_warehouse',
            'note' => 'Parcels checked back in at the depot (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'unassigned')
            ->assertJsonPath('data.custody', 'warehouse')
            ->assertJsonPath('data.incidents.0.resolution', 'return_to_warehouse')
            ->json('data');
        $this->op('POST', 'orders/'.$o['order']['id'].'/missions')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order', 'error.fields');
        $this->op('POST', 'missions/'.$mission['id'].'/cancel', [
            'version' => $mission['version'],
            'reason' => 'Customer asked to deliver next week (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->op('POST', 'orders/'.$o['order']['id'].'/missions')->assertCreated();
    }

    public function test_rights_unassigned_couriers_and_unready_orders(): void
    {
        $courier = $this->courier('KORA-L06');
        $other = $this->courier('KORA-L07');
        $o = $this->assigned($courier);

        // A courier who is not assigned sees nothing of the mission.
        $this->actAs($other);
        $this->courierCall('GET', 'missions/'.$o['mission']['id'])->assertNotFound();
        $this->courierCall('GET', 'missions')->assertOk()->assertJsonCount(0, 'data');
        $this->courierCall('POST', 'missions/'.$o['mission']['id'].'/pickup', [
            'version' => $o['mission']['version'],
            'packages' => 2,
        ])->assertNotFound();
        $this->getJson('/api/v1/operations/missions')->assertForbidden();

        $this->operationUser('catalogue');
        $this->op('GET', 'missions')->assertForbidden();
        $this->operationUser('stock');
        $this->op('POST', 'orders/'.$o['order']['id'].'/missions')->assertForbidden();
        // A manager follows deliveries but does not dispatch them.
        $this->operationUser('manager');
        $this->op('GET', 'missions/'.$o['mission']['id'])->assertOk();
        $this->op('POST', 'missions/'.$o['mission']['id'].'/cancel', [
            'version' => $o['mission']['version'],
            'reason' => 'Test',
        ])->assertForbidden();

        $this->actAs($o['dispatcher']);
        $this->op('GET', 'missions?status=accepted')
            ->assertOk()
            ->assertJsonPath('data.0.id', $o['mission']['id'])
            ->assertJsonMissingPath('data.0.code.value');
        $this->op('POST', 'orders/'.$o['order']['id'].'/missions')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order', 'error.fields');
        $this->op('POST', 'missions/'.$o['mission']['id'].'/assign', [
            'version' => $o['mission']['version'] + 1,
            'courier_id' => $other->id,
            'acceptance_required' => false,
        ])->assertStatus(409);

        // Only active accounts with the courier role are registered and assigned.
        $this->operationUser();
        $this->op('POST', 'couriers', [
            'user_id' => User::factory()->create(['roles' => ['client']])->id,
            'public_name' => 'Not a courier',
            'badge' => 'KORA-X',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('user_id', 'error.fields');
        $profile = $this->op('GET', 'couriers')->json('data');
        $otherProfile = collect($profile)->firstWhere('user_id', $other->id);
        $this->op('PUT', 'couriers/'.$other->id, [
            'version' => $otherProfile['version'],
            'public_name' => $otherProfile['public_name'],
            'active' => false,
        ])->assertOk();
        $this->op('POST', 'missions/'.$o['mission']['id'].'/assign', [
            'version' => $o['mission']['version'],
            'courier_id' => $other->id,
            'acceptance_required' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('courier_id', 'error.fields');

        // An order that is paid but not prepared cannot leave with a courier.
        $this->client($o['client']);
        $quote = $this->quote($this->fill([[$o['ctx']['offer'], 1]]), $this->address('Akwa'))->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => DB::table('delivery_slot_rules')->value('id'),
            'date' => now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $unready = $this->order($quote, '2000')->assertCreated()->json('data');
        $this->operationUser('dispatch');
        $this->op('POST', 'orders/'.$unready['id'].'/missions')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('order', 'error.fields');
    }

    private function missionPhoto(string $missionId, string $kind = 'handover')
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->post(
            '/api/v1/courier/missions/'.$missionId.'/photos',
            [
                'kind' => $kind,
                'note' => 'Parcel on the doorstep (test).',
                'file' => UploadedFile::fake()->createWithContent(
                    'photo.pdf',
                    "%PDF-1.4\n% Test delivery photo only.\n%%EOF",
                ),
            ],
        );
    }

    public function test_couriers_add_photos_that_only_the_delivery_team_can_read(): void
    {
        $courier = $this->courier('KORA-P01');
        $other = $this->courier('KORA-P02');
        $o = $this->assigned($courier);
        $this->actAs($courier);
        $photo = $this->missionPhoto($o['mission']['id'])
            ->assertCreated()
            ->assertJsonPath('data.kind', 'handover')
            ->json('data');
        $this->missionPhoto($o['mission']['id'], 'selfie')->assertUnprocessable();
        $this->assertSame(
            'delivery_evidence',
            DB::table('private_documents')->where('id', $photo['document_id'])->value('purpose'),
        );
        // Another courier cannot add photos to a mission that is not theirs.
        $this->actAs($other);
        $this->missionPhoto($o['mission']['id'])->assertNotFound();

        $this->actAs($o['dispatcher']);
        $this->op('GET', 'missions/'.$o['mission']['id'])
            ->assertJsonPath('data.photos.0.id', $photo['id'])
            ->assertJsonPath('data.photos.0.taken_by', $courier->id);
        $this->get('/api/v1/operations/missions/'.$o['mission']['id'].'/photos/'.$photo['id'])->assertOk();
        $this->get(
            '/api/v1/operations/missions/'.$o['mission']['id'].'/photos/'.$this->fixtureId(),
        )->assertNotFound();
        $this->operationUser('catalogue');
        $this->get('/api/v1/operations/missions/'.$o['mission']['id'].'/photos/'.$photo['id'])->assertForbidden();

        // After delivery, the record is closed: no more photos.
        $this->actAs($courier);
        $view = $this->courierCall('POST', 'missions/'.$o['mission']['id'].'/pickup', [
            'version' => $o['mission']['version'],
            'packages' => 2,
        ])->json('data');
        $code = $this->code($o);
        $this->actAs($courier);
        $this->courierCall('POST', 'missions/'.$view['id'].'/handover', $this->control($view, $code))->assertOk();
        $this->missionPhoto($o['mission']['id'])->assertUnprocessable();
        try {
            DB::transaction(fn () => DB::table('delivery_mission_photos')->delete());
            $this->fail('A mission photo was deleted.');
        } catch (QueryException) {
            $this->assertSame(1, DB::table('delivery_mission_photos')->count());
        }
    }

    public function test_the_customer_checks_the_courier_with_a_qr_code_that_expires_with_the_assignment(): void
    {
        $courier = $this->courier('KORA-Q01');
        $second = $this->courier('KORA-Q02');
        $o = $this->assigned($courier);
        $this->actAs($courier);
        $verification = $this->courierCall('GET', 'missions/'.$o['mission']['id'])->json('data.verification');
        $this->assertSame($o['mission']['number'], $verification['reference']);
        $this->assertStringContainsString('/fr/verifier-livreur?reference=', $verification['url']);

        // Anyone holding the QR code sees the public identity only, without signing in.
        $this->app['auth']->forgetGuards();
        $check = fn (string $token) => $this->getJson(
            '/api/v1/courier-verifications?'.
                http_build_query([
                    'reference' => $verification['reference'],
                    'token' => $token,
                ]),
        );
        $public = $check($verification['token'])
            ->assertOk()
            ->assertJsonPath('data.badge', 'KORA-Q01')
            ->assertJsonPath('data.mission_reference', $o['mission']['number'])
            ->json('data');
        $this->assertSame(['valid', 'mission_reference', 'public_name', 'badge'], array_keys($public));
        $this->assertStringNotContainsString($courier->email, json_encode($public));
        $check(str_repeat('0', 32))->assertNotFound();

        // Suspension and reassignment revoke the code at once.
        $this->operationUser();
        $profile = collect($this->op('GET', 'couriers')->json('data'))->firstWhere('user_id', $courier->id);
        $this->op('PUT', 'couriers/'.$courier->id, [
            'version' => $profile['version'],
            'public_name' => $profile['public_name'],
            'active' => false,
        ])->assertOk();
        $check($verification['token'])->assertNotFound();
        $this->op('PUT', 'couriers/'.$courier->id, [
            'version' => $profile['version'] + 1,
            'public_name' => $profile['public_name'],
            'active' => true,
        ])->assertOk();
        $check($verification['token'])->assertOk();
        $this->actAs($o['dispatcher']);
        $mission = $this->op('GET', 'missions/'.$o['mission']['id'])->json('data');
        $this->op('POST', 'missions/'.$mission['id'].'/assign', [
            'version' => $mission['version'],
            'courier_id' => $second->id,
            'acceptance_required' => false,
        ])->assertOk();
        $check($verification['token'])->assertNotFound();
        $this->actAs($second);
        $fresh = $this->courierCall('GET', 'missions/'.$o['mission']['id'])->json('data.verification');
        $this->assertNotSame($verification['token'], $fresh['token']);
        $this->getJson(
            '/api/v1/courier-verifications?'.
                http_build_query(['reference' => $fresh['reference'], 'token' => $fresh['token']]),
        )
            ->assertOk()
            ->assertJsonPath('data.badge', 'KORA-Q02');
    }

    public function test_mission_records_are_protected_in_the_database(): void
    {
        $courier = $this->courier('KORA-L08');
        $o = $this->assigned($courier);
        $mission = DB::table('delivery_missions')->first();
        foreach (
            [
                // A second live mission for the same parcels.
                fn () => DB::table('delivery_missions')->insert([
                    ...(array) $mission,
                    'id' => $this->fixtureId(),
                    'number' => 'MIS-TEST',
                ]),
                fn () => DB::table('delivery_missions')->where('id', $mission->id)->delete(),
                // Delivered without the handover date and method.
                fn () => DB::table('delivery_missions')
                    ->where('id', $mission->id)
                    ->update(['status' => 'delivered', 'version' => $mission->version + 1]),
                // Version must move forward with any change.
                fn () => DB::table('delivery_missions')
                    ->where('id', $mission->id)
                    ->update(['sequence' => 9]),
                fn () => DB::table('delivery_mission_events')->delete(),
            ] as $write
        ) {
            try {
                DB::transaction($write);
                $this->fail('A mission record was rewritten.');
            } catch (QueryException) {
                $this->assertSame(1, DB::table('delivery_missions')->count());
            }
        }
        $this->assertSame('accepted', DB::table('delivery_missions')->value('status'));
        $this->assertNotNull($o['mission']['number']);
    }
}
