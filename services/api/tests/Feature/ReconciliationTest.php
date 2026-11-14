<?php

namespace Tests\Feature;

use App\Domain\Procurement\Payables;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class ReconciliationTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    public function test_invoice_registration_can_be_corrected_and_cancelled_without_rewriting_approved_debt(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 10);
        $invoice = $this->invoice($ctx, $order, 8, false);
        $data = [
            'purchase_order_id' => $order['id'],
            'number' => $invoice['number'],
            'issued_on' => $invoice['issued_on'],
            'document_id' => $invoice['document_id'],
            'lines' => [['purchase_line_id' => $order['lines'][0]['id'], 'quantity' => 6, 'unit_payable' => 1000]],
            'charges' => [],
            'version' => 1,
            'correction_reason' => 'Test correction of transcription',
        ];
        $this->op('PUT', 'purchase-invoices/'.$invoice['id'], $data)
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.amount', '6000');
        $this->assertDatabaseCount('supplier_obligations', 0);
        $this->assertSame(6, (int) DB::table('purchase_lines')->value('invoiced_quantity'));
        $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/approve', ['version' => 1])->assertConflict();
        $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/cancel-registration', [
            'version' => 2,
            'reason' => 'Test duplicate registration',
        ])
            ->assertOk()
            ->assertJsonPath('data.version', 3);
        $this->assertSame(0, (int) DB::table('purchase_lines')->value('invoiced_quantity'));
        $data['version'] = 3;
        $this->op('PUT', 'purchase-invoices/'.$invoice['id'], $data)
            ->assertOk()
            ->assertJsonPath('data.version', 4);
        $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/approve', ['version' => 4])
            ->assertOk()
            ->assertJsonPath('data.obligation.amount', '6000');
        $data['version'] = 5;
        $this->op('PUT', 'purchase-invoices/'.$invoice['id'], $data)->assertUnprocessable();
        $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/cancel-registration', [
            'version' => 5,
            'reason' => 'Invalid test',
        ])->assertUnprocessable();
        $this->op('GET', 'purchase-invoices/'.$invoice['id'].'/revisions')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('data.4.snapshot.invoice.amount', '8000')
            ->assertJsonPath('data.4.snapshot.invoice.version', 1);
        $this->assertDatabaseCount('supplier_obligations', 1);
    }

    public function test_excess_stock_requires_a_documented_amendment_before_release_or_invoicing_and_counts_in_commitment(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 5);
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 7, [
            'quality' => 'quarantine',
            'unit_cost' => 1200,
            'variance_reason' => 'Test excess and actual agreed price',
        ]);
        $balance = $this->op('GET', 'inventory/balances')->json('data.0');
        $proof = $this->evidence($ctx['supplier'], 'stock_evidence');
        $move = [
            'balance_id' => $balance['id'],
            'location_id' => $location['id'],
            'condition' => 'available',
            'quantity' => 7,
            'unit_ids' => [],
            'reason' => 'Test inspected stock',
            'document_id' => $proof['id'],
        ];
        $this->op('POST', 'inventory/transfers', $move)->assertUnprocessable();
        $invoiceDoc = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $bill = [
            'purchase_order_id' => $order['id'],
            'number' => 'TEST EXCESS',
            'issued_on' => now('Africa/Douala')->toDateString(),
            'document_id' => $invoiceDoc['id'],
            'lines' => [
                [
                    'purchase_line_id' => $order['lines'][0]['id'],
                    'quantity' => 7,
                    'unit_payable' => 1200,
                    'variance_reason' => 'Test agreed actual price',
                ],
            ],
            'charges' => [],
        ];
        $this->op('POST', 'purchase-invoices', $bill)->assertUnprocessable();
        $amendment = $this->evidence($ctx['supplier'], 'supplier_contract');
        $receiptLine = $this->op('GET', 'purchases/'.$order['id'].'/receipts')
            ->assertOk()
            ->assertJsonPath('data.0.lines.0.excess_quantity', 2)
            ->json('data.0.lines.0.id');
        $current = $this->op('GET', 'purchases/'.$order['id'])->json('data');
        $accept = [
            'version' => $current['version'],
            'document_id' => $amendment['id'],
            'reason' => 'Test signed excess amendment',
        ];
        $this->op('POST', 'receipt-lines/'.$receiptLine.'/approve-overage', $accept)
            ->assertOk()
            ->assertJsonPath('data.approved_quantity', 2)
            ->assertJsonPath('data.purchase_order.lines.0.quantity', 5)
            ->assertJsonPath('data.purchase_order.lines.0.approved_overage_quantity', 2);
        $this->assertSame(7400, app(Payables::class)->commitment($ctx['supplier']));
        $this->op('POST', 'inventory/transfers', $move)->assertOk()->assertJsonPath('data.target.available', 7);
        $invoice = $this->op('POST', 'purchase-invoices', $bill)->assertCreated()->json('data');
        $this->assertSame(8400, app(Payables::class)->commitment($ctx['supplier']));
        $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/approve', ['version' => 1])->assertOk();
        $this->assertSame(8400, app(Payables::class)->commitment($ctx['supplier']));
        $this->assertDatabaseCount('receipt_overage_approvals', 1);
    }

    public function test_serial_count_preserves_holds_and_only_recovers_known_missing_units(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue('serial');
        $order = $this->purchase($ctx, $this->contract($ctx), 3);
        $this->receipt($ctx, $order, $this->location(), 3, ['identifiers' => ['COUNT-A', 'COUNT-B', 'COUNT-C']]);
        $this->inventoryPolicy();
        $hold = $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 1,
        ])
            ->assertCreated()
            ->json('data');
        $reserved = DB::table('inventory_units')->where('status', 'reserved')->value('id');
        $others = DB::table('inventory_units')->where('status', 'on_hand')->orderBy('id')->pluck('id')->all();
        $balance = DB::table('inventory_balances')->value('id');
        $doc = $this->evidence($ctx['supplier'], 'stock_evidence');
        $count = [
            'balance_id' => $balance,
            'counted_quantity' => 2,
            'observed_unit_ids' => $others,
            'reason' => 'Test physical count',
            'document_id' => $doc['id'],
        ];
        $this->op('POST', 'inventory/counts', $count)->assertUnprocessable();
        $count['observed_unit_ids'] = [$reserved, $others[0]];
        $this->op('POST', 'inventory/counts', $count)
            ->assertOk()
            ->assertJsonPath('data.on_hand', 2)
            ->assertJsonPath('data.reserved', 1);
        $this->assertDatabaseHas('inventory_units', ['id' => $others[1], 'status' => 'missing', 'balance_id' => null]);
        $count['counted_quantity'] = 3;
        $count['observed_unit_ids'][] = $others[1];
        $this->op('POST', 'inventory/counts', $count)->assertOk()->assertJsonPath('data.on_hand', 3);
        $this->op('POST', 'inventory/reservations/'.$hold['id'].'/issue', [
            'sale_reference' => 'TEST SALE',
            'sale_verified' => true,
            'document_id' => $doc['id'],
        ])->assertOk();
        $this->op('POST', 'inventory/counts', $count)->assertUnprocessable();
        $this->assertDatabaseHas('inventory_units', ['id' => $reserved, 'status' => 'consumed', 'balance_id' => null]);
        $this->assertSame(2, (int) DB::table('inventory_balances')->value('on_hand'));
        $this->assertSame(2, DB::table('inventory_movements')->where('kind', 'inventory_count')->count());
    }

    public function test_unapplied_credit_can_settle_later_debt_without_changing_the_past_or_another_supplier(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 10);
        $invoice = $this->invoice($ctx, $order, 10);
        $payment = $this->payment($ctx, $this->account(), '10000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '10000']]);
        $doc = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $credit = $this->op('POST', 'supplier-credits', [
            'invoice_id' => $invoice['invoice']['id'],
            'amount' => '4000',
            'document_id' => $doc['id'],
            'reference' => 'TEST CREDIT',
            'reason' => 'Test price credit after full payment',
        ])
            ->assertCreated()
            ->assertJsonPath('data.unapplied', '4000')
            ->json('data');
        $this->travel(1)->seconds();
        $past = now()->toIso8601String();
        $this->travel(1)->seconds();
        $order2 = $this->purchase($ctx, $this->contract($ctx), 5);
        $invoice2 = $this->invoice($ctx, $order2, 5);
        $data = ['allocations' => [['obligation_id' => $invoice2['obligation']['id'], 'amount' => '2500']]];
        $key = $this->fixtureId();
        $this->op('POST', 'supplier-credits/'.$credit['id'].'/allocate', $data, $key)
            ->assertOk()
            ->assertJsonPath('data.unapplied', '1500');
        $this->op('POST', 'supplier-credits/'.$credit['id'].'/allocate', $data, $key)
            ->assertOk()
            ->assertJsonPath('data.unapplied', '1500');
        $data['allocations'][0]['amount'] = '1500';
        $this->op('POST', 'supplier-credits/'.$credit['id'].'/allocate', $data)
            ->assertOk()
            ->assertJsonPath('data.unapplied', '0');
        $this->assertDatabaseCount('supplier_credit_allocations', 2);
        $this->op('GET', 'supplier-credits?supplier_id='.$ctx['supplier'])
            ->assertOk()
            ->assertJsonPath('data.0.unapplied', '0')
            ->assertJsonPath('meta.total', 1);
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')->assertJsonPath(
            'data.balances.outstanding_total',
            '1000',
        );
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement?as_of='.urlencode($past))->assertJsonPath(
            'data.balances.unapplied_credit_total',
            '4000',
        );
        $this->op('POST', 'supplier-credits/'.$credit['id'].'/allocate', $data)->assertUnprocessable();
    }

    public function test_filtered_schedule_reports_remaining_amount_after_covering_earlier_installments(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', 'invoice_date', [
            'schedule' => [['basis_points' => 5000, 'offset_days' => 0], ['basis_points' => 5000, 'offset_days' => 30]],
        ]);
        $order = $this->purchase($ctx, $contract, 10);
        $invoice = $this->invoice($ctx, $order, 10);
        $payment = $this->payment($ctx, $this->account(), '6000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '6000']]);
        $this->op('GET', 'payables/schedule?from='.now('Africa/Douala')->addDay()->toDateString())
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.amount', '5000')
            ->assertJsonPath('data.0.settled', '1000')
            ->assertJsonPath('data.0.outstanding', '4000');
    }

    public function test_supplier_ledger_is_paginated_bilingual_isolated_and_dated_by_recorded_events(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 5);
        $this->receipt($ctx, $order, $this->location(), 5);
        $invoice = $this->invoice($ctx, $order, 5);
        $this->travel(1)->seconds();
        $cut = now()->toIso8601String();
        $this->travel(1)->seconds();
        $payment = $this->payment($ctx, $this->account(), '2000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '2000']]);
        $events = $this->op('GET', 'suppliers/'.$ctx['supplier'].'/ledger?locale=en')
            ->assertOk()
            ->json('data');
        $this->assertContains('Physical receipt', array_column($events, 'label'));
        $this->assertContains('payment_verified', array_column($events, 'kind'));
        $this->assertContains('invoice_revision', array_column($events, 'kind'));
        $this->assertContains('contract_revision', array_column($events, 'kind'));
        $past = $this->op('GET', 'suppliers/'.$ctx['supplier'].'/ledger?as_of='.urlencode($cut))
            ->assertOk()
            ->json('data');
        $this->assertNotContains('payment_verified', array_column($past, 'kind'));
        $other = $this->fixtureCatalogue();
        $this->op('GET', 'suppliers/'.$other['supplier'].'/ledger')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $this->operationUser('stock');
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/ledger')->assertForbidden();
    }

    public function test_sensitive_financial_actions_require_recent_password_confirmation_and_mfa(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $account = $this->account();
        $this->withSession(['auth.password_confirmed_at' => now()->subSeconds(901)->timestamp]);
        $this->op('POST', 'treasury/accounts', ['code' => 'STALE', 'name' => 'Test', 'kind' => 'cash'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp, 'mfa_verified' => false]);
        $this->op('POST', 'treasury/accounts', ['code' => 'MFA', 'name' => 'Test', 'kind' => 'cash'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
        $this->assertDatabaseCount('treasury_accounts', 1);
    }

    public function test_invoice_after_two_receipts_in_the_same_second_keeps_the_first_recorded_anchor(): void
    {
        $this->operationUser();
        $this->freezeTime();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx, 'credit', 'first_receipt'), 10);
        $location = $this->location();
        $first = $this->receipt($ctx, $order, $location, 5);
        $this->receipt($ctx, $order, $location, 5);
        // A later entry reports an earlier physical date; recording order remains authoritative.
        $other = DB::table('goods_receipts')->where('id', '!=', $first['id'])->first();
        DB::table('goods_receipts')
            ->where('id', $other->id)
            ->update(['received_at' => now()->subDays(3)]);
        $this->assertSame(1, DB::table('goods_receipts')->distinct()->count('created_at'));
        $invoice = $this->invoice($ctx, $order, 10);
        $this->assertSame(
            now('Africa/Douala')->addDays(30)->toDateString(),
            $invoice['obligation']['schedule'][0]['due_on'],
        );
        $this->travelBack();
    }
}
