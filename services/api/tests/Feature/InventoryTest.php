<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class InventoryTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    public function test_reservations_require_an_explicit_policy_and_cannot_oversell_or_repeat_a_preparation(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 100);
        $data = ['offer_id' => $ctx['offer'], 'purpose_id' => $this->fixtureId(), 'quantity' => 70];
        $this->op('POST', 'inventory/reservations', $data)->assertUnprocessable();
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->inventoryPolicy();
        $key = $this->fixtureId();
        $first = $this->op('POST', 'inventory/reservations', $data, $key)->assertCreated()->json('data');
        $this->op('POST', 'inventory/reservations', $data, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $first['id']);
        $this->op('POST', 'inventory/reservations', $data)->assertUnprocessable();
        $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 31,
        ])->assertUnprocessable();
        $this->op('GET', 'inventory/balances')
            ->assertJsonPath('data.0.reserved', 70)
            ->assertJsonPath('data.0.available', 30);
        $this->assertDatabaseCount('inventory_reservations', 1);
    }

    public function test_expired_holds_are_released_once_by_the_scheduler_and_never_issued_silently(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $this->receipt($ctx, $order, $this->location(), 100);
        $this->inventoryPolicy();
        $r = $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 100,
        ])
            ->assertCreated()
            ->json('data');
        $proof = $this->evidence($ctx['supplier'], 'stock_evidence');
        $this->travel(11)->minutes();
        $this->op('POST', 'inventory/reservations/'.$r['id'].'/issue', [
            'sale_reference' => 'TEST',
            'sale_verified' => true,
            'document_id' => $proof['id'],
        ])->assertUnprocessable();
        $this->artisan('inventory:expire')->assertSuccessful();
        $this->artisan('inventory:expire')->assertSuccessful();
        $this->op('GET', 'inventory/balances')
            ->assertJsonPath('data.0.reserved', 0)
            ->assertJsonPath('data.0.on_hand', 100);
        $this->assertSame(
            1,
            DB::table('audit_events')->where('action', 'inventory.expired')->whereNull('actor_id')->count(),
        );
        $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 100,
        ])->assertCreated();
    }

    public function test_old_stock_is_not_substituted_when_warranty_or_selected_offer_changes(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $this->receipt($ctx, $order, $this->location(), 100);
        $this->inventoryPolicy();
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['warranty' => json_encode(['fr' => 'Autre promesse test', 'en' => 'Different test promise'])]);
        $this->op('GET', 'inventory/offers/'.$ctx['offer'].'/plan?quantity=10')
            ->assertJsonPath('data.warehouse_sources', [])
            ->assertJsonPath('data.supplier_quantity_needed', 10)
            ->assertJsonPath('data.supplier_confirmation_required', true);
        $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 1,
        ])->assertUnprocessable();
        $other = (array) DB::table('offers')->find($ctx['offer']);
        $other['id'] = $this->fixtureId();
        DB::table('offers')->insert($other);
        $this->op('GET', 'inventory/offers/'.$other['id'].'/plan?quantity=1')->assertJsonPath(
            'data.warehouse_sources',
            [],
        );
        $this->assertSame(100, (int) DB::table('inventory_balances')->sum('on_hand'));
    }

    public function test_serial_units_are_unique_and_transfers_require_the_actual_unit_ids(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue('serial');
        $order = $this->purchase($ctx, $this->contract($ctx), 4);
        $location = $this->location();
        $receipt = $this->receipt($ctx, $order, $location, 2, ['identifiers' => ['TEST-SERIAL-1', 'TEST-SERIAL-2']]);
        $units = $this->op('GET', 'inventory/units?lot_id='.$receipt['lot_ids'][0])
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $units);
        $doc = $this->evidence($ctx['supplier'], 'stock_evidence');
        $data = [
            'location_id' => $location['id'],
            'document_id' => $doc['id'],
            'reference' => 'DUPLICATE TEST',
            'received_at' => now()->subSecond()->toIso8601String(),
            'lines' => [
                [
                    'purchase_line_id' => $order['lines'][0]['id'],
                    'quantity' => 2,
                    'quality' => 'available',
                    'identifiers' => ['TEST-SERIAL-1', 'TEST-SERIAL-3'],
                ],
            ],
        ];
        $this->op('POST', 'purchases/'.$order['id'].'/receipts', $data)->assertConflict();
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseCount('inventory_units', 2);
        $balance = $this->op('GET', 'inventory/balances')->json('data.0');
        $target = $this->location();
        $move = [
            'balance_id' => $balance['id'],
            'location_id' => $target['id'],
            'condition' => 'available',
            'quantity' => 1,
            'unit_ids' => [],
            'reason' => 'Test physical transfer',
            'document_id' => $doc['id'],
        ];
        $this->op('POST', 'inventory/transfers', $move)->assertUnprocessable();
        $move['unit_ids'] = [$units[0]['id']];
        $result = $this->op('POST', 'inventory/transfers', $move)->assertOk()->json('data');
        $this->assertSame(1, $result['source']['on_hand']);
        $this->assertSame(1, $result['target']['on_hand']);
        $this->assertSame($result['target']['id'], DB::table('inventory_units')->find($units[0]['id'])->balance_id);
    }

    public function test_lots_expiry_and_excess_quantities_stay_traceable_in_quarantine(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue('lot', true);
        $order = $this->purchase($ctx, $this->contract($ctx), 5);
        $location = $this->location();
        $receipt = $this->receipt($ctx, $order, $location, 7, [
            'lot_number' => 'TEST LOT',
            'expires_on' => now('Africa/Douala')->subDay()->toDateString(),
            'quality' => 'quarantine',
            'variance_reason' => 'Two excess units, expired at inspection',
        ]);
        $this->op('GET', 'inventory/balances')
            ->assertJsonPath('data.0.on_hand', 7)
            ->assertJsonPath('data.0.available', 0);
        $this->assertSame(2, (int) DB::table('receipt_lines')->value('excess_quantity'));
        $this->assertSame(7, (int) DB::table('purchase_lines')->value('received_quantity'));
        $this->assertDatabaseCount('supplier_obligations', 0);
        $doc = $this->evidence($ctx['supplier'], 'stock_evidence');
        $balance = $this->op('GET', 'inventory/balances')->json('data.0');
        $this->op('POST', 'inventory/transfers', [
            'balance_id' => $balance['id'],
            'location_id' => $location['id'],
            'condition' => 'available',
            'quantity' => 1,
            'unit_ids' => [],
            'reason' => 'Invalid test release',
            'document_id' => $doc['id'],
        ])->assertUnprocessable();
    }

    public function test_transfers_and_corrections_cannot_consume_reserved_units(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 10);
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 10);
        $this->inventoryPolicy();
        $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 8,
        ])->assertCreated();
        $balance = $this->op('GET', 'inventory/balances')->json('data.0');
        $doc = $this->evidence($ctx['supplier'], 'stock_evidence');
        $target = $this->location();
        $this->op('POST', 'inventory/adjustments', [
            'balance_id' => $balance['id'],
            'delta' => -3,
            'reason' => 'Invalid test count',
            'document_id' => $doc['id'],
        ])->assertUnprocessable();
        $this->op('POST', 'inventory/transfers', [
            'balance_id' => $balance['id'],
            'location_id' => $target['id'],
            'condition' => 'quarantine',
            'quantity' => 3,
            'unit_ids' => [],
            'reason' => 'Invalid test move',
            'document_id' => $doc['id'],
        ])->assertUnprocessable();
        $this->op('POST', 'inventory/adjustments', [
            'balance_id' => $balance['id'],
            'delta' => -2,
            'reason' => 'Test documented count difference',
            'document_id' => $doc['id'],
        ])
            ->assertOk()
            ->assertJsonPath('data.on_hand', 8)
            ->assertJsonPath('data.reserved', 8);
        $this->assertDatabaseCount('inventory_movements', 2);
    }

    public function test_external_reservation_is_not_guaranteed_until_confirmed_and_release_needs_external_confirmation(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $data = [
            'quantity' => 10,
            'verified_at' => now()->subSecond()->toIso8601String(),
            'valid_until' => now()->addHour()->toIso8601String(),
            'preparation_minutes' => 30,
            'contact' => 'Test contact',
            'reference' => 'TEST REPORT',
        ];
        $this->op('PUT', 'supplier-availability/'.$ctx['offer'], $data)->assertOk();
        $reservation = $this->op('POST', 'supplier-reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 4,
            'expires_at' => now()->addMinutes(10)->toIso8601String(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.supplier_confirmed', false)
            ->json('data');
        $this->op('GET', 'inventory/offers/'.$ctx['offer'].'/plan?quantity=4')
            ->assertJsonPath('data.supplier_quantity_reported', 6)
            ->assertJsonPath('data.supplier_confirmation_required', true);
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm', [
            'reference' => 'ACTUAL CONFIRMATION TEST',
        ])
            ->assertOk()
            ->assertJsonPath('data.supplier_confirmed', true);
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/request-release', [
            'reference' => 'INTERNAL REQUEST TEST',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'release_requested');
        $this->op('GET', 'inventory/offers/'.$ctx['offer'].'/plan?quantity=4')->assertJsonPath(
            'data.supplier_quantity_reported',
            6,
        );
        $this->op('POST', 'supplier-reservations/'.$reservation['id'].'/confirm-release', [
            'reference' => 'ACTUAL RELEASE TEST',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'released');
        $this->op('GET', 'inventory/offers/'.$ctx['offer'].'/plan?quantity=4')->assertJsonPath(
            'data.supplier_quantity_reported',
            10,
        );
        $data['version'] = 1;
        $data['quantity'] = null;
        $this->op('PUT', 'supplier-availability/'.$ctx['offer'], $data)
            ->assertOk()
            ->assertJsonPath('data.quantity', null);
        $this->op('GET', 'inventory/offers/'.$ctx['offer'].'/plan?quantity=4')->assertJsonPath(
            'data.supplier_quantity_reported',
            null,
        );
    }

    public function test_stock_staff_can_receive_without_reading_or_overriding_negotiated_costs(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 5);
        $location = $this->location();
        $this->operationUser('stock');
        $this->op('GET', 'inventory/receivable-purchases')
            ->assertJsonMissingPath('data.0.expected_total')
            ->assertJsonMissingPath('data.0.lines.0.unit_cost');
        $this->op('GET', 'purchases/'.$order['id'])->assertForbidden();
        $doc = $this->evidence($ctx['supplier'], 'stock_evidence');
        $data = [
            'location_id' => $location['id'],
            'document_id' => $doc['id'],
            'reference' => 'TEST RECEIPT',
            'received_at' => now()->subSecond()->toIso8601String(),
            'lines' => [
                [
                    'purchase_line_id' => $order['lines'][0]['id'],
                    'quantity' => 5,
                    'quality' => 'available',
                    'identifiers' => [],
                    'unit_cost' => 1,
                    'variance_reason' => 'Unauthorized test price',
                ],
            ],
        ];
        $this->op('POST', 'purchases/'.$order['id'].'/receipts', $data)->assertForbidden();
        unset($data['lines'][0]['unit_cost'], $data['lines'][0]['variance_reason']);
        $this->op('POST', 'purchases/'.$order['id'].'/receipts', $data)
            ->assertCreated()
            ->assertJsonMissingPath('data.unit_cost');
        $this->op('GET', 'purchases/'.$order['id'].'/receipts')
            ->assertOk()
            ->assertJsonMissingPath('data.0.lines.0.unit_cost')
            ->assertJsonPath('data.0.lines.0.quantity', 5);
        $this->assertSame(1000, (int) DB::table('stock_lots')->value('unit_cost'));
    }

    public function test_imei_is_fifteen_digits_and_unique_even_across_different_variants_and_suppliers(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue('imei');
        $order = $this->purchase($ctx, $this->contract($ctx), 1);
        $this->receipt($ctx, $order, $this->location(), 1, ['identifiers' => ['123456789012345']]);
        $other = $this->fixtureCatalogue('imei');
        $order2 = $this->purchase($other, $this->contract($other), 1);
        $location = $this->location();
        $doc = $this->evidence($other['supplier'], 'stock_evidence');
        $data = [
            'location_id' => $location['id'],
            'document_id' => $doc['id'],
            'reference' => 'TEST IMEI',
            'received_at' => now()->subSecond()->toIso8601String(),
            'lines' => [
                [
                    'purchase_line_id' => $order2['lines'][0]['id'],
                    'quantity' => 1,
                    'quality' => 'available',
                    'identifiers' => ['123'],
                ],
            ],
        ];
        $this->op('POST', 'purchases/'.$order2['id'].'/receipts', $data)->assertUnprocessable();
        $data['lines'][0]['identifiers'] = ['123456789012345'];
        $this->op('POST', 'purchases/'.$order2['id'].'/receipts', $data)->assertConflict();
        $this->assertDatabaseCount('inventory_units', 1);
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertSame(
            0,
            (int) DB::table('purchase_lines')->where('id', $order2['lines'][0]['id'])->value('received_quantity'),
        );
    }
}
