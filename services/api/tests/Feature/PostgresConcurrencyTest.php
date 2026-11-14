<?php

namespace Tests\Feature;

use App\Domain\Accounting\AccountingLettering;
use App\Domain\Accounting\BalanceObservations;
use App\Domain\Accounting\ExpensePlans;
use App\Domain\Accounting\Financings;
use App\Domain\Accounting\TreasuryForecast;
use App\Domain\Accounting\Withdrawals;
use App\Domain\Delivery\DeliveryMissions;
use App\Domain\Documents\BillingIdentity;
use App\Domain\Orders\OrderPreparation;
use App\Domain\Orders\OrderPurchases;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use App\Models\User;
use Brick\Math\BigInteger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\AccountingCaptureFixtures;
use Tests\Support\AccountingFixtures;
use Tests\Support\BalanceObservationFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\ExpenseFixtures;
use Tests\Support\FinancingFixtures;
use Tests\Support\LetteringFixtures;
use Tests\Support\LoyaltyCheckoutFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\ProfessionalFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\Support\WithdrawalFixtures;
use Tests\TestCase;

final class PostgresConcurrencyTest extends TestCase
{
    use AccountingCaptureFixtures,
        AccountingFixtures,
        BalanceObservationFixtures,
        DeliveryFixtures,
        ExpenseFixtures,
        FinancingFixtures,
        LetteringFixtures,
        LoyaltyCheckoutFixtures,
        OrderFixtures,
        PreparedOrderFixtures,
        ProcurementFixtures,
        ProfessionalFixtures,
        TreasuryFixtures,
        WithdrawalFixtures;

    private bool $ownsDatabase = false;

    private array $workers = [];

    public function test_two_financing_repayments_cannot_exceed_principal_actually_received(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->financingContext();
        $f = $this->financing($c);
        $this->financingMovement($c, $f, $this->financingEntry($c, '1000'));
        $actor = auth()->user();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (range(1, 2) as $i) {
            $e = $this->financingEntry($c, '700', 'repayment');
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_financing_record',
                'test_now' => '2026-11-05T11:00:00Z',
                'actor_id' => $actor->id,
                'command' => 'test.financing_record',
                'key' => $this->fixtureId(),
                'data' => [
                    'book_id' => $c['book']['id'],
                    'id' => $f['id'],
                    ...$this->financingMovementData($c, $e, [
                        'direction' => 'repayment',
                        'reference' => 'TEST-REPAY-'.$i,
                    ]),
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('financing_movements', 2);
        $s = app(Financings::class)->summary(Records::row('accounting_books', $c['book']['id'], false));
        $this->assertSame('300', $s['totals']['outstanding_principal']);
        $this->assertSame('700', $s['totals']['repaid_principal']);
    }

    public function test_financing_repayment_and_funding_correction_cannot_produce_negative_principal(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->financingContext();
        $f = $this->financing($c);
        $m = $this->financingMovement($c, $f, $this->financingEntry($c, '1000'));
        $e = $this->financingEntry($c, '700', 'repayment');
        $actor = auth()->user();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_financing_record' => $this->financingMovementData($c, $e, [
                    'direction' => 'repayment',
                    'reference' => 'TEST-REPAY',
                ]),
                'accounting_financing_reverse' => ['movement' => $m['id'], ...$this->financingReversal($c, $m)],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'test_now' => '2026-11-05T11:00:00Z',
                'actor_id' => $actor->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $f['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $s = app(Financings::class)->summary(Records::row('accounting_books', $c['book']['id'], false));
        $this->assertContains($s['totals']['outstanding_principal'], ['0', '300']);
        $this->assertTrue(
            BigInteger::of($s['totals']['funded_principal'])->isGreaterThanOrEqualTo($s['totals']['repaid_principal']),
        );
    }

    public function test_financing_forecast_uses_a_read_only_snapshot_when_repayment_is_committed_between_sources(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->financingContext();
        $f = $this->financing($c);
        $this->financingMovement($c, $f, $this->financingEntry($c));
        $e = $this->financingEntry($c, '200', 'repayment');
        $actor = auth()->user();
        $data = $this->financingMovementData($c, $e, ['direction' => 'repayment', 'reference' => 'TEST-REPAY']);
        config(['database.connections.proof_writer' => config('database.connections.pgsql')]);
        $written = false;
        try {
            DB::listen(function (QueryExecuted $q) use (&$written, $c, $f, $data, $actor) {
                if (! $written && $q->connectionName !== 'proof_writer' && str_contains($q->sql, 'from "financings"')) {
                    $written = true;
                    $default = DB::getDefaultConnection();
                    DB::setDefaultConnection('proof_writer');
                    try {
                        DB::transaction(
                            fn () => app(Financings::class)->record(
                                Records::row('accounting_books', $c['book']['id']),
                                $f['id'],
                                $data,
                                $actor,
                            ),
                        );
                    } finally {
                        DB::setDefaultConnection($default);
                    }
                }
            });
            $report = app(ReadSnapshot::class)->run(function () use ($c) {
                $this->assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);

                return app(TreasuryForecast::class)->calculate(
                    Records::row('accounting_books', $c['book']['id'], false),
                    '2026-11-05',
                    '2026-11-20',
                );
            });
            $this->assertTrue($written);
            $this->assertSame('600', $report['financing']['totals']['outstanding_principal']);
            $this->assertSame('600', $report['totals']['known_outflows']);
            $next = app(ReadSnapshot::class)->run(
                fn () => app(TreasuryForecast::class)->calculate(
                    Records::row('accounting_books', $c['book']['id'], false),
                    '2026-11-05',
                    '2026-11-20',
                ),
            );
            $this->assertSame('400', $next['financing']['totals']['outstanding_principal']);
            $this->assertSame('400', $next['totals']['known_outflows']);
        } finally {
            DB::purge('proof_writer');
        }
    }

