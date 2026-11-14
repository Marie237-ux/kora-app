<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ExpenseFixtures;
use Tests\Support\FinancingFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\TestCase;

final class FinancingTest extends TestCase
{
    use AccountingFixtures, ExpenseFixtures, FinancingFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
    }

    private function summary(array $c): array
    {
        return $this->op('GET', $c['treasury_root'].'/financing-summary')
            ->assertOk()
            ->json('data');
    }

    private function cancel(array $c, array $f, string $amount)
    {
        return $this->op('POST', $c['treasury_root'].'/financings/'.$f['id'].'/cancel', [
            'version' => $f['version'],
            'sha256' => $f['sha256'],
            'date' => '2026-11-05',
            'note' => 'Test',
            'document_id' => $c['evidence']['id'],
            'accepted_undrawn' => $amount,
        ]);
    }

    private function reverse(array $c, array $f, array $m)
    {
        return $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements/'.$m['id'].'/reverse',
            $this->financingReversal($c, $m),
        );
    }

    private function guard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL accepted an invalid financing mutation.');
        } catch (QueryException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_only_principal_actually_received_becomes_a_repayment_commitment_without_a_second_posting(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c);
        $this->assertSame('0', $this->summary($c)['totals']['outstanding_principal']);
        $this->financingMovement($c, $f, $this->financingEntry($c));
        $this->assertSame('600', $this->summary($c)['totals']['outstanding_principal']);
        $this->financingMovement($c, $f, $this->financingEntry($c, '200', 'repayment'), [
            'direction' => 'repayment',
            'reference' => 'TEST-REPAY',
        ]);
        $s = $this->summary($c);
        $this->assertSame('400', $s['totals']['outstanding_principal']);
        $this->assertSame('400', $s['totals']['undrawn_principal']);
        $this->assertDatabaseCount('accounting_entries', 2);
        $this->op('GET', $c['treasury_root'].'/forecast?from=2026-11-05&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.financing.totals.outstanding_principal', '400')
            ->assertJsonPath('data.totals.known_outflows', '400')
            ->assertJsonPath('data.cash.free_cash', null);
    }

    public function test_forecast_classifies_actual_principal_by_maturity_with_inclusive_boundaries_and_no_invented_dates(): void
    {
        $c = $this->financingContext();
        foreach (
            [
                '2026-11-05' => '100',
                '2026-11-10' => '200',
                '2026-11-20' => '300',
                '2026-11-21' => '400',
                'undated' => '500',
            ] as $due => $amount
        ) {
            $f = $this->financing($c, [
                'reference' => 'TEST-DUE-'.$due,
                'due_on' => $due === 'undated' ? null : $due,
            ]);
            $this->financingMovement($c, $f, $this->financingEntry($c, $amount), ['reference' => 'TEST-FUND-'.$due]);
        }
        $this->financing($c, ['reference' => 'TEST-NOT-RECEIVED']);
        $this->travelTo(CarbonImmutable::parse('2026-11-06T11:00:00Z'));
        $this->operationUser('accountant');
        $this->op('GET', $c['treasury_root'].'/forecast?from=2026-11-10&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.financing.forecast.before_window', '100')
            ->assertJsonPath('data.financing.forecast.within_window', '500')
            ->assertJsonPath('data.financing.forecast.after_window', '400')
            ->assertJsonPath('data.financing.forecast.undated', '500')
            ->assertJsonPath('data.financing.forecast.past_due', '100')
            ->assertJsonPath('data.totals.known_outflows', '1500');
        $this->op('GET', $c['treasury_root'].'/financing-summary?as_of=2026-11-04')->assertUnprocessable();
    }

    public function test_capital_contributions_do_not_become_loan_repayments(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c, [
            'kind' => 'capital_contribution',
            'account_id' => $c['accounts']['77']['id'],
            'due_on' => null,
        ]);
        $this->financingMovement($c, $f, $this->financingEntry($c, '600', 'funding', '77'));
        $this->assertSame('0', $this->summary($c)['totals']['outstanding_principal']);
        $this->assertSame('600', $this->summary($c)['totals']['capital_received']);
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $this->financingEntry($c, '100', 'repayment', '77'), [
                'direction' => 'repayment',
            ]),
        )->assertUnprocessable();
    }

    public function test_principal_limits_require_review_and_never_revolve_after_repayment(): void
    {
        $c = $this->financingContext();
        $pending = $this->financing($c, approve: false);
        $e = $this->financingEntry($c, '1000');
        $this->operationUser('accountant');
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$pending['id'].'/movements',
            $this->financingMovementData($c, $e, ['version' => 1]),
        )->assertUnprocessable();
        $f = $this->op('POST', $c['treasury_root'].'/financings/'.$pending['id'].'/approve', [
            'version' => 1,
            'sha256' => $pending['sha256'],
            'date' => '2026-11-05',
            'note' => 'Test',
        ])
            ->assertOk()
            ->json('data');
        $repay = $this->financingEntry($c, '1000', 'repayment');
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $repay, ['direction' => 'repayment']),
        )->assertUnprocessable();
        $this->financingMovement($c, $f, $e);
        $this->financingMovement($c, $f, $repay, ['direction' => 'repayment', 'reference' => 'TEST-REPAY']);
        $this->assertSame('0', $this->summary($c)['totals']['outstanding_principal']);
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $this->financingEntry($c, '1'), ['reference' => 'TEST-OVER']),
        )->assertUnprocessable();
    }

    public function test_cancellation_only_removes_undrawn_funding_and_keeps_received_principal_payable(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c, ['kind' => 'founder_advance']);
        $this->financingMovement($c, $f, $this->financingEntry($c));
        $this->cancel($c, $f, '1000')->assertConflict();
        $f = $this->cancel($c, $f, '400')->assertOk()->json('data');
        $this->assertSame('600', $f['balance']['outstanding_principal']);
        $this->assertSame('400', $f['balance']['cancelled_undrawn']);
        $this->assertSame('0', $f['balance']['pending_funding']);
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $this->financingEntry($c, '100'), [
                'version' => 3,
                'reference' => 'TEST-NEW',
            ]),
        )->assertUnprocessable();
        $this->financingMovement($c, $f, $this->financingEntry($c, '200', 'repayment'), [
            'version' => 3,
            'direction' => 'repayment',
            'reference' => 'TEST-REPAY',
        ]);
        $this->assertSame('400', $this->summary($c)['totals']['outstanding_principal']);
        $this->assertSame('400', $this->summary($c)['by_kind']['founder_advance']['outstanding_principal']);
    }

    public function test_funding_corrections_cannot_leave_repayments_above_net_funding_and_counters_are_exact(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c);
        $e = $this->financingEntry($c);
        $in = $this->financingMovement($c, $f, $e);
        $out = $this->financingMovement($c, $f, $this->financingEntry($c, '200', 'repayment'), [
            'direction' => 'repayment',
            'reference' => 'TEST-REPAY',
        ]);
        $this->reverse($c, $f, $in)->assertUnprocessable();
        $this->guard(
            fn () => DB::table('financing_movements')
                ->where('id', $in['id'])
                ->update(['status' => 'reversing']),
        );
        $this->op('POST', $c['root'].'/entries/'.$e['id'].'/reverse', [
            ...$this->financingReversal($c, $in),
            'version' => 2,
        ])->assertUnprocessable();
        $this->reverse($c, $f, $out)->assertOk();
        DB::table('accounting_periods')
            ->where('id', $c['period']['id'])
            ->update(['status' => 'closed']);
        $this->reverse($c, $f, $in)->assertUnprocessable();
        $this->assertDatabaseHas('financing_movements', ['id' => $in['id'], 'status' => 'recorded']);
        DB::table('accounting_periods')
            ->where('id', $c['period']['id'])
            ->update(['status' => 'open']);
        $r = $this->reverse($c, $f, $in)->assertOk()->assertJsonPath('data.snapshot', $in['snapshot'])->json('data');
        $this->assertDatabaseHas('accounting_entries', ['id' => $r['reversal_entry_id'], 'reversal_of' => $e['id']]);
        $this->assertSame(
            '600',
            (string) DB::table('accounting_entry_lines')
                ->where('entry_id', $r['reversal_entry_id'])
                ->where('account_id', $c['accounts']['11']['id'])
                ->value('credit'),
        );
        $this->assertSame('0', $this->summary($c)['totals']['outstanding_principal']);
        $this->op('POST', $c['root'].'/entries/'.$r['reversal_entry_id'].'/reverse', [
            ...$this->financingReversal($c, $in),
            'version' => 2,
        ])->assertUnprocessable();
    }

    public function test_cancelled_funding_can_only_replace_its_own_reversed_movement_without_increasing_it(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c);
        $m = $this->financingMovement($c, $f, $this->financingEntry($c));
        $f = $this->cancel($c, $f, '400')->assertOk()->json('data');
        $this->reverse($c, $f, $m)->assertOk();
        $this->assertSame('0', $this->summary($c)['totals']['pending_funding']);
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $this->financingEntry($c, '700'), [
                'version' => 3,
                'replaces_id' => $m['id'],
            ]),
        )->assertUnprocessable();
        $r = $this->financingMovement($c, $f, $this->financingEntry($c), ['version' => 3, 'replaces_id' => $m['id']]);
        $this->assertSame(2, $r['revision']);
        $this->assertSame($m['id'], $r['snapshot']['replaces']['id']);
        $this->assertSame('600', $this->summary($c)['totals']['outstanding_principal']);
        $this->assertSame('400', $this->summary($c)['totals']['cancelled_undrawn']);
    }

    public function test_an_existing_principal_entry_cannot_be_reused_as_an_expense_in_either_direction(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c);
        $this->financingMovement($c, $f, $this->financingEntry($c));
        $e = $this->financingEntry($c, '200', 'repayment');
        $this->financingMovement($c, $f, $e, ['direction' => 'repayment', 'reference' => 'TEST-REPAY']);
        $p = $this->expensePlan($c);
        $body = [
            'version' => 2,
            'binding_id' => $c['binding']['id'],
            'entry_id' => $e['id'],
            'ledger_line_id' => $e['cash_line_id'],
            'accepted_amount' => '200',
            'paid_at' => '2026-11-05T09:00:00Z',
            'document_id' => $c['evidence']['id'],
            'note' => 'Test',
        ];
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', $body)->assertUnprocessable();
        $other = $this->financingEntry($c, '100', 'repayment');
        $payment = $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', [
            ...$body,
            'entry_id' => $other['id'],
            'ledger_line_id' => $other['cash_line_id'],
            'accepted_amount' => '100',
        ])
            ->assertCreated()
            ->json('data');
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $other, ['direction' => 'repayment', 'reference' => 'TEST-SECOND']),
        )->assertUnprocessable();
        $row = (array) DB::table('expense_payments')->where('id', $payment['id'])->first();
        $this->guard(
            fn () => DB::table('expense_payments')->insert([
                ...$row,
                'id' => $this->fixtureId(),
                'entry_id' => $e['id'],
                'ledger_line_id' => $e['cash_line_id'],
                'amount' => 200,
            ]),
        );
    }

    public function test_cash_matches_must_be_voided_before_correction_even_if_the_counter_would_be_later(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c);
        $e = $this->financingEntry($c);
        $m = $this->financingMovement($c, $f, $e);
        $s = $this->treasuryStatement($c, [
            'ends_on' => '2026-11-05',
            'closing_balance' => '600',
            'lines' => [
                [
                    'external_id' => 'TEST-FINANCE',
                    'booked_on' => '2026-11-05',
                    'reference' => 'TEST',
                    'direction' => 'in',
                    'amount' => '600',
                ],
            ],
        ]);
        $match = $this->op(
            'POST',
            $c['treasury_root'].'/statements/'.$s['id'].'/matches',
            $this->matchData($s, (string) $e['cash_line_id']),
        )
            ->assertCreated()
            ->json('data');
        $this->reverse($c, $f, $m)->assertUnprocessable();
        $this->guard(
            fn () => DB::table('financing_movements')
                ->where('id', $m['id'])
                ->update(['status' => 'reversing']),
        );
        $this->op('POST', $c['treasury_root'].'/statements/'.$s['id'].'/matches/'.$match['id'].'/void', [
            'version' => 2,
            'note' => 'Test',
        ])->assertOk();
        $this->reverse($c, $f, $m)->assertOk();
    }

    public function test_sql_keeps_terms_references_capacities_and_transitions_immutable(): void
    {
        $c = $this->financingContext();
        $f = $this->financing($c);
        $m = $this->financingMovement($c, $f, $this->financingEntry($c));
        foreach (
            [
                fn () => DB::table('financings')
                    ->where('id', $f['id'])
                    ->update(['principal' => 2000]),
                fn () => DB::table('financings')->where('id', $f['id'])->delete(),
                fn () => DB::table('financings')
                    ->where('id', $f['id'])
                    ->update(['status' => null]),
                fn () => DB::table('financing_movements')
                    ->where('id', $m['id'])
                    ->update(['amount' => 1]),
                fn () => DB::table('financing_movements')->where('id', $m['id'])->delete(),
                fn () => DB::table('financing_movements')
                    ->where('id', $m['id'])
                    ->update(['status' => 'reversed', 'version' => 2, 'reference_slot' => null]),
            ] as $write
        ) {
            $this->guard($write);
        }
        $e = $this->financingEntry($c, '700');
        $row = (array) DB::table('financing_movements')->where('id', $m['id'])->first();
        $this->guard(
            fn () => DB::table('financing_movements')->insert([
                ...$row,
                'id' => $this->fixtureId(),
                'entry_id' => $e['id'],
                'cash_line_id' => $e['cash_line_id'],
                'reference' => 'TEST-OVER',
                'amount' => 700,
            ]),
        );
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $this->financingEntry($c, '100'), ['accepted_amount' => '99']),
        )->assertUnprocessable();
        $this->op('POST', $c['treasury_root'].'/financings', $this->financingData($c))->assertUnprocessable();
    }

    public function test_dates_evidence_and_account_classification_are_rechecked(): void
    {
        $c = $this->financingContext();
        foreach (
            [
                ['principal' => '0'],
                ['principal' => '1.5'],
                ['effective_on' => '2026-11-06'],
                ['due_on' => '2026-11-04'],
                ['account_id' => $c['accounts']['77']['id']],
                ['kind' => 'capital_contribution', 'account_id' => $c['accounts']['77']['id']],
            ] as $bad
        ) {
            $this->op(
                'POST',
                $c['treasury_root'].'/financings',
                $this->financingData($c, $bad),
            )->assertUnprocessable();
        }
        $f = $this->financing($c, approve: false);
        $this->operationUser('accountant');
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $approve = ['version' => 1, 'sha256' => $f['sha256'], 'date' => '2026-11-05', 'note' => 'Test'];
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/approve',
            $approve,
        )->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean']);
        $f = $this->op('POST', $c['treasury_root'].'/financings/'.$f['id'].'/approve', $approve)
            ->assertOk()
            ->json('data');
        $e = $this->financingEntry($c);
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $e, ['occurred_at' => '2026-11-06T09:00:00Z']),
        )->assertUnprocessable();
        $this->operationUser();
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', [
            ...$this->bindingData($c, 1),
            'inflow_side' => 'credit',
        ])
            ->assertCreated()
            ->json('data');
        $this->operationUser('accountant');
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $e),
        )->assertUnprocessable();
    }

    public function test_live_reviews_are_separate_and_reads_respect_book_scope_mfa_and_confirmation(): void
    {
        $c = $this->financingContext('live');
        $creator = auth()->user();
        $f = $this->financing($c, approve: false);
        $creator->forceFill(['roles' => ['owner', 'accountant']])->save();
        $a = ['version' => 1, 'sha256' => $f['sha256'], 'date' => '2026-11-05', 'note' => 'Test'];
        $this->op('POST', $c['treasury_root'].'/financings/'.$f['id'].'/approve', $a)->assertUnprocessable();
        $this->operationUser('accountant');
        $f = $this->op('POST', $c['treasury_root'].'/financings/'.$f['id'].'/approve', $a)
            ->assertOk()
            ->json('data');
        $e = $this->financingEntry($c);
        $this->op(
            'POST',
            $c['treasury_root'].'/financings/'.$f['id'].'/movements',
            $this->financingMovementData($c, $e),
        )->assertUnprocessable();
        $this->operationUser('accountant');
        $this->financingMovement($c, $f, $e);
        $other = $this->financingContext();
        $this->assertSame('0', $this->summary($other)['totals']['outstanding_principal']);
        $this->op('GET', $other['treasury_root'].'/financings/'.$f['id'])->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('POST', $other['treasury_root'].'/financings', $this->financingData($other))->assertForbidden();
        $this->operationUser('support');
        $this->op('GET', $c['treasury_root'].'/financings')->assertForbidden();
        $this->operationUser('accountant');
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['treasury_root'].'/financing-summary')->assertForbidden();
    }

    public function test_summary_and_forecast_sum_all_principal_exactly_beyond_native_integers_and_pagination(): void
    {
        $c = $this->financingContext();
        foreach (range(1, 10) as $i) {
            $f = $this->financing($c, [
                'reference' => 'TEST-LARGE-'.$i,
                'principal' => '1000000000000000000',
                'kind' => $i % 2 ? 'loan' : 'founder_advance',
            ]);
            $this->financingMovement($c, $f, $this->financingEntry($c, '1000000000000000000'), [
                'reference' => 'TEST-FUND-'.$i,
            ]);
        }
        foreach (range(1, 15) as $i) {
            $this->financing($c, ['reference' => 'TEST-PENDING-'.$i], approve: false);
        }
        $this->op('GET', $c['treasury_root'].'/financings')
            ->assertOk()
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('meta.total', 25);
        $this->assertSame('10000000000000000000', $this->summary($c)['totals']['outstanding_principal']);
        $this->op('GET', $c['treasury_root'].'/forecast?from=2026-11-05&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.totals.known_outflows', '10000000000000000000')
            ->assertJsonPath('data.days.0.financing', '10000000000000000000');
    }
}
