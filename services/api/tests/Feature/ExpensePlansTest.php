<?php

namespace Tests\Feature;

use App\Domain\Documents\BillingIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ExpenseFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\TestCase;

final class ExpensePlansTest extends TestCase
{
    use AccountingFixtures, ExpenseFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    private function summary(array $c, string $date = '2026-11-04'): array
    {
        return $this->op('GET', $c['root'].'/expense-summary?as_of='.$date)
            ->assertOk()
            ->json('data');
    }

    private function cancel(array $c, array $p, string $remaining = '600', string $date = '2026-11-05')
    {
        return $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/cancel', [
            'version' => $p['version'],
            'sha256' => $p['sha256'],
            'accepted_remaining' => $remaining,
            'date' => $date,
            'note' => 'Cancel unspent test budget',
        ]);
    }

    private function reverse(array $c, array $p, array $payment, string $date = '2026-11-05')
    {
        return $this->op(
            'POST',
            $c['root'].'/expenses/'.$p['id'].'/payments/'.$payment['id'].'/reverse',
            $this->expenseReversal($c, $payment, $date),
        );
    }

    private function guard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL accepted a forbidden expense mutation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('EXPENSE_', $e->getMessage());
        }
    }

    public function test_only_approved_plans_commit_funds_and_partial_payments_link_existing_entries_without_posting_again(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c, approve: false);
        $this->assertSame('0', $this->summary($c)['totals']['committed']);
        $this->assertDatabaseCount('accounting_entries', 0);
        $this->operationUser('accountant');
        $p = $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/approve', [
            'version' => 1,
            'sha256' => $p['sha256'],
            'date' => '2026-11-04',
            'note' => 'Test review',
        ])
            ->assertOk()
            ->json('data');
        $this->assertSame('1000', $this->summary($c)['totals']['committed']);
        $e = $this->expenseCash($c);
        $data = $this->expensePaymentData($c, $e);
        $key = $this->fixtureId();
        $payment = $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', $data, $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', $data, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $payment['id']);
        $this->assertDatabaseCount('accounting_entries', 1);
        $s = $this->summary($c);
        $this->assertSame('400', $s['totals']['net_spent']);
        $this->assertSame('600', $s['totals']['committed']);
        $this->expensePayment($c, $p, $this->expenseCash($c, '600'));
        $this->assertSame('0', $this->summary($c)['totals']['committed']);
        $this->assertDatabaseCount('accounting_entries', 2);
        $this->assertDatabaseCount('accounting_sources', 0);
        $this->op('GET', $c['root'].'/expenses/'.$p['id'].'/payments')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->assertNull($s['available_funds']);
        $this->assertFalse($s['external_payment_verified']);
    }

    public function test_cancelling_only_the_unspent_remainder_preserves_spending_and_earlier_commitments(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $this->expensePayment($c, $p, $this->expenseCash($c));
        $this->cancel($c, $p, '1000')->assertConflict();
        $this->cancel($c, $p)->assertOk()->assertJsonPath('data.balance.cancelled_unspent', '600');
        $this->assertSame('600', $this->summary($c)['totals']['committed']);
        $later = $this->summary($c, '2026-11-05');
        $this->assertSame('0', $later['totals']['committed']);
        $this->assertSame('400', $later['totals']['net_spent']);
        $e = $this->expenseCash($c, '100', '2026-11-05');
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', [
            ...$this->expensePaymentData($c, $e),
            'version' => 3,
        ])->assertUnprocessable();
    }

    public function test_payment_correction_atomically_posts_an_exact_reversal_and_restores_only_the_later_commitment(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $e = $this->expenseCash($c);
        $payment = $this->expensePayment($c, $p, $e);
        $old = $this->summary($c);
        $this->op('POST', $c['root'].'/entries/'.$e['id'].'/reverse', [
            ...$this->expenseReversal($c, $payment),
            'version' => $e['version'],
        ])->assertUnprocessable();
        $draft = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c, '400'))
            ->assertCreated()
            ->json('data');
        $this->guard(
            fn () => DB::table('accounting_entries')
                ->where('id', $draft['id'])
                ->update([
                    'status' => 'posted',
                    'reversal_of' => $e['id'],
                    'number' => 'DEMO-FORBIDDEN',
                    'posted_snapshot' => '{}',
                    'sha256' => str_repeat('a', 64),
                ]),
        );
        $reversed = $this->reverse($c, $p, $payment)->assertOk()->json('data');
        $this->assertSame($old, $this->summary($c));
        $this->assertSame('1000', $this->summary($c, '2026-11-05')['totals']['committed']);
        $this->assertSame($payment['sha256'], $reversed['sha256']);
        $this->assertSame($payment['snapshot'], $reversed['snapshot']);
        $counter = DB::table('accounting_entries')->where('id', $reversed['reversal_entry_id'])->first();
        $this->assertSame($e['id'], $counter->reversal_of);
        $line = DB::table('accounting_entry_lines')
            ->where('entry_id', $counter->id)
            ->where('account_id', $c['accounts']['11']['id'])
            ->first();
        $this->assertSame('400', (string) $line->debit);
        $this->assertSame('0', (string) $line->credit);
        $this->op('POST', $c['root'].'/entries/'.$counter->id.'/reverse', [
            ...$this->expenseReversal($c, $payment),
            'version' => 2,
        ])->assertUnprocessable();
        $this->reverse($c, $p, $payment)->assertConflict();
        $this->op(
            'POST',
            $c['root'].'/expenses/'.$p['id'].'/payments',
            $this->expensePaymentData($c, $e),
        )->assertUnprocessable();
    }

    public function test_closed_reconciliations_roll_back_the_entire_payment_correction(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $e = $this->expenseCash($c);
        $payment = $this->expensePayment($c, $p, $e);
        $s = $this->treasuryStatement($c, [
            'closing_balance' => '-400',
            'lines' => [
                [
                    'external_id' => 'OUT',
                    'booked_on' => '2026-11-04',
                    'reference' => 'Test',
                    'direction' => 'out',
                    'amount' => '400',
                ],
            ],
        ]);
        $path = $c['treasury_root'].'/statements/'.$s['id'];
        $this->op('POST', $path.'/matches', $this->matchData($s, $e['line_id']))->assertCreated();
        $this->op('POST', $path.'/close', ['version' => 2, 'note' => 'Test close'])->assertOk();
        $this->reverse($c, $p, $payment, '2026-11-04')->assertUnprocessable();
        $this->assertDatabaseHas('expense_payments', ['id' => $payment['id'], 'status' => 'recorded', 'version' => 1]);
        $this->assertDatabaseCount('accounting_entries', 1);
        $this->operationUser();
        $this->op('POST', $path.'/reopen', ['version' => 3, 'note' => 'Test reopen'])->assertOk();
        $this->reverse($c, $p, $payment, '2026-11-04')->assertOk();
    }

    public function test_invalid_amounts_duplicate_references_and_duplicate_or_wrong_postings_are_rejected(): void
    {
        $c = $this->treasuryContext();
        foreach (['0', '-1', '1.1', '1000000000000000001'] as $a) {
            $this->op(
                'POST',
                $c['root'].'/expenses',
                $this->expenseData($c, ['amount' => $a]),
            )->assertUnprocessable();
        }
        $p = $this->expensePlan($c);
        $this->op(
            'POST',
            $c['root'].'/expenses',
            $this->expenseData($c, ['reference' => ' test-expense ']),
        )->assertUnprocessable();
        $e = $this->expenseCash($c, '1001');
        $this->op(
            'POST',
            $c['root'].'/expenses/'.$p['id'].'/payments',
            $this->expensePaymentData($c, $e),
        )->assertUnprocessable();
        $e = $this->expenseCash($c);
        $d = $this->expensePaymentData($c, $e);
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/payments', [
            ...$d,
            'accepted_amount' => '399',
        ])->assertUnprocessable();
        $in = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $this->accountingPost($c, $in)->assertOk();
        $in['line_id'] = (int) DB::table('accounting_entry_lines')
            ->where('entry_id', $in['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');
        $this->op(
            'POST',
            $c['root'].'/expenses/'.$p['id'].'/payments',
            $this->expensePaymentData($c, $in),
        )->assertUnprocessable();
        $this->expensePayment($c, $p, $e);
        $other = $this->expensePlan($c, ['reference' => 'TEST-OTHER']);
        $this->op('POST', $c['root'].'/expenses/'.$other['id'].'/payments', $d)->assertUnprocessable();
        $this->assertDatabaseCount('expense_payments', 1);
        $this->operationUser();
        $second = $this->account();
        $this->op('POST', $c['treasury_root'].'/bindings', [
            'treasury_account_id' => $second['id'],
            'account_id' => $c['accounts']['22']['id'],
            'inflow_side' => 'debit',
            'version' => 0,
            'note' => 'Test second treasury',
        ])->assertCreated();
        $transfer = $this->expenseCash($c, '100');
        $this->operationUser('accountant');
        $this->op(
            'POST',
            $c['root'].'/expenses/'.$other['id'].'/payments',
            $this->expensePaymentData($c, $transfer),
        )->assertUnprocessable();
    }

    public function test_dates_and_closed_periods_protect_the_history(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $e = $this->expenseCash($c);
        $pay = $this->expensePayment($c, $p, $e);
        $this->reverse($c, $p, $pay, '2026-11-03')->assertUnprocessable();
        $this->reverse($c, $p, $pay)->assertOk();
        $this->cancel($c, $p, '1000', '2026-11-04')->assertUnprocessable();
        $this->op('POST', $c['root'].'/periods/'.$c['period']['id'].'/close', [
            'version' => 1,
            'note' => 'Test close',
        ])->assertOk();
        $this->cancel($c, $p, '1000', '2026-11-05')->assertUnprocessable();
        $this->op(
            'POST',
            $c['root'].'/expenses',
            $this->expenseData($c, ['reference' => 'CLOSED']),
        )->assertUnprocessable();
    }

    public function test_sql_guards_immutable_content_state_and_overallocation(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c);
        $e = $this->expenseCash($c);
        $pay = $this->expensePayment($c, $p, $e);
        $this->guard(
            fn () => DB::table('expense_plans')
                ->where('id', $p['id'])
                ->update(['amount' => 2000]),
        );
        $this->guard(fn () => DB::table('expense_plans')->where('id', $p['id'])->delete());
        $this->guard(
            fn () => DB::table('expense_payments')
                ->where('id', $pay['id'])
                ->update(['snapshot' => '{}']),
        );
        $this->guard(
            fn () => DB::table('expense_payments')
                ->where('id', $pay['id'])
                ->update(['status' => 'reversed', 'version' => 2, 'reversal_entry_id' => null]),
        );
        $this->guard(fn () => DB::table('expense_payments')->where('id', $pay['id'])->delete());
        $big = $this->expenseCash($c, '1000');
        $this->guard(function () use ($pay, $big) {
            $copy = (array) DB::table('expense_payments')->where('id', $pay['id'])->first();
            $copy['id'] = $this->fixtureId();
            $copy['entry_id'] = $big['id'];
            $copy['ledger_line_id'] = $big['line_id'];
            $copy['amount'] = 1000;
            DB::table('expense_payments')->insert($copy);
        });
    }

    public function test_scope_permissions_recent_confirmation_clean_proofs_and_replay_are_enforced(): void
    {
        $c = $this->treasuryContext();
        $p = $this->expensePlan($c, approve: false);
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/approve', [
            'version' => 1,
            'sha256' => $p['sha256'],
            'date' => '2026-11-04',
            'note' => 'Test',
        ])->assertForbidden();
        $this->operationUser('accountant');
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/approve', [
            'version' => 1,
            'sha256' => $p['sha256'],
            'date' => '2026-11-04',
            'note' => 'Test',
        ])->assertUnprocessable();
        $other = $this->accountingContext();
        $this->op('GET', $other['root'].'/expenses/'.$p['id'])->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->cancel($c, $p, '1000')->assertForbidden();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['root'].'/expenses')->assertForbidden();
        $this->operationUser('manager');
        $this->op('GET', $c['root'].'/expense-summary?as_of=2026-11-04')->assertForbidden();
    }

    public function test_report_aggregates_large_commitments_without_claiming_available_funds_or_other_books(): void
    {
        $c = $this->treasuryContext();
        $this->expensePlan($c, ['amount' => '1000000000000000000']);
        $this->expensePlan($c, ['amount' => '1000000000000000000', 'reference' => 'TEST-SECOND']);
        $this->expensePlan($c, ['planned_on' => '2026-11-06', 'reference' => 'TEST-FUTURE'], approve: false);
        $r = $this->summary($c);
        $this->assertSame(2, $r['plans']);
        $this->assertSame('2000000000000000000', $r['totals']['committed']);
        $this->assertSame('expense_plans_only', $r['scope']);
        $this->assertNull($r['available_funds']);
        $this->assertFalse($r['availability_verified']);
        $other = $this->accountingContext();
        $this->assertSame(0, $this->summary($other)['plans']);
    }

    public function test_credit_oriented_treasury_bindings_use_debit_cash_outflows(): void
    {
        $c = $this->treasuryContext();
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', [
            ...$this->bindingData($c, 1),
            'inflow_side' => 'credit',
        ])
            ->assertCreated()
            ->json('data');
        $p = $this->expensePlan($c);
        $this->expensePayment($c, $p, $this->expenseCash($c));
        $this->assertSame('400', $this->summary($c)['totals']['net_spent']);
    }

    public function test_live_review_separates_plan_creator_and_cash_poster_from_the_reviewer(): void
    {
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Fictional test operator',
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
        $creator = $this->operationUser();
        $p = $this->expensePlan($c, approve: false);
        $e = $this->expenseCash($c);
        $creator->forceFill(['roles' => ['owner', 'accountant']])->save();
        $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/approve', [
            'version' => 1,
            'sha256' => $p['sha256'],
            'date' => '2026-11-04',
            'note' => 'Test',
        ])->assertUnprocessable();
        $reviewer = $this->operationUser('accountant');
        $p = $this->op('POST', $c['root'].'/expenses/'.$p['id'].'/approve', [
            'version' => 1,
            'sha256' => $p['sha256'],
            'date' => '2026-11-04',
            'note' => 'Test',
        ])
            ->assertOk()
            ->json('data');
        $this->app['auth']->forgetGuards();
        $this->actingAs($creator, 'web');
        $this->op(
            'POST',
            $c['root'].'/expenses/'.$p['id'].'/payments',
            $this->expensePaymentData($c, $e),
        )->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->actingAs($reviewer, 'web');
        $this->expensePayment($c, $p, $e);
    }
}
