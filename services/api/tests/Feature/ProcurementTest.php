<?php

namespace Tests\Feature;

use App\Domain\Documents\ClamAvScanner;
use App\Domain\Documents\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    public function test_credit_receipt_adds_stock_without_a_fake_payment_and_invoice_recognition_is_unique(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx);
        $order = $this->purchase($ctx, $contract);
        $location = $this->location();
        $receipt = $this->receipt($ctx, $order, $location, 100);
        $this->assertFalse($receipt['supplier_payment_implied']);
        $this->assertDatabaseCount('supplier_payments', 0);
        $this->assertDatabaseCount('supplier_obligations', 0);
        $this->getJson('/api/v1/operations/inventory/balances')
            ->assertJsonPath('data.0.on_hand', 100)
            ->assertJsonPath('data.0.reserved', 0)
            ->assertJsonPath('data.0.ownership', 'kora')
            ->assertJsonMissingPath('data.0.unit_cost');
        $invoice = $this->invoice($ctx, $order, 100);
        $this->assertSame('100000', $invoice['obligation']['outstanding']);
        $this->assertDatabaseCount('supplier_obligations', 1);
        $this->op('POST', 'purchase-invoices/'.$invoice['invoice']['id'].'/approve', [
            'version' => $invoice['invoice']['version'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('supplier_obligations', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
    }

    public function test_payment_intention_does_not_settle_debt_and_verified_30000_leaves_70000(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $invoice = $this->invoice($ctx, $order, 100);
        $payment = $this->payment($ctx, $this->account(), '30000');
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')
            ->assertJsonPath('data.balances.outstanding_total', '100000')
            ->assertJsonPath('data.balances.verified_paid_total', '0');
        $verified = $this->verify($ctx, $payment, [
            ['obligation_id' => $invoice['obligation']['id'], 'amount' => '30000'],
        ]);
        $this->assertSame('verified', $verified['status']);
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement?locale=en')
            ->assertJsonPath('data.balances.outstanding_total', '70000')
            ->assertJsonPath('data.balances.verified_paid_total', '30000')
            ->assertJsonPath('data.labels.title', 'Supplier statement');
        $this->assertDatabaseCount('supplier_payment_allocations', 1);
    }

    public function test_command_replay_is_exact_and_another_body_with_same_key_cannot_duplicate_receipts(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $location = $this->location();
        $doc = $this->evidence($ctx['supplier'], 'stock_evidence');
        $key = $this->fixtureId();
        $data = [
            'location_id' => $location['id'],
            'document_id' => $doc['id'],
            'reference' => 'RECEIPT TEST',
            'received_at' => now()->subSecond()->toIso8601String(),
            'lines' => [
                [
                    'purchase_line_id' => $order['lines'][0]['id'],
                    'quantity' => 40,
                    'quality' => 'available',
                    'identifiers' => [],
                ],
            ],
        ];
        $first = $this->op('POST', 'purchases/'.$order['id'].'/receipts', $data, $key)
            ->assertCreated()
            ->json('data');
        $second = $this->op('POST', 'purchases/'.$order['id'].'/receipts', $data, $key)
            ->assertCreated()
            ->json('data');
        $this->assertSame($first, $second);
        $data['lines'][0]['quantity'] = 41;
        $this->op('POST', 'purchases/'.$order['id'].'/receipts', $data, $key)->assertConflict();
        $this->assertDatabaseCount('goods_receipts', 1);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertSame(40, (int) DB::table('inventory_balances')->sum('on_hand'));
        $this->op('POST', 'purchases', [])->assertUnprocessable();
    }

    public function test_invoice_before_partial_receipts_creates_one_debt_and_receipt_based_due_date_once(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', 'first_receipt');
        $order = $this->purchase($ctx, $contract);
        $invoice = $this->invoice($ctx, $order, 100);
        $this->assertSame('awaiting_receipt', $invoice['obligation']['due_state']);
        $this->assertSame([], $invoice['obligation']['schedule']);
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 30);
        $this->receipt($ctx, $order, $location, 70);
        $this->assertDatabaseCount('supplier_obligations', 1);
        $this->assertDatabaseCount('payable_schedule', 1);
        $this->op('GET', 'payables/obligations')
            ->assertJsonPath('data.0.schedule.0.amount', '100000')
            ->assertJsonPath('data.0.schedule.0.due_on', now('Africa/Douala')->addDays(30)->toDateString());
        $fresh = $this->op('GET', 'purchases/'.$order['id'])
            ->assertOk()
            ->json('data');
        $this->assertSame(100, $fresh['lines'][0]['received_quantity']);
        $this->assertSame(100, $fresh['lines'][0]['invoiced_quantity']);
    }

    public function test_invoice_date_and_fixed_date_schedules_follow_the_frozen_contract_in_douala(): void
    {
        $this->travelTo(now()->setTimezone('UTC')->setDate(2026, 10, 28)->setTime(23, 30));
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', 'fixed_date', [
            'schedule' => [['basis_points' => 3000, 'offset_days' => 0], ['basis_points' => 7000, 'offset_days' => 10]],
        ]);
        $order = $this->purchase($ctx, $contract);
        $result = $this->invoice($ctx, $order, 100);
        $this->assertSame('2026-10-29', $result['invoice']['issued_on']);
        $this->assertSame($contract['terms']['fixed_due_on'], $result['obligation']['schedule'][0]['due_on']);
        $this->assertSame('30000', $result['obligation']['schedule'][0]['amount']);
        $this->assertSame('70000', $result['obligation']['schedule'][1]['amount']);
        $this->op('PUT', 'contracts/'.$contract['id'], [
            'version' => $contract['version'],
            'supplier_id' => $ctx['supplier'],
            'reference' => $contract['reference'],
            'starts_on' => $contract['starts_on'],
            'text' => $contract['text'],
            'terms' => $contract['terms'],
        ])->assertUnprocessable();
        $this->assertSame($contract['version'], $order['contract_snapshot']['version']);
    }

    public function test_one_verified_payment_can_cover_multiple_invoices_and_an_advance_stays_explicit(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $first = $this->invoice($ctx, $order, 40);
        $second = $this->invoice($ctx, $order, 60);
        $account = $this->account();
        $payment = $this->payment($ctx, $account, '120000');
        $verified = $this->verify(
            $ctx,
            $payment,
            [
                ['obligation_id' => $first['obligation']['id'], 'amount' => '40000'],
                ['obligation_id' => $second['obligation']['id'], 'amount' => '60000'],
            ],
            true,
        );
        $this->assertSame('20000', $verified['advance']);
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')
            ->assertJsonPath('data.balances.outstanding_total', '0')
            ->assertJsonPath('data.balances.advance_total', '20000');
        $this->assertDatabaseCount('supplier_payment_allocations', 2);
        $doc = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $this->op('POST', 'purchase-invoices', [
            'purchase_order_id' => $order['id'],
            'number' => 'DUPLICATE TEST',
            'issued_on' => now('Africa/Douala')->toDateString(),
            'document_id' => $doc['id'],
            'lines' => [['purchase_line_id' => $order['lines'][0]['id'], 'quantity' => 1, 'unit_payable' => 1000]],
            'charges' => [],
        ])->assertUnprocessable();
    }

    public function test_requested_and_actual_payment_amounts_are_distinct_and_transaction_references_cannot_be_reused(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $invoice = $this->invoice($ctx, $order, 100);
        $account = $this->account();
        $payment = $this->payment($ctx, $account, '40000');
        $verified = $this->verify(
            $ctx,
            $payment,
            [['obligation_id' => $invoice['obligation']['id'], 'amount' => '30000']],
            false,
            '30000',
        );
        $this->assertSame('40000', $verified['requested_amount']);
        $this->assertSame('30000', $verified['amount']);
        $other = $this->payment($ctx, $account, '30000');
        $proof = $this->evidence($ctx['supplier'], 'payment_evidence');
        $this->op('POST', 'supplier-payments/'.$other['id'].'/verify', [
            'amount' => '30000',
            'reference' => strtolower($verified['reference']),
            'evidence_id' => $proof['id'],
            'paid_at' => now()->subSecond()->toIso8601String(),
            'allow_advance' => false,
            'allocations' => [['obligation_id' => $invoice['obligation']['id'], 'amount' => '30000']],
        ])->assertConflict();
        $this->assertDatabaseCount('supplier_payment_allocations', 1);
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')->assertJsonPath(
            'data.balances.outstanding_total',
            '70000',
        );
    }

    public function test_supplier_return_does_not_erase_debt_until_a_documented_credit_is_approved(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 100);
        $invoice = $this->invoice($ctx, $order, 100);
        $balance = $this->op('GET', 'inventory/balances')->assertOk()->json('data.0');
        $proof = $this->evidence($ctx['supplier'], 'stock_evidence');
        $return = $this->op('POST', 'inventory/supplier-returns', [
            'balance_id' => $balance['id'],
            'quantity' => 10,
            'unit_ids' => [],
            'document_id' => $proof['id'],
            'reason' => 'Test accepted supplier return',
            'supplier_reference' => 'TEST ACCEPTANCE',
        ])
            ->assertCreated()
            ->json('data');
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')->assertJsonPath(
            'data.balances.outstanding_total',
            '100000',
        );
        $creditDoc = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $credit = $this->op('POST', 'supplier-credits', [
            'invoice_id' => $invoice['invoice']['id'],
            'return_id' => $return['id'],
            'amount' => '10000',
            'document_id' => $creditDoc['id'],
            'reference' => 'CREDIT TEST',
            'reason' => 'Supplier credit accepted in test',
        ])
            ->assertCreated()
            ->json('data');
        $this->assertSame('10000', $credit['applied']);
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')->assertJsonPath(
            'data.balances.outstanding_total',
            '90000',
        );
        $this->assertSame(90, (int) DB::table('inventory_balances')->sum('on_hand'));
    }

    public function test_consignment_retains_supplier_ownership_and_recognizes_only_an_approved_sales_statement(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'consignment');
        $order = $this->purchase($ctx, $contract);
        $location = $this->location();
        $this->receipt($ctx, $order, $location, 100);
        $this->op('GET', 'inventory/balances')->assertJsonPath('data.0.ownership', 'supplier');
        $this->assertDatabaseCount('supplier_obligations', 0);
        $this->inventoryPolicy();
        $reservation = $this->op('POST', 'inventory/reservations', [
            'offer_id' => $ctx['offer'],
            'purpose_id' => $this->fixtureId(),
            'quantity' => 30,
        ])
            ->assertCreated()
            ->json('data');
        $proof = $this->evidence($ctx['supplier'], 'stock_evidence');
        $this->op('POST', 'inventory/reservations/'.$reservation['id'].'/issue', [
            'sale_reference' => 'MANUALLY VERIFIED TEST SALE',
            'sale_verified' => true,
            'document_id' => $proof['id'],
        ])->assertOk();
        $this->assertDatabaseCount('supplier_obligations', 0);
        $document = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $today = now('Africa/Douala')->toDateString();
        $this->op('GET', 'payables/forecast?from='.$today.'&to='.$today)
            ->assertOk()
            ->assertJsonPath('data.totals.recognized_outstanding', '0')
            ->assertJsonPath('data.totals.consignment_sales_to_reconcile', '30000');
        $data = [
            'starts_on' => now('Africa/Douala')->toDateString(),
            'ends_on' => now('Africa/Douala')->toDateString(),
            'document_id' => $document['id'],
            'reference' => 'STATEMENT TEST',
        ];
        $statement = $this->op('POST', 'purchases/'.$order['id'].'/consignment-statements', $data)
            ->assertCreated()
            ->json('data');
        $this->assertSame('30000', $statement['amount']);
        $this->assertSame(30, $statement['quantity_sold']);
        $this->op('GET', 'payables/forecast?from='.$today.'&to='.$today)
            ->assertOk()
            ->assertJsonPath('data.totals.recognized_outstanding', '30000')
            ->assertJsonPath('data.totals.consignment_sales_to_reconcile', '0');
        $data['reference'] = 'SECOND STATEMENT TEST';
        $this->op('POST', 'purchases/'.$order['id'].'/consignment-statements', $data)->assertUnprocessable();
        $this->assertDatabaseCount('supplier_obligations', 1);
        $this->assertSame(70, (int) DB::table('inventory_balances')->sum('on_hand'));
    }

    public function test_schedule_renegotiation_retains_the_previous_schedule_and_requires_exact_amounts(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $invoice = $this->invoice($ctx, $order, 100);
        $doc = $this->evidence($ctx['supplier'], 'supplier_contract');
        $id = $invoice['obligation']['id'];
        $data = [
            'version' => 1,
            'document_id' => $doc['id'],
            'reason' => 'Test signed renegotiation',
            'installments' => [
                ['amount' => '30000', 'due_on' => now('Africa/Douala')->addDays(10)->toDateString()],
                ['amount' => '70000', 'due_on' => now('Africa/Douala')->addDays(40)->toDateString()],
            ],
        ];
        $this->op('POST', 'payables/obligations/'.$id.'/reschedule', $data)
            ->assertOk()
            ->assertJsonPath('data.schedule_version', 2);
        $this->assertDatabaseCount('payable_schedule', 3);
        $this->op('POST', 'payables/obligations/'.$id.'/reschedule', $data)->assertConflict();
        $data['version'] = 2;
        $data['installments'][1]['amount'] = '69999';
        $this->op('POST', 'payables/obligations/'.$id.'/reschedule', $data)->assertUnprocessable();
        $this->assertDatabaseCount('payable_schedule', 3);
    }

    public function test_missing_scanner_leaves_a_document_pending_and_cannot_be_forged_clean(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        config(['kora.clamscan_binary' => null]);
        $this->app->forgetInstance(DocumentScanner::class);
        $this->app->bind(DocumentScanner::class, ClamAvScanner::class);
        $document = $this->evidence($ctx['supplier'], 'supplier_contract');
        $this->assertSame('pending', $document['scan_status']);
        $this->getJson('/api/v1/operations/documents/'.$document['id'].'/download')->assertUnprocessable();
        $row = DB::table('private_documents')->find($document['id']);
        $this->assertSame($document['sha256'], hash('sha256', Storage::disk('local')->get($row->object_key)));
        $this->getJson('/api/v1/media/'.$document['id'].'/public')->assertNotFound();
    }

    public function test_credit_limit_needs_owner_override_but_actual_receipts_remain_recordable(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', 'invoice_date', ['credit_limit' => '50000']);
        $order = $this->purchase($ctx, $contract, 100, false);
        $location = $this->location();
        $this->operationUser('procurement');
        $this->op('POST', 'purchases/'.$order['id'].'/confirm', [
            'version' => 1,
            'supplier_confirmation_reference' => 'TEST',
            'credit_override_reason' => 'Forged authority',
        ])->assertUnprocessable();
        $this->receipt($ctx, $order, $location, 100);
        $this->assertDatabaseCount('supplier_payments', 0);
        $this->operationUser();
        $this->op('POST', 'purchases/'.$order['id'].'/confirm', [
            'version' => 1,
            'supplier_confirmation_reference' => 'TEST',
            'credit_override_reason' => 'Owner explicitly approves test exposure',
        ])->assertOk();
        $this->assertDatabaseCount('goods_receipts', 1);
    }

    public function test_private_documents_and_finance_mutations_require_the_correct_permission_and_mfa(): void
    {
        $this->getJson('/api/v1/operations/purchases')->assertUnauthorized();
        foreach (['client', 'supplier', 'courier', 'catalogue'] as $role) {
            $this->operationUser($role);
            $this->getJson('/api/v1/operations/supplier-payments')->assertForbidden();
            $this->op('POST', 'purchase-invoices/'.$this->fixtureId().'/approve')->assertForbidden();
        }
        $this->operationUser('procurement');
        $this->op('POST', 'supplier-payments/'.$this->fixtureId().'/verify', [])->assertForbidden();
        $this->operationUser('stock');
        $this->getJson('/api/v1/operations/purchases')->assertForbidden();
        $this->op('POST', 'inventory/adjustments', [])->assertForbidden();
        $this->getJson('/api/v1/operations/inventory/receivable-purchases')->assertOk();
        $this->operationUser();
        $this->withSession(['mfa_verified' => false])
            ->getJson('/api/v1/operations/payables/obligations')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }
}