    public function test_balance_observation_summary_is_read_only_and_ignores_evidence_changed_halfway(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->treasuryContext();
        $this->balanceObservation($c);
        config(['database.connections.proof_writer' => config('database.connections.pgsql')]);
        $written = false;
        try {
            DB::listen(function (QueryExecuted $q) use (&$written, $c) {
                if (! $written && $q->connectionName !== 'proof_writer' && str_contains($q->sql, 'treasury_accounts')) {
                    $written = true;
                    DB::connection('proof_writer')
                        ->table('private_documents')
                        ->where('id', $c['evidence']['id'])
                        ->update(['scan_status' => 'pending']);
                }
            });
            $r = app(ReadSnapshot::class)->run(function () use ($c) {
                $this->assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);

                return app(BalanceObservations::class)->summary(
                    Records::row('accounting_books', $c['book']['id'], false),
                );
            });
            $this->assertTrue($written);
            $this->assertSame('800', $r['totals']['available_funds']);
            $next = app(ReadSnapshot::class)->run(
                fn () => app(BalanceObservations::class)->summary(
                    Records::row('accounting_books', $c['book']['id'], false),
                ),
            );
            $this->assertSame('evidence_unavailable', $next['accounts'][0]['state']);
            $this->assertNull($next['totals']['available_funds']);
            $this->assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        } finally {
            DB::purge('proof_writer');
        }
    }

    public function test_two_balance_observations_cannot_both_replace_the_same_account_sequence(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->treasuryContext();
        $actor = auth()->user();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (range(1, 2) as $i) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_balance_create',
                'test_now' => '2026-11-05T11:00:00Z',
                'actor_id' => $actor->id,
                'command' => 'test.balance_create',
                'key' => $this->fixtureId(),
                'data' => [
                    'book_id' => $c['book']['id'],
                    ...$this->balanceObservationData($c, ['reference' => 'TEST-'.$i]),
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('treasury_balance_observations', 1);
        $this->assertDatabaseHas('treasury_balance_observations', [
            'book_id' => $c['book']['id'],
            'sequence' => 1,
            'status' => 'pending',
        ]);
    }

    public function test_a_new_balance_observation_prevents_concurrent_review_from_exposing_an_old_balance(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->treasuryContext();
        $o = $this->balanceObservation($c, approve: false);
        $actor = $this->operationUser('accountant');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_balance_create' => $this->balanceObservationData($c, ['sequence' => 1]),
                'accounting_balance_approve' => [
                    'id' => $o['id'],
                    'version' => 1,
                    'sha256' => $o['sha256'],
                    'note' => 'Test',
                ],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'test_now' => '2026-11-05T11:00:00Z',
                'actor_id' => $actor->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], ...$data],
            ];
        }
        $statuses = $this->contend('accounting_books', $c['book']['id'], $race, $payloads);
        $this->assertContains($statuses, [['ok', 'ok'], ['ok', 'rejected']]);
        $this->assertDatabaseCount('treasury_balance_observations', 2);
        $r = app(BalanceObservations::class)->summary(Records::row('accounting_books', $c['book']['id'], false));
        $this->assertSame('pending_review', $r['accounts'][0]['state']);
        $this->assertSame(2, $r['accounts'][0]['observation']['sequence']);
        $this->assertNull($r['totals']['available_funds']);
    }

    public function test_two_withdrawal_receipts_cannot_overallocate_the_requested_gross_amount(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $actor = $this->operationUser('accountant');
        $entries = [$this->withdrawalEntry($c, '700'), $this->withdrawalEntry($c, '700')];
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ($entries as $i => $entry) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_withdrawal_record',
                'actor_id' => $actor->id,
                'command' => 'test.withdrawal_record',
                'key' => $this->fixtureId(),
                'data' => [
                    'book_id' => $c['book']['id'],
                    'id' => $w['id'],
                    ...$this->withdrawalReceiptData($c, $entry, ['reference' => 'TEST-'.$i]),
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('withdrawal_receipts', 1);
        $this->assertDatabaseCount('accounting_entries', 2);
        $summary = app(Withdrawals::class)->summary(Records::row('accounting_books', $c['book']['id'], false));
        $this->assertSame('300', $summary['totals']['pending_gross']);
        $this->assertSame('700', $summary['totals']['received_gross']);
    }

    public function test_withdrawal_receipt_and_cancellation_serialize_the_explicitly_accepted_remainder(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $actor = $this->operationUser('accountant');
        $entry = $this->withdrawalEntry($c, '700');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_withdrawal_record' => $this->withdrawalReceiptData($c, $entry),
                'accounting_withdrawal_cancel' => [
                    'version' => 2,
                    'sha256' => $w['sha256'],
                    'date' => '2026-11-05',
                    'note' => 'Test cancel',
                    'document_id' => $c['evidence']['id'],
                    'accepted_remaining' => '1000',
                ],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $actor->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $w['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $request = Records::row('withdrawals', $w['id'], false);
        $balance = app(Withdrawals::class)->balance($request);
        $this->assertSame($request->status === 'cancelled' ? '0' : '300', $balance['pending_gross']);
        $this->assertDatabaseCount('withdrawal_receipts', $request->status === 'cancelled' ? 0 : 1);
        $this->assertDatabaseCount('accounting_entries', 1);
    }

    public function test_treasury_forecast_uses_one_snapshot_when_a_refund_is_committed_between_sources(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Fictional snapshot test operator',
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
        $order = $this->paidOrderFixture('TEST-FORECAST-SNAPSHOT');
        $refund = [
            'order_id' => $order,
            'amount' => 1000,
            'reason' => 'Test',
            'status' => 'requested',
            'requested_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        DB::table('customer_refunds')->insert(['id' => $this->fixtureId(), ...$refund]);
        $ctx = $this->fixtureCatalogue();
        $this->invoice($ctx, $this->purchase($ctx, $this->contract($ctx)), 100);
        config(['database.connections.proof_writer' => config('database.connections.pgsql')]);
        $written = false;
        try {
            DB::listen(function (QueryExecuted $query) use (&$written, $refund) {
                if (
                    ! $written &&
                    $query->connectionName !== 'proof_writer' &&
                    str_contains($query->sql, 'expense_plans')
                ) {
                    $written = true;
                    DB::connection('proof_writer')
                        ->table('customer_refunds')
                        ->insert(['id' => $this->fixtureId(), ...$refund, 'amount' => 700]);
                }
            });
            $report = app(ReadSnapshot::class)->run(
                fn () => app(TreasuryForecast::class)->calculate(
                    Records::row('accounting_books', $c['book']['id'], false),
                    '2026-11-01',
                    '2026-12-31',
                ),
            );
            $this->assertTrue($written);
            $this->assertSame('1000', $report['refunds']['to_fund']);
            $this->assertSame('101000', $report['totals']['known_outflows']);
            $next = app(ReadSnapshot::class)->run(
                fn () => app(TreasuryForecast::class)->calculate(
                    Records::row('accounting_books', $c['book']['id'], false),
                    '2026-11-01',
                    '2026-12-31',
                ),
            );
            $this->assertSame('1700', $next['refunds']['to_fund']);
            $this->assertSame('101700', $next['totals']['known_outflows']);
            $this->assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        } finally {
            DB::purge('proof_writer');
        }
    }

    public function test_two_expense_allocations_cannot_overrun_the_same_approved_budget(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $reviewer = $this->operationUser('accountant');
        $entries = [$this->expenseCash($c, '700'), $this->expenseCash($c, '700')];
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ($entries as $entry) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_expense_record',
                'actor_id' => $reviewer->id,
                'command' => 'test.expense_record',
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $p['id'], ...$this->expensePaymentData($c, $entry)],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('expense_payments', 1);
        $this->assertDatabaseCount('accounting_entries', 2);
        $balance = app(ExpensePlans::class)->balance(
            (object) ['id' => $p['id'], 'amount' => 1000, 'approved_on' => '2026-11-04', 'cancelled_on' => null],
        );
        $this->assertSame('700', $balance['net_spent']);
        $this->assertSame('300', $balance['committed']);
    }

    public function test_expense_recording_and_cancellation_cannot_silently_change_the_accepted_unspent_amount(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $reviewer = $this->operationUser('accountant');
        $entry = $this->expenseCash($c, '700');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_expense_record' => $this->expensePaymentData($c, $entry),
                'accounting_expense_cancel' => [
                    'version' => 2,
                    'sha256' => $p['sha256'],
                    'accepted_remaining' => '1000',
                    'date' => '2026-11-04',
                    'note' => 'Test cancel',
                ],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $reviewer->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $p['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $plan = DB::table('expense_plans')->where('id', $p['id'])->first();
        $balance = app(ExpensePlans::class)->balance($plan);
        $this->assertSame($plan->status === 'cancelled' ? '0' : '300', $balance['committed']);
        $this->assertSame($plan->status === 'cancelled' ? 0 : 1, DB::table('expense_payments')->count());
        $this->assertDatabaseCount('accounting_entries', 1);
    }

    public function test_two_partial_letterings_cannot_overallocate_the_same_posted_line(): void
    {
        $c = $this->letteringContext();
        $invoice = $this->letteringEntry($c, 'debit', '100');
        $payments = [$this->letteringEntry($c, 'credit', '70'), $this->letteringEntry($c, 'credit', '70')];
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ($payments as $payment) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_lettering_create',
                'actor_id' => $c['actor']->id,
                'command' => 'test.lettering_create',
                'key' => $this->fixtureId(),
                'data' => [
                    'book_id' => $c['book']['id'],
                    ...$this->letteringData($c, $this->letteringPair($invoice, $payment, '70')),
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('accounting_letterings', 1);
        $this->assertDatabaseCount('accounting_lettering_lines', 2);
        $report = app(AccountingLettering::class)->report((object) $c['book'], [
            'account_id' => $c['account']['id'],
            'as_of' => '2026-11-04',
        ]);
        $this->assertSame('30', $report['totals']['remaining_debit']);
        $this->assertSame('70', $report['totals']['remaining_credit']);
    }

    public function test_void_and_new_lettering_serialize_capacity_without_rewriting_the_previous_day(): void
    {
        $c = $this->letteringContext();
        $invoice = $this->letteringEntry($c, 'debit');
        $first = $this->letteringEntry($c, 'credit');
        $next = $this->letteringEntry($c, 'credit');
        $g = $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($invoice, $first)),
        )
            ->assertCreated()
            ->json('data');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_lettering_void' => [
                    'id' => $g['id'],
                    'version' => 1,
                    'sha256' => $g['sha256'],
                    'voided_on' => '2026-11-05',
                    'note' => 'Test cancellation',
                ],
                'accounting_lettering_create' => $this->letteringData(
                    $c,
                    $this->letteringPair($invoice, $next),
                    '2026-11-05',
                ),
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $c['actor']->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], ...$data],
            ];
        }
        $this->assertContains($this->contend('accounting_books', $c['book']['id'], $race, $payloads), [
            ['ok', 'ok'],
            ['ok', 'rejected'],
        ]);
        $this->assertDatabaseHas('accounting_letterings', ['id' => $g['id'], 'status' => 'void']);
        $report = app(AccountingLettering::class)->report((object) $c['book'], [
            'account_id' => $c['account']['id'],
            'as_of' => '2026-11-04',
        ]);
        $this->assertSame('1000', $report['totals']['allocated_debit']);
        $this->assertSame('0', $report['totals']['remaining_debit']);
    }

    public function test_coverage_activation_and_statement_closing_cannot_certify_an_unverified_closure(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s))
            ->assertCreated()
            ->json('data');
        $reviewer = $this->operationUser('accountant');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_coverage_review' => [
                    'id' => $p['id'],
                    'version' => 1,
                    'sha256' => $p['sha256'],
                    'note' => 'Test review',
                ],
                'accounting_treasury_close' => ['id' => $s['id'], 'version' => 1, 'note' => 'Test close'],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $reviewer->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], ...$data],
            ];
        }
        $result = $this->contend('accounting_books', $c['book']['id'], $race, $payloads);
        $this->assertContains($result, [['ok', 'ok'], ['ok', 'rejected']]);
        $this->assertDatabaseHas('treasury_statements', ['id' => $s['id'], 'status' => 'closed']);
        $active = DB::table('treasury_coverage_policies')->where('id', $p['id'])->where('current_slot', 1)->exists();
        $snapshot = json_decode(
            DB::table('treasury_closures')->where('statement_id', $s['id'])->value('snapshot'),
            true,
        );
        $this->assertSame($active ? $p['id'] : null, $snapshot['balance']['policy_id'] ?? null);
    }

    public function test_preopening_post_and_covered_statement_close_cannot_leave_a_stale_closed_opening(): void
    {
        $c = $this->treasuryContext();
        $e = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c, '100', '2026-10-31'))
            ->assertCreated()
            ->json('data');
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $this->activateCoverage($c, $s);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_post' => ['id' => $e['id'], 'version' => 1],
                'accounting_treasury_close' => ['id' => $s['id'], 'version' => 1, 'note' => 'Test close'],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $c['actor']->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $closed = DB::table('treasury_statements')->where('id', $s['id'])->value('status') === 'closed';
        $this->assertSame(
            $closed ? 'draft' : 'posted',
            DB::table('accounting_entries')->where('id', $e['id'])->value('status'),
        );
    }

    public function test_outstanding_decision_and_match_cannot_claim_the_same_line_concurrently(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_outstanding_create' => $this->outstandingData($c, $s, 'ledger', $p['line_id']),
                'accounting_treasury_match' => $this->matchData($s, $p['line_id']),
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $c['actor']->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $s['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertSame(1, DB::table('treasury_outstanding')->count() + DB::table('treasury_matches')->count());
        $this->assertSame(2, (int) DB::table('treasury_statements')->where('id', $s['id'])->value('version'));
    }

    public function test_two_cross_period_settlements_consume_an_outstanding_line_only_once(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $nov = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $carry = $this->treasuryCarry($c, $nov, 'ledger', $p['line_id']);
        $dec = $this->treasuryStatement($c, [
            'reference' => 'TEST-DEC',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'lines' => [[...$this->statementData($c)['lines'][0], 'booked_on' => '2026-12-05']],
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_treasury_match',
                'actor_id' => $c['actor']->id,
                'command' => 'treasury.match',
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $dec['id'], ...$this->matchData($dec, $p['line_id'])],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('treasury_match_ledger', 1);
        $this->assertDatabaseHas('treasury_closures', [
            'id' => $carry['closed']['closure']['id'],
            'sha256' => $carry['closed']['closure']['sha256'],
        ]);
    }

    public function test_two_treasury_matches_cannot_consume_the_same_lines_twice(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_treasury_match',
                'actor_id' => $c['actor']->id,
                'command' => 'treasury.match',
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $s['id'], ...$this->matchData($s, $p['line_id'])],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('treasury_matches', 1);
        $this->assertDatabaseCount('treasury_match_rows', 1);
        $this->assertDatabaseCount('treasury_match_ledger', 1);
    }

    public function test_posting_and_statement_closure_serialize_without_a_false_reconciled_balance(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $this->op(
            'POST',
            $c['treasury_root'].'/statements/'.$s['id'].'/matches',
            $this->matchData($s, $p['line_id']),
        )->assertCreated();
        $draft = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_post' => ['id' => $draft['id'], 'version' => 1],
                'accounting_treasury_close' => ['id' => $s['id'], 'version' => 2, 'note' => 'Test closure'],
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $c['actor']->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $closed = DB::table('treasury_statements')->where('id', $s['id'])->value('status') === 'closed';
        $this->assertSame(
            $closed ? 'draft' : 'posted',
            DB::table('accounting_entries')->where('id', $draft['id'])->value('status'),
        );
    }

    public function test_two_accounting_capture_workers_commit_one_source_and_one_attempt(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_capture',
                'actor_id' => $c['actor']->id,
                'command' => 'test.accounting.capture',
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'event_id' => $facts['eventId']],
            ];
        }
        $this->assertSame(['ok', 'ok'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('accounting_sources', 1);
        $this->assertDatabaseCount('accounting_capture_attempts', 1);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'book_id' => $c['book']['id'],
            'event_id' => $facts['eventId'],
            'status' => 'captured',
            'attempts' => 1,
        ]);
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_manual_and_automatic_accounting_capture_share_the_same_source_without_double_effects(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (['accounting_capture', 'accounting_manual_capture'] as $kind) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $c['actor']->id,
                'command' => 'test.'.$kind,
                'key' => $this->fixtureId(),
                'data' => [
                    'book_id' => $c['book']['id'],
                    'event_id' => $facts['eventId'],
                    'source_type' => 'customer_invoice',
                    'resource_id' => $facts['document']['id'],
                ],
            ];
        }
        $this->assertSame(['ok', 'ok'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseCount('accounting_sources', 1);
        $sourceId = DB::table('accounting_sources')->value('id');
        $this->assertDatabaseHas('accounting_capture_receipts', ['source_id' => $sourceId, 'status' => 'captured']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'accounting.source_captured')->count());
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_two_concurrent_validations_post_one_accounting_entry_and_consume_one_number(): void
    {
        $c = $this->accountingContext();
        $draft = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'accounting_post',
                'actor_id' => $c['actor']->id,
                'command' => 'accounting.entry_post',
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], 'id' => $draft['id'], 'version' => 1],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $draft['id'],
            'status' => 'posted',
            'version' => 2,
            'number' => 'DEMO-TEST-20260101-00000001',
        ]);
        $this->assertDatabaseCount('accounting_entry_revisions', 2);
    }

    public function test_closing_and_creating_a_draft_cannot_leave_a_draft_in_a_closed_period(): void
    {
        $c = $this->accountingContext();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (
            [
                'accounting_close' => ['id' => $c['period']['id'], 'version' => 1, 'note' => 'Test closure'],
                'accounting_create' => $this->accountingEntryData($c),
            ] as $kind => $data
        ) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => $kind,
                'actor_id' => $c['actor']->id,
                'command' => $kind,
                'key' => $this->fixtureId(),
                'data' => ['book_id' => $c['book']['id'], ...$data],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('accounting_books', $c['book']['id'], $race, $payloads));
        $closed = DB::table('accounting_periods')->where('id', $c['period']['id'])->value('status') === 'closed';
        $this->assertSame(
            $closed ? 0 : 1,
            DB::table('accounting_entries')->where('book_id', $c['book']['id'])->where('status', 'draft')->count(),
        );
    }

    public function test_two_concurrent_checkouts_cannot_reserve_the_same_loyalty_points_twice(): void
    {
        $c = $this->loyaltyCheckout(1);
        $first = $this->pointsQuote($c)->assertCreated()->json('data');
        $secondDelivery = $this->quote($c['cart'], $this->address('Akwa'))->assertCreated()->json('data');
        $secondDelivery = $this->api('POST', 'cart/delivery-quotes/'.$secondDelivery['id'].'/slot', [
            'version' => $secondDelivery['version'],
            'rule_id' => $c['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
        $other = [...$c, 'quote' => $secondDelivery];
        $second = $this->pointsQuote($other)->assertCreated()->json('data');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ([[$c, $first], [$other, $second]] as [$ctx, $price]) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'loyalty_checkout',
                'actor_id' => $c['client']->id,
                'command' => 'order.create',
                'key' => $this->fixtureId(),
                'data' => [
                    'quote_id' => $ctx['quote']['id'],
                    'quote_version' => $ctx['quote']['version'],
                    'accepted_total' => $price['total'],
                    'loyalty_quote_id' => $price['id'],
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('loyalty_accounts', $c['card']['id'], $race, $payloads));
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseCount('loyalty_redemptions', 1);
        $this->assertSame(
            100,
            (int) DB::table('loyalty_redemption_allocations')->where('status', 'held')->sum('points'),
        );
        $this->assertSame(0, (int) DB::table('loyalty_movements')->sum('points_delta'));
    }

    public function test_two_concurrent_checkouts_cannot_exceed_the_last_monetary_loyalty_budget(): void
    {
        $c = $this->loyaltyCheckout(1, 100, ['budget_xaf' => '500']);
        $first = $this->pointsQuote($c)->assertCreated()->json('data');
        $client = $this->client();
        $card = $this->api('POST', 'account/loyalty/card')->assertCreated()->json('data');
        $this->operationUser();
        $this->op('POST', 'loyalty/accounts/'.$card['id'].'/adjustments', [
            'points' => 100,
            'explanation' => ['fr' => 'Test uniquement.', 'en' => 'Test only.'],
        ])->assertOk();
        $this->client($client);
        $other = $this->loyaltyCart([...$c, 'client' => $client, 'card' => $card], 1);
        $second = $this->pointsQuote($other)->assertCreated()->json('data');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ([[$c, $first], [$other, $second]] as [$ctx, $price]) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'loyalty_checkout',
                'actor_id' => $ctx['client']->id,
                'command' => 'order.create',
                'key' => $this->fixtureId(),
                'data' => [
                    'quote_id' => $ctx['quote']['id'],
                    'quote_version' => $ctx['quote']['version'],
                    'accepted_total' => $price['total'],
                    'loyalty_quote_id' => $price['id'],
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('loyalty_redemption_policy', '1', $race, $payloads));
        $this->assertDatabaseCount('orders', 1);
        $this->assertSame(500, (int) DB::table('loyalty_redemptions')->where('status', 'held')->sum('discount'));
    }

    public function test_a_delivered_order_awards_loyalty_points_once_with_two_independent_workers(): void
    {
        $actor = $this->operationUser();
        $rules = $this->loyaltyRaceRules();
        $this->op('PUT', 'loyalty/program', ['version' => 1, 'enabled' => true, 'rules' => $rules])->assertOk();
        $c = $this->checkout();
        $card = $this->api('POST', 'account/loyalty/card')->assertCreated()->json('data');
        $order = $this->order($c['quote'])->assertCreated()->json('data');
        // Trusted delivery/payment facts exist only in this fixture, not in a public loyalty endpoint.
        DB::table('orders')
            ->where('id', $order['id'])
            ->update(['status' => 'paid', 'paid_at' => now(), 'delivered_at' => now()]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'loyalty_delivered',
                'actor_id' => $actor->id,
                'order_id' => $order['id'],
                'command' => 'test.loyalty.delivered',
                'key' => $this->fixtureId(),
                'data' => [],
            ];
        }
        $this->assertSame(['ok', 'ok'], $this->contend('orders', $order['id'], $race, $payloads));
        $this->assertDatabaseCount('loyalty_grants', 1);
        $this->assertSame(
            10,
            (int) DB::table('loyalty_movements')->where('account_id', $card['id'])->sum('points_delta'),
        );
        $this->assertSame(10, (int) DB::table('loyalty_program')->value('committed_points'));
        $this->assertSame(1, DB::table('loyalty_movements')->where('kind', 'acquired')->count());
    }

    public function test_two_accounts_cannot_exceed_the_last_loyalty_points_budget_with_concurrent_adjustments(): void
    {
        $actor = $this->operationUser();
        $this->op('PUT', 'loyalty/program', [
            'version' => 1,
            'enabled' => true,
            'rules' => $this->loyaltyRaceRules(),
        ])->assertOk();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $this->client();
            $card = $this->api('POST', 'account/loyalty/card')->assertCreated()->json('data');
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'loyalty_adjust',
                'actor_id' => $actor->id,
                'account_id' => $card['id'],
                'command' => 'test.loyalty.adjust:'.$card['id'],
                'key' => $this->fixtureId(),
                'data' => [
                    'points' => 10,
                    'explanation' => ['fr' => 'Correction de test.', 'en' => 'Test correction.'],
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('loyalty_program', '1', $race, $payloads));
        $this->assertSame(10, (int) DB::table('loyalty_movements')->sum('points_delta'));
        $this->assertSame(10, (int) DB::table('loyalty_program')->value('committed_points'));
    }

    private function loyaltyRaceRules(): array
    {
        return [
            'earn_step_xaf' => '1000',
            'earn_points' => 10,
            'rounding' => 'floor',
            'max_points_per_order' => 100,
            'excluded_category_ids' => [],
            'validation_delay_days' => 0,
            'points_valid_days' => null,
            'budget_points' => 10,
            'tier_window_days' => 365,
            'include_professional' => false,
            'tiers' => [['key' => 'test-only', 'label' => ['fr' => 'Test', 'en' => 'Test'], 'threshold_xaf' => '0']],
            'conditions' => ['fr' => 'Simulation uniquement.', 'en' => 'Test simulation only.'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'pgsql') {
            $this->markTestSkipped('Requires the isolated PostgreSQL database and independent PHP processes.');
        }
        $this->assertSame('kora_test', DB::selectOne('select current_database() as name')->name);
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->ownsDatabase = true;
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->ownsDatabase) {
                $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_two_processes_contend_for_the_same_stock_without_double_reservation(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx), 10);
        $this->receipt($ctx, $order, $this->location(), 10);
        $this->inventoryPolicy();
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'reserve',
                'actor_id' => $actor->id,
                'command' => 'inventory.reserve',
                'key' => $this->fixtureId(),
                'data' => ['offer_id' => $ctx['offer'], 'purpose_id' => $this->fixtureId(), 'quantity' => 7],
            ];
        }
        $results = $this->contend('offers', $ctx['offer'], $race, $payloads);
        $this->assertSame(['ok', 'rejected'], $results);
        $this->assertSame(7, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertSame(10, (int) DB::table('inventory_balances')->sum('on_hand'));
        $this->assertSame(1, DB::table('inventory_reservations')->where('status', 'active')->count());
    }

    public function test_two_verified_payment_attempts_cannot_overallocate_the_same_supplier_debt(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $invoice = $this->invoice($ctx, $order, 100);
        $account = $this->account();
        $payments = [$this->payment($ctx, $account, '60000'), $this->payment($ctx, $account, '60000')];
        $evidence = $this->evidence($ctx['supplier'], 'payment_evidence');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ($payments as $index => $payment) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'verify',
                'actor_id' => $actor->id,
                'command' => 'supplier_payment.verify:'.$payment['id'],
                'key' => $this->fixtureId(),
                'payment_id' => $payment['id'],
                'data' => [
                    'amount' => '60000',
                    'reference' => 'TEST-CONCURRENT-'.$index,
                    'evidence_id' => $evidence['id'],
                    'paid_at' => now()->subSecond()->toIso8601String(),
                    'allow_advance' => false,
                    'allocations' => [['obligation_id' => $invoice['obligation']['id'], 'amount' => '60000']],
                ],
            ];
        }
        $results = $this->contend('suppliers', $ctx['supplier'], $race, $payloads);
        $this->assertSame(['ok', 'rejected'], $results);
        $this->assertSame(60000, (int) DB::table('supplier_payment_allocations')->sum('amount'));
        $this->assertSame(1, DB::table('supplier_payments')->where('status', 'verified')->count());
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/statement')->assertJsonPath(
            'data.balances.outstanding_total',
            '40000',
        );
    }

    public function test_two_workers_deliver_one_internal_reminder_with_one_atomic_receipt(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $order = $this->purchase($ctx, $this->contract($ctx));
        $this->invoice($ctx, $order, 100);
        $this->op('PUT', 'settings/payable-reminders', [
            'enabled' => true,
            'due_soon_days' => 365,
            'local_time' => '00:00',
            'weekdays' => [1, 2, 3, 4, 5, 6, 7],
            'repeat_after_days' => 1,
            'reason' => 'Concurrent test policy only',
        ])->assertOk();
        $this->artisan('payables:scan-alerts')->assertSuccessful();
        $event = DB::table('outbox_events')->where('type', 'SupplierPayableReminder')->first();
        $this->assertNotNull($event);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'outbox',
                'actor_id' => $actor->id,
                'event_id' => $event->id,
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('outbox_events', $event->id, $race, $payloads));
        $this->assertDatabaseCount('operational_notifications', 1);
        $this->assertDatabaseCount('outbox_attempts', 1);
        $this->assertDatabaseHas('outbox_events', ['id' => $event->id, 'attempts' => 1]);
        $this->assertNotNull(DB::table('outbox_events')->where('id', $event->id)->value('processed_at'));
    }

    public function test_distinct_publication_events_contending_on_one_request_cannot_send_duplicate_account_notices(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $id = $this->fixtureId();
        DB::table('product_requests')->insert([
            'id' => $id,
            'reference' => 'TEST-'.substr($id, 0, 30),
            'user_id' => $actor->id,
            'locale' => 'fr',
            'details' => '{}',
            'state' => 'preparing',
            'linked_offer_id' => $ctx['offer'],
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            Records::event('ProductRequestOfferReady', 'TEST-'.$id.':'.$i, [
                'request_id' => $id,
                'offer_id' => $ctx['offer'],
                'proposal' => false,
            ]);
            $eventId = DB::table('outbox_events')
                ->where('deduplication_key', 'ProductRequestOfferReady:TEST-'.$id.':'.$i)
                ->value('id');
            $payloads[] = ['race_name' => $race, 'kind' => 'outbox', 'actor_id' => $actor->id, 'event_id' => $eventId];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('product_requests', $id, $race, $payloads));
        $this->assertDatabaseCount('account_notifications', 1);
        $this->assertDatabaseHas('product_requests', ['id' => $id, 'state' => 'published', 'version' => 2]);
        $this->assertDatabaseCount('outbox_attempts', 2);
    }

    public function test_two_devices_with_one_cart_version_cannot_overwrite_or_double_an_addition(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $id = $this->fixtureId();
        DB::table('carts')->insert([
            'id' => $id,
            'user_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'cart_add',
                'actor_id' => $actor->id,
                'command' => 'cart.add',
                'key' => $this->fixtureId(),
                'data' => ['version' => 1, 'offer_id' => $ctx['offer'], 'quantity' => 4],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('carts', $id, $race, $payloads));
        $this->assertDatabaseCount('cart_lines', 1);
        $this->assertDatabaseHas('cart_lines', ['cart_id' => $id, 'quantity' => 4]);
        $this->assertDatabaseHas('carts', ['id' => $id, 'version' => 2]);
        $this->assertDatabaseCount('inventory_reservations', 0);
    }

    public function test_two_customers_cannot_both_take_the_last_place_of_a_delivery_slot(): void
    {
        $date = now('Africa/Douala')->addDay();
        $zone = $this->fixtureId();
        $rule = $this->fixtureId();
        DB::table('delivery_zones')->insert([
            'id' => $zone,
            'code' => 'TEST',
            'name' => json_encode(['fr' => 'Zone test', 'en' => 'Test zone']),
            'districts' => json_encode(['Test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delivery_slot_rules')->insert([
            'id' => $rule,
            'zone_id' => $zone,
            'weekday' => $date->dayOfWeekIso,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'capacity' => 1,
            'cutoff_minutes' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $customer = User::factory()->create(['roles' => ['client']]);
            $cart = $this->fixtureId();
            $address = $this->fixtureId();
            $quote = $this->fixtureId();
            DB::table('carts')->insert([
                'id' => $cart,
                'user_id' => $customer->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('customer_addresses')->insert([
                'id' => $address,
                'user_id' => $customer->id,
                'label' => 'Test',
                'district' => 'Test',
                'landmark' => 'Test only',
                'instructions' => '',
                'contact_name' => 'Test only',
                'contact_phone' => '600000000',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('delivery_quotes')->insert([
                'id' => $quote,
                'user_id' => $customer->id,
                'cart_id' => $cart,
                'cart_version' => 1,
                'address_id' => $address,
                'address_snapshot' => json_encode(['label' => 'Test', 'district' => 'Test']),
                'zone_id' => $zone,
                'status' => 'quoted',
                'reasons' => '[]',
                'plan' => json_encode(['lines' => [], 'points' => []]),
                'breakdown' => '[]',
                'total' => 1000,
                'demo_amounts' => true,
                'ready_after_minutes' => 0,
                'expires_at' => now()->addMinutes(30),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'slot_hold',
                'actor_id' => $customer->id,
                'quote_id' => $quote,
                'command' => 'delivery.slot:'.$quote,
                'key' => $this->fixtureId(),
                'data' => ['rule_id' => $rule, 'date' => $date->toDateString()],
            ];
        }
        DB::table('delivery_slot_locks')->insert([
            'rule_id' => $rule,
            'date' => $date->toDateString(),
            'created_at' => now(),
        ]);
        $lock = fn () => DB::table('delivery_slot_locks')
            ->where('rule_id', $rule)
            ->where('date', $date->toDateString())
            ->lockForUpdate()
            ->first();

        $this->assertSame(['ok', 'rejected'], $this->contend('delivery_slot_locks', '', $race, $payloads, $lock));
        $this->assertSame(
            1,
            DB::table('delivery_quotes')->where('slot_rule_id', $rule)->where('slot_status', 'held')->count(),
        );
    }

    public function test_two_invoices_issued_at_the_same_time_receive_distinct_numbers(): void
    {
        $actor = $this->operationUser('accountant');
        $year = now('Africa/Douala')->year;
        DB::table('document_sequences')->insert([
            'key' => 'demo-invoice:'.$year,
            'last_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (['CMD-TEST-A', 'CMD-TEST-B'] as $number) {
            $order = $this->paidOrderFixture($number);
            $document = $this->fixtureId();
            DB::table('customer_documents')->insert([
                'id' => $document,
                'order_id' => $order,
                'kind' => 'invoice',
                'status' => 'draft',
                'total' => 3000,
                'demo' => true,
                'created_by' => $actor->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'document_issue',
                'actor_id' => $actor->id,
                'order_id' => $order,
                'document_id' => $document,
                'command' => 'document.issue:'.$document,
                'key' => $this->fixtureId(),
                'data' => ['version' => 1],
            ];
        }
        $lock = fn () => DB::table('document_sequences')
            ->where('key', 'demo-invoice:'.$year)
            ->lockForUpdate()
            ->first();

        // Both are issued, one after the other, each with its own number and audit entry.
        $this->assertSame(['ok', 'ok'], $this->contend('document_sequences', '', $race, $payloads, $lock));
        $numbers = DB::table('customer_documents')->orderBy('number')->pluck('number')->all();
        $this->assertSame(['DEMO-FAC-'.$year.'-000001', 'DEMO-FAC-'.$year.'-000002'], $numbers);
        $this->assertSame(2, DB::table('audit_events')->where('action', 'document.issued')->count());
    }

    public function test_two_customers_cannot_both_take_the_last_delivery_advantage_of_a_budget(): void
    {
        $this->deliveryOfferRace(false);
    }

    public function test_two_orders_cannot_use_the_same_customers_last_delivery_advantage(): void
    {
        $this->deliveryOfferRace(true);
    }

    public function test_two_customer_choices_for_one_missing_line_cannot_create_conflicting_resolutions(): void
    {
        $this->operationUser();
        $catalogue = $this->fixtureCatalogue();
        $orderId = $this->paidOrderFixture('CMD-SUPPLY-RACE');
        $customer = User::findOrFail(DB::table('orders')->where('id', $orderId)->value('user_id'));
        $lineId = $this->fixtureId();
        $caseId = $this->fixtureId();
        DB::table('order_lines')->insert([
            'id' => $lineId,
            'order_id' => $orderId,
            'offer_id' => $catalogue['offer'],
            'quantity' => 1,
            'unit_price' => 3000,
            'line_total' => 3000,
            'snapshot' => '{}',
            'internal_snapshot' => '{}',
            'from_warehouse' => 0,
            'from_supplier' => 1,
            'supply_status' => 'unavailable',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_supply_cases')->insert([
            'id' => $caseId,
            'order_id' => $orderId,
            'order_line_id' => $lineId,
            'sequence' => 1,
            'missing_quantity' => 1,
            'message' => json_encode(['fr' => 'Test', 'en' => 'Test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (['wait', 'refund_missing'] as $choice) {
            $payloads[] = [
                'kind' => 'supply_choice',
                'race_name' => $race,
                'actor_id' => $customer->id,
                'order_id' => $orderId,
                'case_id' => $caseId,
                'command' => 'order.supply_choice:'.$caseId,
                'key' => $this->fixtureId(),
                'data' => ['version' => 1, 'choice' => $choice, 'accepted_refund_amount' => '3000'],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('orders', $orderId, $race, $payloads));
        $case = DB::table('order_supply_cases')->where('id', $caseId)->first();
        $this->assertSame(2, (int) $case->version);
        $this->assertSame(
            $case->choice === 'wait' ? 0 : 1,
            DB::table('customer_refunds')->where('order_id', $orderId)->count(),
        );
        $this->assertSame(
            1,
            DB::table('order_events')->where('order_id', $orderId)->where('action', 'supply_customer_choice')->count(),
        );
    }

    public function test_two_orders_cannot_both_take_the_last_unit_of_a_supplier_purchase(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $purchase = $this->purchase($ctx, $this->contract($ctx), 1, true, 'customer_needs');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (['CMD-BUY-A', 'CMD-BUY-B'] as $number) {
            [$order, $line] = $this->supplierOrderFixture($ctx, $number);
            $payloads[] = [
                'kind' => 'purchase_assign',
                'race_name' => $race,
                'actor_id' => $actor->id,
                'order_id' => $order,
                'line_id' => $line,
                'command' => 'order.purchase_assign:'.$line,
                'key' => $this->fixtureId(),
                'data' => ['version' => 1, 'purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 1],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('suppliers', $ctx['supplier'], $race, $payloads));
        $this->assertSame(1, DB::table('order_purchase_allocations')->count());
        $this->assertSame(1, (int) DB::table('order_purchase_allocations')->sum('quantity'));
    }

    public function test_two_orders_cannot_both_hold_the_only_unit_actually_received(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $purchase = $this->purchase($ctx, $this->contract($ctx), 2, true, 'customer_needs');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach (['CMD-RECEIVE-A', 'CMD-RECEIVE-B'] as $number) {
            [$order, $line] = $this->supplierOrderFixture($ctx, $number);
            $allocation = DB::transaction(
                fn () => app(OrderPurchases::class)->assign(
                    Records::row('orders', $order),
                    $line,
                    ['version' => 1, 'purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 1],
                    $actor,
                ),
            );
            $payloads[] = [
                'kind' => 'purchase_receive',
                'race_name' => $race,
                'actor_id' => $actor->id,
                'order_id' => $order,
                'allocation_id' => $allocation['id'],
                'command' => 'order.receipt_assign:'.$allocation['id'],
                'key' => $this->fixtureId(),
            ];
        }
        $receipt = $this->receipt($ctx, $purchase, $this->location(), 1);
        $receiptLine = DB::table('stock_lots')->where('id', $receipt['lot_ids'][0])->value('receipt_line_id');
        foreach ($payloads as &$payload) {
            $payload['data'] = ['version' => 1, 'receipt_line_id' => $receiptLine, 'quantity' => 1];
        }
        unset($payload);
        $this->assertSame(['ok', 'rejected'], $this->contend('suppliers', $ctx['supplier'], $race, $payloads));
        $this->assertSame(1, DB::table('order_receipt_allocations')->count());
        $this->assertSame(1, (int) DB::table('inventory_balances')->sum('reserved'));
    }

    public function test_a_received_unit_is_either_issued_for_preparation_or_released_never_both(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $purchase = $this->purchase($ctx, $this->contract($ctx), 1, true, 'customer_needs');
        [$order, $line] = $this->supplierOrderFixture($ctx, 'CMD-PREPARE-RACE');
        $allocation = DB::transaction(
            fn () => app(OrderPurchases::class)->assign(
                Records::row('orders', $order),
                $line,
                ['version' => 1, 'purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => 1],
                $actor,
            ),
        );
        $receipt = $this->receipt($ctx, $purchase, $this->location(), 1);
        $held = DB::transaction(
            fn () => app(OrderPurchases::class)->receive(
                Records::row('orders', $order),
                $allocation['id'],
                [
                    'version' => 1,
                    'receipt_line_id' => DB::table('stock_lots')
                        ->where('id', $receipt['lot_ids'][0])
                        ->value('receipt_line_id'),
                    'quantity' => 1,
                ],
                $actor,
            ),
        );
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [
            [
                'kind' => 'preparation_pick',
                'race_name' => $race,
                'actor_id' => $actor->id,
                'order_id' => $order,
                'command' => 'order.preparation_pick:'.$order,
                'key' => $this->fixtureId(),
                'data' => ['version' => 1, 'reservation_id' => $held['reservation_id'], 'identifiers' => []],
            ],
            [
                'kind' => 'receipt_release',
                'race_name' => $race,
                'actor_id' => $actor->id,
                'order_id' => $order,
                'receipt_id' => $held['id'],
                'command' => 'order.receipt_release:'.$held['id'],
                'key' => $this->fixtureId(),
                'data' => ['version' => 1, 'reason' => 'Concurrent test release'],
            ],
        ];
        $statuses = $this->contend('orders', $order, $race, $payloads);
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $picked = DB::table('order_preparation_picks')->count();
        $released = DB::table('order_receipt_allocations')->where('status', 'released')->count();
        $this->assertSame(1, $picked + $released);
        $this->assertSame(
            $picked === 1 ? 'consumed' : 'released',
            DB::table('inventory_reservations')->where('id', $held['reservation_id'])->value('status'),
        );
        $this->assertSame(0, (int) DB::table('inventory_balances')->sum('reserved'));
        $this->assertSame(1 - $picked, (int) DB::table('inventory_balances')->sum('on_hand'));
        // The picking list reads the same order whatever the winner, not only when the pick won the race.
        $view = app(OrderPreparation::class)->view(Records::row('orders', $order, false));
        $this->assertSame($picked, $view['lines'][0]['prepared_quantity']);
    }

    public function test_a_handover_and_a_reassignment_cannot_both_win(): void
    {
        $this->useFakeGateway();
        $o = $this->warehouseOrder(1);
        $this->readyOrder($o['order']);
        $first = User::factory()->create(['roles' => ['courier']]);
        $second = User::factory()->create(['roles' => ['courier']]);
        $owner = $this->operationUser();
        foreach ([$first, $second] as $i => $courier) {
            $this->op('POST', 'couriers', [
                'user_id' => $courier->id,
                'public_name' => 'Test courier '.$i,
                'badge' => 'TEST-RACE-'.$i,
            ])->assertCreated();
        }
        $mission = $this->op('POST', 'orders/'.$o['order']['id'].'/missions')
            ->assertCreated()
            ->json('data');
        $mission = $this->op('POST', 'missions/'.$mission['id'].'/assign', [
            'version' => $mission['version'],
            'courier_id' => $first->id,
            'acceptance_required' => false,
        ])->json('data');
        $mission = DB::transaction(
            fn () => app(DeliveryMissions::class)->pickup(
                $mission['id'],
                ['version' => $mission['version'], 'packages' => 1],
                $first,
            ),
        );
        $this->client($o['client']);
        $code = $this->getJson('/api/v1/orders/'.$o['order']['id'])->json('data.delivery.mission.handover_code');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [
            [
                'kind' => 'mission_handover',
                'race_name' => $race,
                'actor_id' => $first->id,
                'mission_id' => $mission['id'],
                'command' => 'courier.handover:'.$mission['id'],
                'key' => $this->fixtureId(),
                'data' => [
                    'version' => $mission['version'],
                    'code' => $code,
                    'packages' => 1,
                    'items' => collect($mission['items'])
                        ->map(
                            fn ($i) => [
                                'order_line_id' => $i['order_line_id'],
                                'quantity' => $i['quantity'],
                                'identifiers' => $i['identifiers'],
                            ],
                        )
                        ->all(),
                ],
            ],
            [
                'kind' => 'mission_assign',
                'race_name' => $race,
                'actor_id' => $owner->id,
                'mission_id' => $mission['id'],
                'command' => 'delivery.mission_assign:'.$mission['id'],
                'key' => $this->fixtureId(),
                'data' => [
                    'version' => $mission['version'],
                    'courier_id' => $second->id,
                    'acceptance_required' => false,
                    'note' => 'Concurrent test handover',
                ],
            ],
        ];
        $statuses = $this->contend('orders', $o['order']['id'], $race, $payloads);
        sort($statuses);
        $this->assertSame(['ok', 'rejected'], $statuses);
        $row = DB::table('delivery_missions')->where('id', $mission['id'])->first();
        $delivered = DB::table('orders')->where('id', $o['order']['id'])->value('delivered_at') !== null;
        // Either the first courier delivered and nobody else holds the mission, or it moved on undelivered.
        $this->assertSame($delivered, $row->status === 'delivered');
        $this->assertSame($delivered ? $first->id : $second->id, (int) $row->courier_id);
        $this->assertSame(
            $delivered ? 1 : 0,
            DB::table('delivery_handover_attempts')->where('accepted', true)->count(),
        );
    }

    public function test_two_collections_cannot_promise_the_same_purchase_units(): void
    {
        $actor = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $purchase = $this->purchase($ctx, $this->contract($ctx), 3);
        $location = $this->location();
        $zone = $this->zone('SOUTH', ['Akwa']);
        $this->point($zone, ['shop_id' => $ctx['shop']]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        foreach ([2, 2] as $quantity) {
            $payloads[] = [
                'kind' => 'collection_create',
                'race_name' => $race,
                'actor_id' => $actor->id,
                'purchase_id' => $purchase['id'],
                'command' => 'delivery.collection_create:'.$purchase['id'],
                'key' => $this->fixtureId(),
                'data' => [
                    'shop_id' => $ctx['shop'],
                    'warehouse_id' => $location['warehouse_id'],
                    'lines' => [['purchase_line_id' => $purchase['lines'][0]['id'], 'quantity' => $quantity]],
                ],
            ];
        }
        $this->assertSame(['ok', 'rejected'], $this->contend('purchase_orders', $purchase['id'], $race, $payloads));
        $this->assertSame(2, (int) DB::table('supplier_collection_lines')->sum('expected_quantity'));
    }

    private function supplierOrderFixture(array $ctx, string $number): array
    {
        $order = $this->paidOrderFixture($number);
        $line = $this->fixtureId();
        $confirmation = $this->fixtureId();
        $offer = Records::row('offers', $ctx['offer'], false);
        $variant = Records::row('variants', $ctx['variant'], false);
        DB::table('order_lines')->insert([
            'id' => $line,
            'order_id' => $order,
            'offer_id' => $ctx['offer'],
            'quantity' => 1,
            'unit_price' => 3000,
            'line_total' => 3000,
            // Same keys as a line written by checkout: readers such as the picking list rely on them.
            'snapshot' => Records::json([
                'variant_id' => $ctx['variant'],
                'shop_id' => $ctx['shop'],
                'name' => json_decode(DB::table('products')->where('id', $variant->product_id)->value('name'), true),
                'shop' => (array) DB::table('shops')
                    ->where('id', $ctx['shop'])
                    ->first(['id', 'name', 'slug']),
                'sku' => $variant->sku,
                'attributes' => json_decode($variant->attributes, true),
                'warranty' => json_decode($offer->warranty, true),
            ]),
            'internal_snapshot' => Records::json(['supplier_id' => $ctx['supplier']]),
            'from_warehouse' => 0,
            'from_supplier' => 1,
            'supply_status' => 'supplier_confirmed',
            'supplier_confirmed_quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $actor = DB::table('orders')->where('id', $order)->value('user_id');
        DB::table('supplier_reservations')->insert([
            'id' => $confirmation,
            'offer_id' => $ctx['offer'],
            'purpose_id' => $line,
            'order_line_id' => $line,
            'quantity' => 1,
            'confirmed_quantity' => 1,
            'status' => 'confirmed',
            'expires_at' => now()->addHour(),
            'confirmation_reference' => 'TEST EXTERNAL CONFIRMATION',
            'confirmed_at' => now(),
            'created_by' => $actor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_lines')
            ->where('id', $line)
            ->update(['supplier_reservation_id' => $confirmation]);

        return [$order, $line];
    }

    public function test_two_concurrent_conversions_of_one_professional_quote_create_one_order_and_one_slot_hold(): void
    {
        $this->useFakeGateway();
        $c = $this->professionalRequest(1);
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->client($c['client']);
        $accepted = $this->acceptProfessional($c, $issued)->assertOk()->json('data');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'kind' => 'professional_convert',
                'race_name' => $race,
                'actor_id' => $c['client']->id,
                'profile_id' => $c['profile']['id'],
                'quote_id' => $c['quote']['id'],
                'command' => 'professional.quote_convert:'.$c['quote']['id'],
                'key' => $this->fixtureId(),
                'data' => [
                    'version' => $accepted['version'],
                    'accepted_total' => '2500',
                    'rule_id' => $c['rule']['id'],
                    'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
                ],
            ];
        }
        $this->assertSame(['ok', 'ok'], $this->contend('professional_profiles', $c['profile']['id'], $race, $payloads));
        $this->assertSame(1, DB::table('orders')->count());
        $this->assertSame(1, DB::table('professional_quote_events')->where('action', 'converted')->count());
        $this->assertSame(1, DB::table('delivery_quotes')->where('slot_status', 'held')->count());
        $this->assertSame(DB::table('orders')->value('id'), DB::table('professional_quotes')->value('order_id'));
    }

    private function deliveryOfferRace(bool $sameCustomer): void
    {
        $actor = $this->operationUser();
        $zone = $this->fixtureId();
        $offer = $this->fixtureId();
        DB::table('delivery_zones')->insert([
            'id' => $zone,
            'code' => 'TEST',
            'name' => json_encode(['fr' => 'Zone test', 'en' => 'Test zone']),
            'districts' => json_encode(['Test']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // Either the shared budget or the customer's last use is the limiting resource.
        DB::table('delivery_offers')->insert([
            'id' => $offer,
            'name' => json_encode(['fr' => 'Test', 'en' => 'Test']),
            'status' => 'active',
            'starts_at' => now()->subHour(),
            'zone_ids' => json_encode([$zone]),
            'min_unit_price' => 5000,
            'uses_per_customer' => $sameCustomer ? 1 : 3,
            'coverage' => 'full',
            'budget' => $sameCustomer ? 3000 : 1500,
            'funded_by' => 'kora',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        $customer = $sameCustomer ? User::factory()->create(['roles' => ['client']]) : null;
        foreach (['CMD-TEST-X', 'CMD-TEST-Y'] as $number) {
            $order = $this->paidOrderFixture($number, $customer);
            DB::table('orders')
                ->where('id', $order)
                ->update([
                    'status' => 'awaiting_payment',
                    'paid_at' => null,
                    'payment_expires_at' => now()->addMinutes(10),
                ]);
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'offer_reserve',
                'actor_id' => $actor->id,
                'order_id' => $order,
                'zone_id' => $zone,
                'command' => 'offer.reserve:'.$order,
                'key' => $this->fixtureId(),
                'data' => [],
            ];
        }

        $this->assertSame(['ok', 'rejected'], $this->contend('delivery_offers', $offer, $race, $payloads));
        $this->assertSame(1500, (int) DB::table('delivery_offer_usages')->where('offer_id', $offer)->sum('amount'));
        $this->assertSame(1, DB::table('delivery_offer_usages')->where('offer_id', $offer)->count());
    }

    public function test_two_concurrent_partial_refunds_cannot_exceed_the_money_collected(): void
    {
        $actor = $this->operationUser('accountant');
        $order = $this->paidOrderFixture('CMD-TEST-1');
        $race = 'kora-test-'.$this->fixtureId();
        $payloads = [];
        for ($i = 0; $i < 2; $i++) {
            $payloads[] = [
                'race_name' => $race,
                'kind' => 'refund_request',
                'actor_id' => $actor->id,
                'order_id' => $order,
                'command' => 'refund.request:'.$order,
                'key' => $this->fixtureId(),
                'data' => ['amount' => 2000],
            ];
        }
        // Each request alone fits in 3 000; together they would not.
        $this->assertSame(['ok', 'rejected'], $this->contend('orders', $order, $race, $payloads));
        $this->assertSame(2000, (int) DB::table('customer_refunds')->where('order_id', $order)->sum('amount'));
    }

    public function test_reporting_snapshot_does_not_mix_an_update_committed_halfway(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        config(['database.connections.proof_writer' => config('database.connections.pgsql')]);
        try {
            $observed = app(ReadSnapshot::class)->run(function () use ($ctx) {
                $this->assertSame(
                    'repeatable read',
                    DB::selectOne('SHOW transaction_isolation')->transaction_isolation,
                );
                $this->assertSame('on', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
                $first = DB::table('suppliers')->where('id', $ctx['supplier'])->value('name');
                DB::connection('proof_writer')
                    ->table('suppliers')
                    ->where('id', $ctx['supplier'])
                    ->update(['name' => 'Updated by second test connection']);

                return [$first, DB::table('suppliers')->where('id', $ctx['supplier'])->value('name')];
            });
            $this->assertSame(['Test only', 'Test only'], $observed);
            $this->assertSame(
                'Updated by second test connection',
                DB::table('suppliers')->where('id', $ctx['supplier'])->value('name'),
            );
            $this->assertSame('off', DB::selectOne('SHOW transaction_read_only')->transaction_read_only);
        } finally {
            DB::purge('proof_writer');
        }
    }

    /** A minimal paid order of 3 000 XAF inserted directly, with its confirmed payment. */
    private function paidOrderFixture(string $number, ?User $customer = null): string
    {
        $customer ??= User::factory()->create(['roles' => ['client']]);
        $cart = DB::table('carts')->where('user_id', $customer->id)->value('id');
        if (! $cart) {
            $cart = $this->fixtureId();
            DB::table('carts')->insert([
                'id' => $cart,
                'user_id' => $customer->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $address = $this->fixtureId();
        $quote = $this->fixtureId();
        $order = $this->fixtureId();
        DB::table('customer_addresses')->insert([
            'id' => $address,
            'user_id' => $customer->id,
            'label' => 'Test',
            'district' => 'Test',
            'landmark' => 'Test only',
            'instructions' => '',
            'contact_name' => 'Test only',
            'contact_phone' => '600000000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('delivery_quotes')->insert([
            'id' => $quote,
            'user_id' => $customer->id,
            'cart_id' => $cart,
            'cart_version' => 1,
            'address_id' => $address,
            'address_snapshot' => '{}',
            'status' => 'quoted',
            'reasons' => '[]',
            'plan' => '{"lines":[],"points":[]}',
            'breakdown' => '[]',
            'total' => 0,
            'demo_amounts' => true,
            'expires_at' => now()->addMinutes(30),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('orders')->insert([
            'id' => $order,
            'number' => $number,
            'user_id' => $customer->id,
            'cart_id' => $cart,
            'quote_id' => $quote,
            'status' => 'paid',
            'items_total' => 3000,
            'delivery_total' => 0,
            'total' => 3000,
            'currency' => 'XAF',
            'locale' => 'fr',
            'address_snapshot' => '{}',
            'delivery_snapshot' => '{"breakdown":[],"slot":{"rule_id":null,"date":null}}',
            'payment_expires_at' => now(),
            'paid_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('payment_attempts')->insert([
            'id' => $this->fixtureId(),
            'order_id' => $order,
            'request_key' => $this->fixtureId(),
            'provider' => 'monetbil',
            'status' => 'confirmed',
            'amount' => 3000,
            'received_amount' => 3000,
            'currency' => 'XAF',
            'testmode' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $order;
    }

    private function contend(string $table, string $id, string $race, array $payloads, ?\Closure $lock = null): array
    {
        // Committed fixtures are visible to both workers. Hold their common row
        // until pg_stat_activity proves that both independent sessions are waiting.
        $this->assertSame(0, DB::transactionLevel());
        DB::beginTransaction();
        $lock ? $lock() : DB::table($table)->where('id', $id)->lockForUpdate()->first();
        try {
            foreach ($payloads as $payload) {
                $worker = new Process(
                    [
                        PHP_BINARY,
                        base_path('tests/Support/transaction-worker.php'),
                        base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)),
                    ],
                    base_path(),
                    [
                        'APP_ENV' => 'testing',
                        'DB_CONNECTION' => 'pgsql',
                        'DB_HOST' => '127.0.0.1',
                        'DB_PORT' => '55432',
                        'DB_DATABASE' => 'kora_test',
                        'DB_USERNAME' => 'kora_dev',
                        'DB_PASSWORD' => '',
                        'DB_URL' => '',
                        'CACHE_STORE' => 'array',
                        'SESSION_DRIVER' => 'array',
                        'QUEUE_CONNECTION' => 'sync',
                        'MAIL_MAILER' => 'array',
                    ],
                );
                $worker->setTimeout(180);
                $worker->start();
                $this->workers[] = $worker;
            }
            // A cold PHP boot on a busy synced Windows folder can exceed thirty seconds.
            // Correctness is still asserted below: both sessions must be seen waiting on the lock.
            $deadline = microtime(true) + 90;
            $waiting = 0;
            do {
                DB::select('select pg_stat_clear_snapshot()');
                $waiting = (int) DB::selectOne(
                    "select count(*) as count from pg_stat_activity where application_name = ? and wait_event_type = 'Lock'",
                    [$race],
                )->count;
                if ($waiting === 2) {
                    break;
                }
                usleep(100000);
            } while (microtime(true) < $deadline);
            $diagnostic = implode(
                "\n",
                array_map(fn ($worker) => $worker->getOutput().$worker->getErrorOutput(), $this->workers),
            );
            $this->assertSame(
                2,
                $waiting,
                'Both independent PostgreSQL sessions must actually contend for the locked row. '.$diagnostic,
            );
        } finally {
            DB::commit();
        }
        $statuses = [];
        foreach ($this->workers as $worker) {
            $code = $worker->wait();
            $result = json_decode($worker->getOutput(), true);
            $this->assertSame(0, $code, $worker->getOutput().$worker->getErrorOutput());
            $this->assertContains($result['status'] ?? null, ['ok', 'rejected']);
            $statuses[] = $result['status'];
        }
        sort($statuses);

        return $statuses;
    }
}
