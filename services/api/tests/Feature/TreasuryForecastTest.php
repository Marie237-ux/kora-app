<?php

namespace Tests\Feature;

use App\Domain\Documents\BillingIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\ExpenseFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\TestCase;

final class TreasuryForecastTest extends TestCase
{
    use AccountingFixtures,
        DeliveryFixtures,
        ExpenseFixtures,
        OrderFixtures,
        ProcurementFixtures,
        RefreshDatabase,
        TreasuryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $this->useFakeGateway();
    }

    private function liveContext(): array
    {
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Fictional forecast test operator',
                'niu' => 'TEST-NIU',
                'rccm' => 'TEST-RCCM',
                'address' => 'Test',
                'city' => 'Test',
                'tax_regime' => 'exempt',
                'mentions' => ['fr' => 'Test', 'en' => 'Test'],
            ],
            $actor,
        );
        $c = $this->accountingContext('live');
        $c['treasury'] = $this->account();
        $c['treasury_root'] = $c['root'].'/treasury';
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c))
            ->assertCreated()
            ->json('data');
        $c['evidence'] = $this->treasuryEvidence();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/chart/review', [
            'version' => 1,
            'sha256' => $c['chart']['sha256'],
            'note' => 'Test',
        ])->assertOk();
        $this->op('POST', $c['treasury_root'].'/bindings/'.$c['binding']['id'].'/review', [
            'sha256' => $c['binding']['sha256'],
            'note' => 'Test',
        ])->assertOk();
        $this->operationUser();

        return $c;
    }

    private function forecast(array $c, string $query = 'from=2026-11-05&to=2026-11-20')
    {
        return $this->op('GET', $c['root'].'/treasury/forecast?'.$query);
    }

    private function paidOrder(): array
    {
        $ctx = $this->checkout();
        $order = $this->order($ctx['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TEST-FORECAST-'.$attempt['id'], $attempt['id'], 2500);
        $this->notify([
            'transaction_id' => 'TEST-FORECAST-'.$attempt['id'],
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->operationUser();

        return compact('order', 'attempt');
    }

    public function test_empty_forecast_does_not_invent_cash_or_provider_availability(): void
    {
        $c = $this->treasuryContext();
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.mode', 'demo')
            ->assertJsonPath('data.scope', 'book_commitments_only')
            ->assertJsonPath('data.suppliers', null)
            ->assertJsonPath('data.refunds', null)
            ->assertJsonPath('data.collections', null)
            ->assertJsonPath('data.totals.known_outflows', '0')
            ->assertJsonPath('data.cash.available_funds', null)
            ->assertJsonPath('data.cash.provider_held_funds', null)
            ->assertJsonPath('data.cash.withdrawable_funds', null)
            ->assertJsonPath('data.cash.pending_withdrawals', '0')
            ->assertJsonPath('data.cash.free_cash', null)
            ->assertJsonPath('data.availability_verified', false)
            ->assertJsonPath('data.coverage.complete', false);
    }

    public function test_live_forecast_combines_remaining_supplier_expense_and_refund_commitments_once(): void
    {
        $c = $this->liveContext();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, extra: ['schedule' => [['basis_points' => 10000, 'offset_days' => 10]]]);
        $purchase = $this->purchase($ctx, $contract);
        $invoice = $this->invoice($ctx, $purchase, 100);
        $payment = $this->payment($ctx, $c['treasury'], '30000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '30000']]);
        $this->purchase($ctx, $contract, 20);
        $p = $this->expensePlan($c);
        $cash = $this->expenseCash($c);
        $this->operationUser('accountant');
        $this->expensePayment($c, $p, $cash);
        $paid = $this->paidOrder();
        $this->refund($paid['order'], '500')->assertCreated();
        $unmapped = $this->account();
        $entries = DB::table('accounting_entries')->count();
        $report = $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.scope', 'known_operational_commitments')
            ->assertJsonPath('data.suppliers.totals.recognized_outstanding', '70000')
            ->assertJsonPath('data.suppliers.totals.unrecognized_commitment', '20000')
            ->assertJsonPath('data.expenses.committed', '600')
            ->assertJsonPath('data.refunds.to_fund', '500')
            ->assertJsonPath('data.collections.confirmed_collected', '2500')
            ->assertJsonPath('data.totals.within_window', '70600')
            ->assertJsonPath('data.totals.undated', '20500')
            ->assertJsonPath('data.totals.known_outflows', '91100')
            ->assertJsonPath('data.cash.free_cash', null)
            ->assertJsonCount(2, 'data.cash_positions')
            ->json('data');
        $position = collect($report['cash_positions'])->firstWhere('treasury_account_id', $unmapped['id']);
        $this->assertFalse($position['coverage']['configured']);
        $this->assertFalse($position['coverage']['complete']);
        $this->assertNull($position['available_funds']);
        $this->assertSame($entries, DB::table('accounting_entries')->count());
        $this->assertDatabaseCount('customer_refunds', 1);
    }

    public function test_forecast_rejects_unsupported_historical_and_partial_scope_queries_and_checks_permissions(): void
    {
        $c = $this->treasuryContext();
        foreach (
            [
                'from=2026-11-20&to=2026-11-05',
                'from=2026-01-01&to=2027-01-03',
                'from=bad&to=2026-11-20',
                'from=2026-11-05&to=2026-11-20&as_of=2026-11-04',
                'from=2026-11-05&to=2026-11-20&supplier_id='.$this->fixtureId(),
                'from=2026-11-05&to=2026-11-20&treasury_account_id='.$c['treasury']['id'],
            ] as $query
        ) {
            $this->forecast($c, $query)->assertUnprocessable();
        }
        $this->forecast(['root' => 'accounting/books/'.$this->fixtureId()])->assertNotFound();
        $this->withSession(['mfa_verified' => false]);
        $this->forecast($c)->assertForbidden();
        $this->operationUser('support');
        $this->forecast($c)->assertForbidden();
    }

    public function test_demo_books_exclude_global_business_sources_and_other_books_expenses(): void
    {
        $live = $this->liveContext();
        $this->expensePlan($live);
        $paid = $this->paidOrder();
        $this->refund($paid['order'], '500')->assertCreated();
        $demo = $this->treasuryContext();
        $this->expensePlan($demo, ['amount' => '2000']);
        $other = $this->treasuryContext();
        $this->expensePlan($other, ['amount' => '3000']);
        $this->forecast($demo)
            ->assertOk()
            ->assertJsonPath('data.totals.known_outflows', '2000')
            ->assertJsonPath('data.suppliers', null)
            ->assertJsonPath('data.refunds', null)
            ->assertJsonPath('data.collections', null)
            ->assertJsonPath('data.coverage.business_sources_included', false)
            ->assertJsonCount(1, 'data.cash_positions')
            ->assertJsonPath('data.cash_positions.0.treasury_account_id', $demo['treasury']['id']);
        $this->forecast($live)->assertOk()->assertJsonPath('data.totals.known_outflows', '1500');
    }

    public function test_windows_are_inclusive_and_pending_or_future_approvals_are_not_current_commitments(): void
    {
        $c = $this->treasuryContext();
        foreach (
            ['2026-11-04' => '1000', '2026-11-05' => '2000', '2026-11-20' => '3000', '2026-11-21' => '4000'] as $date => $amount
        ) {
            $this->expensePlan($c, ['amount' => $amount, 'due_on' => $date, 'reference' => 'TEST-'.$date]);
        }
        $this->expensePlan($c, ['amount' => '5000', 'reference' => 'TEST-PENDING'], approve: false);
        $future = $this->expensePlan(
            $c,
            ['amount' => '6000', 'reference' => 'TEST-FUTURE', 'planned_on' => '2026-11-06'],
            approve: false,
        );
        $this->op('POST', $c['root'].'/expenses/'.$future['id'].'/approve', [
            'version' => 1,
            'sha256' => $future['sha256'],
            'date' => '2026-11-06',
            'note' => 'Test future approval',
        ])->assertOk();
        $pending = $this->expensePlan($c, ['amount' => '1000', 'reference' => 'TEST-PENDING-CANCEL'], approve: false);
        $this->op('POST', $c['root'].'/expenses/'.$pending['id'].'/cancel', [
            'version' => 1,
            'sha256' => $pending['sha256'],
            'date' => '2026-11-06',
            'note' => 'Test future cancellation',
            'accepted_remaining' => '1000',
        ])->assertOk();
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.expenses.pending_budget', '6000')
            ->assertJsonPath('data.expenses.not_yet_effective', '6000')
            ->assertJsonPath('data.totals.before_window', '1000')
            ->assertJsonPath('data.totals.within_window', '5000')
            ->assertJsonPath('data.totals.after_window', '4000')
            ->assertJsonPath('data.totals.past_due', '1000')
            ->assertJsonPath('data.totals.known_outflows', '10000')
            ->assertJsonCount(2, 'data.days')
            ->assertJsonPath('data.days.0.due_on', '2026-11-05')
            ->assertJsonPath('data.days.1.due_on', '2026-11-20');
        $this->travel(1)->days();
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.expenses.pending_budget', '5000')
            ->assertJsonPath('data.expenses.not_yet_effective', '0')
            ->assertJsonPath('data.totals.known_outflows', '16000');
    }

    public function test_dated_cancellation_and_reversal_do_not_resurrect_cancelled_commitments(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $payment = $this->expensePayment($c, $p, $this->expenseCash($c));
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/cancel', [
            'version' => 2,
            'sha256' => $p['sha256'],
            'date' => '2026-11-06',
            'note' => 'Test',
            'accepted_remaining' => '600',
        ])->assertOk();
        $this->forecast($c)->assertOk()->assertJsonPath('data.expenses.committed', '600');
        $this->travel(1)->days();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->op(
            'POST',
            $c['root'].'/expenses/'.$p['id'].'/payments/'.$payment['id'].'/reverse',
            $this->expenseReversal($c, $payment, '2026-11-07'),
        )->assertOk();
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.expenses.committed', '0')
            ->assertJsonPath('data.expenses.net_spent', '400')
            ->assertJsonPath('data.expenses.cancelled_unspent', '600');
        $this->travel(1)->days();
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.expenses.net_spent', '0')
            ->assertJsonPath('data.expenses.cancelled_unspent', '1000')
            ->assertJsonPath('data.totals.known_outflows', '0');
        $this->assertDatabaseCount('accounting_entries', 2);
    }

    public function test_supplier_and_combined_aggregates_exceed_operation_and_native_integer_limits_exactly(): void
    {
        $c = $this->liveContext();
        $purchases = [];
        foreach ([1, 2] as $i) {
            $ctx = $this->fixtureCatalogue();
            DB::table('offers')
                ->where('id', $ctx['offer'])
                ->update(['reference_price' => 1000000000000, 'selling_price' => 1000000000000]);
            $contract = $this->contract($ctx, extra: ['schedule' => [['basis_points' => 10000, 'offset_days' => 5]]]);
            $purchases[] = [$ctx, $this->purchase($ctx, $contract, 1000000)];
        }
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.suppliers.totals.unrecognized_commitment', '2000000000000000000');
        foreach ($purchases as [$ctx, $purchase]) {
            $document = $this->evidence($ctx['supplier'], 'supplier_invoice');
            $invoice = $this->op('POST', 'purchase-invoices', [
                'purchase_order_id' => $purchase['id'],
                'number' => 'TEST-'.$this->fixtureId(),
                'issued_on' => '2026-11-05',
                'document_id' => $document['id'],
                'lines' => [
                    [
                        'purchase_line_id' => $purchase['lines'][0]['id'],
                        'quantity' => 1000000,
                        'unit_payable' => 1000000000000,
                    ],
                ],
                'charges' => [],
            ])
                ->assertCreated()
                ->json('data');
            $this->op('POST', 'purchase-invoices/'.$invoice['id'].'/approve', [
                'version' => $invoice['version'],
            ])->assertOk();
        }
        foreach (range(1, 8) as $i) {
            $this->expensePlan($c, ['amount' => '1000000000000000000', 'reference' => 'TEST-LARGE-'.$i]);
        }
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.suppliers.totals.recognized_outstanding', '2000000000000000000')
            ->assertJsonPath('data.suppliers.totals.unrecognized_commitment', '0')
            ->assertJsonPath('data.expenses.committed', '8000000000000000000')
            ->assertJsonPath('data.totals.within_window', '10000000000000000000')
            ->assertJsonPath('data.days.0.total', '10000000000000000000')
            ->assertJsonPath('data.totals.known_outflows', '10000000000000000000');
        $this->op('GET', 'payables/forecast?from=2026-11-05&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.totals.recognized_outstanding', '2000000000000000000');
    }

    public function test_supplier_remainders_keep_unallocated_advances_and_undated_obligations_separate(): void
    {
        $c = $this->liveContext();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract(
            $ctx,
            extra: [
                'schedule' => [
                    ['basis_points' => 5000, 'offset_days' => 0],
                    ['basis_points' => 5000, 'offset_days' => 10],
                ],
            ],
        );
        $invoice = $this->invoice($ctx, $this->purchase($ctx, $contract), 100);
        $payment = $this->payment($ctx, $c['treasury'], '60000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '60000']]);
        $this->verify($ctx, $this->payment($ctx, $c['treasury'], '10000'), [], true);
        $undated = $this->contract($ctx, basis: 'first_receipt');
        $this->invoice($ctx, $this->purchase($ctx, $undated, 10), 10);
        $this->forecast($c, 'from=2026-11-06&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.suppliers.totals.unallocated_advances', '10000')
            ->assertJsonPath('data.suppliers.totals.undated', '10000')
            ->assertJsonPath('data.totals.before_window', '0')
            ->assertJsonPath('data.totals.within_window', '40000')
            ->assertJsonPath('data.totals.undated', '10000')
            ->assertJsonPath('data.totals.known_outflows', '50000')
            ->assertJsonPath('data.cash.free_cash', null);
    }

    public function test_collection_evidence_test_modes_disputes_and_duplicate_transactions_are_not_free_cash(): void
    {
        $c = $this->liveContext();
        $paid = $this->paidOrder();
        $refund = $this->refund($paid['order'], '200')->assertCreated()->json('data');
        $this->transfer($refund, '200', 'TEST-REFUND-FORECAST')->assertOk();
        $this->refund($paid['order'], '300')->assertCreated();
        $base = (array) DB::table('payment_attempts')->where('id', $paid['attempt']['id'])->first();
        foreach (
            [
                ['status' => 'to_verify', 'confirmed_at' => null, 'currency' => 'EUR'],
                ['testmode' => true],
                ['testmode' => null],
                ['confirmed_at' => null],
                ['status' => 'reversed', 'confirmed_at' => null],
            ] as $changes
        ) {
            DB::table('payment_attempts')->insert([
                ...$base,
                'id' => $this->fixtureId(),
                'request_key' => $this->fixtureId(),
                'provider_transaction_id' => $this->fixtureId(),
                ...$changes,
            ]);
        }
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.collections.confirmed_collected', '2500')
            ->assertJsonPath('data.collections.unresolved_attempt_count', 4)
            ->assertJsonPath('data.collections.test_attempt_count', 1)
            ->assertJsonPath('data.refunds.to_fund', '300')
            ->assertJsonPath('data.refunds.paid', '200')
            ->assertJsonPath('data.totals.undated', '300')
            ->assertJsonPath('data.cash.free_cash', null);
        DB::table('payment_attempts')->insert([
            ...$base,
            'id' => $this->fixtureId(),
            'request_key' => $this->fixtureId(),
        ]);
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.collections.confirmed_collected', '0')
            ->assertJsonPath('data.collections.duplicate_transaction_count', 1)
            ->assertJsonPath('data.collections.unresolved_attempt_count', 6);
    }

    public function test_closed_statement_is_a_dated_reference_and_never_a_current_available_balance(): void
    {
        $c = $this->treasuryContext();
        $posted = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c, ['ends_on' => '2026-11-04']);
        $s = $this->op(
            'POST',
            $c['treasury_root'].'/statements/'.$s['id'].'/matches',
            $this->matchData($s, $posted['line_id']),
        )
            ->assertCreated()
            ->json('data');
        $statement = DB::table('treasury_statements')->where('id', $s['statement_id'])->first();
        $this->op('POST', $c['treasury_root'].'/statements/'.$statement->id.'/close', [
            'version' => (int) $statement->version,
            'note' => 'Test close',
        ])->assertOk();
        $this->forecast($c)
            ->assertOk()
            ->assertJsonPath('data.cash_positions.0.latest_closed_statement.id', $statement->id)
            ->assertJsonPath('data.cash_positions.0.latest_closed_statement.as_of', '2026-11-04')
            ->assertJsonPath('data.cash_positions.0.latest_closed_statement.closing_balance', '2500')
            ->assertJsonPath('data.cash_positions.0.coverage.complete', false)
            ->assertJsonPath('data.cash_positions.0.available_funds', null)
            ->assertJsonPath('data.cash.available_funds', null);
    }
}
