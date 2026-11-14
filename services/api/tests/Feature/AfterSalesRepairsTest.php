<?php

namespace Tests\Feature;

use App\Domain\Suppliers\SupplierWorkspace;
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

final class AfterSalesRepairsTest extends TestCase
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

    /** A delivered depot order with an accepted case of the given remedy, and its unit received back. */
    private function received(string $resolution): array
    {
        $o = $this->warehouseOrder(2);
        $this->readyOrder($o['order'], 1);
        $courier = User::factory()->create(['roles' => ['courier']]);
        $this->operationUser();
        $this->op('POST', 'couriers', [
            'user_id' => $courier->id,
            'public_name' => 'Test',
            'badge' => 'KORA-R01',
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
        $view = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/courier/missions/'.$mission['id'].'/pickup', [
                'version' => $mission['version'],
                'packages' => 1,
            ])
            ->json('data');
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/courier/missions/'.$mission['id'].'/handover', [
                'version' => $view['version'],
                'code' => $code,
                'packages' => 1,
                'items' => [['order_line_id' => $o['order']['lines'][0]['id'], 'quantity' => 2, 'identifiers' => []]],
            ])
            ->assertOk();
        $this->client($o['client']);
        $case = $this->customer('POST', 'after-sales', [
            'order_line_id' => $o['order']['lines'][0]['id'],
            'kind' => 'breakdown',
            'quantity' => 1,
            'identifiers' => [],
            'description' => 'Stopped working (test).',
        ])->json('data');
        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$case['id'].'/proposal', [
            'version' => $case['version'],
            'resolution' => $resolution,
            'responsibility' => 'supplier',
            'responsibility_note' => 'Manufacturing fault (test).',
            'message' => 'Proposal (test).',
        ])->assertOk();
        $this->client($o['client']);
        $this->customer('POST', 'after-sales/'.$case['id'].'/decision', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'accept' => true,
        ])->assertOk();
        $this->operationUser('stock');
        $staff = $this->op('POST', 'after-sales/'.$case['id'].'/returns', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'location_id' => $o['location']['id'],
            'quantity' => 1,
            'identifiers' => [],
        ])
            ->assertCreated()
            ->json('data');

        return [...$o, 'case' => $case, 'return' => $staff['returns'][0], 'courier' => $courier];
    }

    public function test_the_customers_unit_is_repaired_tracked_and_brought_back_before_the_case_closes(): void
    {
        $r = $this->received('repair');
        $supplier = $r['ctx']['supplier'];

        // The customer's unit is not controlled for resale, nor sent back as a defective KORA unit.
        $this->op('POST', 'after-sales-returns/'.$r['return']['id'].'/control', [
            'version' => $r['return']['version'],
            'result' => 'resellable',
            'note' => 'x',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('control', 'error.fields');
        $this->operationUser();
        $this->op('POST', 'after-sales-returns/'.$r['return']['id'].'/supplier-return', [
            'balance_id' => $r['return']['balance_id'],
            'quantity' => 1,
            'reason' => 'x',
            'supplier_reference' => 'x',
            'document_id' => $this->evidence($supplier, 'stock_evidence')['id'],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('return', 'error.fields');

        $this->op('POST', 'after-sales/'.$r['case']['id'].'/repairs', [
            'return_id' => $r['return']['id'],
            'repairer_kind' => 'supplier',
        ])->assertUnprocessable();
        $repair = $this->op('POST', 'after-sales/'.$r['case']['id'].'/repairs', [
            'return_id' => $r['return']['id'],
            'repairer_kind' => 'supplier',
            'supplier_id' => $supplier,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->json('data');
        $this->assertStringStartsWith('REP-', $repair['number']);

        // Handed to the supplier with a slip; the unit leaves the depot shelf for the "at the repairer" location.
        $away = $this->op('POST', 'inventory/locations', [
            'warehouse_id' => $r['location']['warehouse_id'],
            'code' => 'REPAIR',
            'name' => 'Chez le réparateur (test)',
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'after-sales-repairs/'.$repair['id'].'/send', [
            'version' => $repair['version'],
            'location_id' => $away['id'],
            'document_id' => $this->fixtureId(),
        ])->assertNotFound();
        $slip = $this->evidence($supplier, 'stock_evidence');
        $repair = $this->op('POST', 'after-sales-repairs/'.$repair['id'].'/send', [
            'version' => $repair['version'],
            'location_id' => $away['id'],
            'document_id' => $slip['id'],
            'reference' => 'RMA-TEST-1',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'at_repairer')
            ->json('data');
        $this->assertSame(1, (int) DB::table('inventory_balances')->where('location_id', $away['id'])->sum('on_hand'));
        $this->op('POST', 'after-sales/'.$r['case']['id'].'/repair-returns', [
            'repair_id' => $repair['id'],
            'warehouse_id' => $r['location']['warehouse_id'],
            'packages' => 1,
        ])->assertUnprocessable();

        // The supplier sees the repair in its warranties, with serials and no customer.
        $warranties = app(SupplierWorkspace::class)->warranties($supplier);
        $this->assertSame(
            [$repair['number'], 'at_repairer'],
            [$warranties['repairs'][0]['number'], $warranties['repairs'][0]['status']],
        );

        // Back with its slip, outcome and who bears the cost; the case still cannot close.
        $this->op('POST', 'after-sales-repairs/'.$repair['id'].'/back', [
            'version' => $repair['version'],
            'location_id' => $r['location']['id'],
            'outcome' => 'repaired',
            'note' => 'Board replaced (test).',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('document_id', 'error.fields');
        $repair = $this->op('POST', 'after-sales-repairs/'.$repair['id'].'/back', [
            'version' => $repair['version'],
            'location_id' => $r['location']['id'],
            'outcome' => 'repaired',
            'note' => 'Board replaced under warranty (test).',
            'document_id' => $this->evidence($supplier, 'stock_evidence')['id'],
            'cost' => 0,
            'cost_bearer' => 'supplier',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'back')
            ->json('data');
        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$r['case']['id'].'/resolve', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'message' => 'x',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('case', 'error.fields');

        // Brought back by a courier: the same unit leaves stock to its owner, handed over with a code.
        $this->operationUser();
        $issued = DB::table('inventory_movements')->where('kind', 'repair_return')->count();
        $trip = $this->op('POST', 'after-sales/'.$r['case']['id'].'/repair-returns', [
            'repair_id' => $repair['id'],
            'warehouse_id' => $r['location']['warehouse_id'],
            'packages' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.direction', 'delivery')
            ->json('data');
        $this->assertSame($issued + 1, DB::table('inventory_movements')->where('kind', 'repair_return')->count());
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('condition', 'returned')->sum('on_hand'));
        $this->assertSame('returning', DB::table('after_sales_repairs')->value('status'));
        $this->op('POST', 'after-sales-trips/'.$trip['id'].'/assign', [
            'version' => $trip['version'],
            'courier_id' => $r['courier']->id,
        ])->assertOk();
        $this->client($r['client']);
        $view = $this->customer('GET', 'after-sales/'.$r['case']['id'])->json('data');
        $code = collect($view['trips'])->firstWhere('direction', 'delivery')['handover_code'];
        $this->assertSame(
            ['number' => $repair['number'], 'status' => 'returning', 'outcome' => 'repaired', 'returned_at' => null],
            $view['repairs'][0],
        );
        $this->actAs($r['courier']);
        $version = fn () => (int) DB::table('after_sales_trips')->where('id', $trip['id'])->value('version');
        $courier = fn (string $action, array $data) => $this->withHeader(
            'Idempotency-Key',
            (string) Str::uuid(),
        )->postJson('/api/v1/courier/trips/'.$trip['id'].'/'.$action, $data);
        $courier('take', ['version' => $version(), 'packages' => 1])->assertOk();
        $courier('handover', ['version' => $version(), 'code' => $code])
            ->assertOk()
            ->assertJsonPath('data.status', 'completed');
        $this->assertSame('returned', DB::table('after_sales_repairs')->value('status'));

        $this->operationUser('support');
        $this->op('POST', 'after-sales/'.$r['case']['id'].'/resolve', [
            'version' => DB::table('after_sales_cases')->value('version'),
            'message' => 'Your repaired item is back.',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved');
        try {
            DB::transaction(
                fn () => DB::table('after_sales_repairs')->update(['outcome' => 'not_repairable', 'version' => 99]),
            );
            $this->fail('A returned repair stays as recorded.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('AFTER_SALES_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_a_defective_returned_unit_goes_back_to_its_supplier_with_the_answer_recorded(): void
    {
        $r = $this->received('exchange');
        $supplier = $r['ctx']['supplier'];
        $this->op('POST', 'after-sales-returns/'.$r['return']['id'].'/control', [
            'version' => $r['return']['version'],
            'result' => 'defective',
            'note' => 'Does not power on (test).',
        ])->assertOk();
        $damaged = DB::table('inventory_balances')->where('condition', 'damaged')->first();
        $available = DB::table('inventory_balances')->where('condition', 'available')->first();
        $this->operationUser();
        $slip = $this->evidence($supplier, 'stock_evidence');
        $body = [
            'quantity' => 1,
            'reason' => 'Warranty return (test).',
            'supplier_reference' => 'RMA-TEST-2',
            'document_id' => $slip['id'],
        ];

        // Only the defective units of this return, never saleable stock.
        $this->op('POST', 'after-sales-returns/'.$r['return']['id'].'/supplier-return', [
            ...$body,
            'balance_id' => $available->id,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('balance_id', 'error.fields');
        $sent = $this->op('POST', 'after-sales-returns/'.$r['return']['id'].'/supplier-return', [
            ...$body,
            'balance_id' => $damaged->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.decision', 'pending')
            ->assertJsonPath('data.supplier_reference', 'RMA-TEST-2')
            ->json('data');
        $this->assertSame(0, (int) DB::table('inventory_balances')->where('id', $damaged->id)->value('on_hand'));
        $this->assertSame(1, DB::table('inventory_movements')->where('kind', 'supplier_return')->count());

        // The supplier's answer, recorded once with a reason; a credit, if any, comes through payables.
        $this->op('POST', 'after-sales-supplier-returns/'.$sent['id'].'/decision', [
            'version' => $sent['version'],
            'decision' => 'credit',
        ])->assertUnprocessable();
        $decided = $this->op('POST', 'after-sales-supplier-returns/'.$sent['id'].'/decision', [
            'version' => $sent['version'],
            'decision' => 'credit',
            'note' => 'Supplier grants a credit note (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.decision', 'credit')
            ->assertJsonPath('data.credit', null)
            ->json('data');
        $this->op('POST', 'after-sales-supplier-returns/'.$sent['id'].'/decision', [
            'version' => $decided['version'],
            'decision' => 'refused',
            'note' => 'x',
        ])->assertUnprocessable();
        $staff = $this->op('GET', 'after-sales/'.$r['case']['id'])->json('data');
        $this->assertSame('credit', $staff['supplier_returns'][0]['decision']);
        $this->assertSame('credit', app(SupplierWorkspace::class)->warranties($supplier)['returns'][0]['decision']);
        $this->operationUser('support');
        $this->op('POST', 'after-sales-supplier-returns/'.$sent['id'].'/decision', [
            'version' => $decided['version'],
            'decision' => 'refused',
            'note' => 'x',
        ])->assertForbidden();
    }
}
