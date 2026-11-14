<?php

namespace Tests\Feature;

use App\Domain\Documents\BillingIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\TestCase;

final class TreasuryCoverageTest extends TestCase
{
    use AccountingFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    private function path(array $c, array $s): string
    {
        return $c['treasury_root'].'/statements/'.$s['id'];
    }

    private function review(array $c, array $p)
    {
        return $this->op('POST', $c['treasury_root'].'/coverage-policies/'.$p['id'].'/review', [
            'version' => $p['version'],
            'sha256' => $p['sha256'],
            'note' => 'Test review',
        ]);
    }

    private function report(array $c, string $to = '2026-12-31'): array
    {
        return $this->op(
            'GET',
            $c['treasury_root'].
                '/coverage?'.
                http_build_query(['treasury_account_id' => $c['treasury']['id'], 'to' => $to]),
        )
            ->assertOk()
            ->json('data');
    }

    private function close(array $c, array $s, int $version)
    {
        return $this->op('POST', $this->path($c, $s).'/close', ['version' => $version, 'note' => 'Test close']);
    }

    private function calendar(array $c)
    {
        return $this->op('POST', $c['root'].'/periods/'.$c['period']['id'].'/close', [
            'version' => 1,
            'note' => 'Test calendar close',
        ]);
    }

    private function priorEntry(array $c, string $amount = '1000', ?string $journal = null, bool $post = true): array
    {
        $e = $this->op('POST', $c['root'].'/entries', [
            ...$this->accountingEntryData($c, $amount, '2026-10-31'),
            ...$journal ? ['journal_id' => $journal] : [],
        ])
            ->assertCreated()
            ->json('data');

        return $post ? $this->accountingPost($c, $e)->assertOk()->json('data') : $e;
    }

    private function sqlGuard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL accepted a forbidden coverage mutation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TREASURY_', $e->getMessage());
        }
    }

    public function test_coverage_is_opt_in_and_unconfigured_or_earlier_dates_never_claim_completeness(): void
    {
        $c = $this->treasuryContext();
        $this->assertFalse($this->report($c)['configured']);
        $this->assertFalse($this->report($c)['complete']);
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s))
            ->assertCreated()
            ->json('data');
        $this->assertFalse($this->report($c)['configured']);
        $this->review($c, $p)->assertForbidden();
        $this->operationUser('accountant');
        $this->review($c, $p)->assertOk();
        $r = $this->report($c, '2026-10-31');
        $this->assertFalse($r['applicable']);
        $this->assertFalse($r['complete']);
        $this->assertSame('2026-11-01', $r['from']);
        $this->assertNull($r['available_funds']);
    }

    public function test_missing_december_blocks_calendar_even_without_any_open_statement_and_balances_are_frozen(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $nov = $this->treasuryStatement($c);
        $policy = $this->activateCoverage($c, $nov);
        $this->op('POST', $this->path($c, $nov).'/matches', $this->matchData($nov, $p['line_id']))->assertCreated();
        $closed = $this->close($c, $nov, 2)->assertOk()->json('data');
        $this->assertSame('2500', $closed['balance_reconciliation']['ledger']['balance']);
        $this->assertSame('0', $closed['balance_reconciliation']['difference']);
        $this->assertSame($policy['id'], $closed['closure']['snapshot']['balance']['policy_id']);
        $this->assertTrue($this->report($c, '2026-11-30')['complete']);
        $r = $this->report($c);
        $this->assertSame([['from' => '2026-12-01', 'to' => '2026-12-31']], $r['gaps']);
        $this->assertSame([], $r['unclosed']);
        $this->calendar($c)->assertUnprocessable();
        $dec = $this->treasuryStatement($c, [
            'reference' => 'TEST-DEC',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'opening_balance' => '2500',
            'closing_balance' => '2500',
            'lines' => [],
        ]);
        $this->assertSame([$dec['id']], $this->report($c)['unclosed']);
        $this->close($c, $dec, 1)->assertOk();
        $this->assertTrue($this->report($c)['complete']);
        $this->calendar($c)->assertOk();
        $audit = DB::table('audit_events')
            ->where('action', 'accounting.closed')
            ->where('resource_id', $c['period']['id'])
            ->first();
        $details = json_decode($audit->details, true);
        $this->assertSame($policy['id'], $details['treasury_coverage'][0]['policy_id']);
        $this->assertTrue($details['treasury_coverage'][0]['report']['complete']);
        $this->assertSame(
            $closed['closure']['id'],
            $details['treasury_coverage'][0]['report']['verified_closures'][0]['closure_id'],
        );
        $this->op('GET', $this->path($c, $nov))
            ->assertOk()
            ->assertJsonPath('data.balance_reconciliation', $closed['balance_reconciliation']);
    }

    public function test_opening_mismatch_and_stale_draft_cannot_be_approved_after_a_new_posting(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c, ['opening_balance' => '1000', 'closing_balance' => '3500']);
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s))
            ->assertCreated()
            ->json('data');
        $this->assertSame('-1000', $p['snapshot']['opening']['difference']);
        $this->operationUser('accountant');
        $this->review($c, $p)->assertUnprocessable();
        $this->priorEntry($c);
        $this->review($c, $p)->assertUnprocessable();
        $this->operationUser();
        $next = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s, 1))
            ->assertCreated()
            ->json('data');
        $this->assertSame('1000', $next['snapshot']['opening']['ledger']['balance']);
        $this->operationUser('accountant');
        $this->review($c, $next)->assertOk();
        $posted = $this->treasuryPosted($c);
        $this->op('POST', $this->path($c, $s).'/matches', $this->matchData($s, $posted['line_id']))->assertCreated();
        $this->close($c, $s, 2)->assertOk()->assertJsonPath('data.balance_reconciliation.ledger.balance', '3500');
    }

    public function test_opening_adjustment_uses_the_previous_reviewed_outstanding_items(): void
    {
        $c = $this->treasuryContext();
        $e = $this->priorEntry($c, '2500');
        $line = (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $e['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');
        $oct = $this->treasuryStatement($c, [
            'reference' => 'TEST-OCT',
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-31',
            'closing_balance' => '0',
            'lines' => [],
        ]);
        $carry = $this->treasuryCarry($c, $oct, 'ledger', $line);
        $nov = $this->treasuryStatement($c);
        $policy = $this->activateCoverage($c, $nov);
        $this->assertSame('2500', $policy['snapshot']['opening']['outstanding_ledger_net']);
        $this->assertSame('0', $policy['snapshot']['opening']['difference']);
        $this->assertSame($carry['closed']['closure']['id'], $policy['snapshot']['opening']['previous_closure']['id']);
        $this->op('POST', $this->path($c, $nov).'/matches', $this->matchData($nov, $line))->assertCreated();
        $this->close($c, $nov, 2)->assertOk()->assertJsonPath('data.balance_reconciliation.difference', '0');
    }

    public function test_posting_before_the_reviewed_opening_requires_reopening_and_makes_the_policy_stale(): void
    {
        $c = $this->treasuryContext();
        $j = $this->op('POST', $c['root'].'/journals', [
            'code' => 'OPEN',
            'label' => ['fr' => 'Test', 'en' => 'Test'],
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
        $draft = $this->priorEntry($c, '100', $j['id'], false);
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $this->activateCoverage($c, $s);
        $this->op('POST', $this->path($c, $s).'/matches', $this->matchData($s, $p['line_id']))->assertCreated();
        $this->close($c, $s, 2)->assertOk();
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->sqlGuard(
            fn () => DB::table('accounting_entries')
                ->where('id', $draft['id'])
                ->update([
                    'status' => 'posted',
                    'number' => 'SQL-BYPASS',
                    'posted_snapshot' => '{}',
                    'sha256' => str_repeat('a', 64),
                ]),
        );
        $this->operationUser();
        $this->op('POST', $this->path($c, $s).'/reopen', [
            'version' => 3,
            'note' => 'Test opening correction',
        ])->assertOk();
        $prior = $this->accountingPost($c, $draft)->assertOk()->json('data');
        $this->close($c, $s, 4)->assertUnprocessable();
        $r = $this->report($c, '2026-11-30');
        $this->assertFalse($r['opening']['valid']);
        $this->assertSame('opening_changed', $r['opening']['reason']);
        $this->assertSame('100', $r['opening']['current']['difference']);
        $this->op('POST', $c['root'].'/entries/'.$prior['id'].'/reverse', [
            'version' => $prior['version'],
            'journal_id' => $j['id'],
            'entry_date' => '2026-10-31',
            'description' => ['fr' => 'Correction de test', 'en' => 'Test correction'],
            'note' => 'Test exact linked reversal',
        ])->assertCreated();
        $r = $this->report($c, '2026-11-30');
        $this->assertSame('0', $r['opening']['current']['difference']);
        $this->assertFalse($r['opening']['valid']);
        $new = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s, 1))
            ->assertCreated()
            ->json('data');
        $this->operationUser('accountant');
        $this->review($c, $new)->assertOk();
        $this->close($c, $s, 4)->assertOk()->assertJsonPath('data.balance_reconciliation.policy_id', $new['id']);
    }

    public function test_policy_revision_keeps_the_mandate_and_old_closure_until_explicit_reopening(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $old = $this->activateCoverage($c, $s);
        $closed = $this->close($c, $s, 1)->assertOk()->json('data');
        $this->operationUser();
        $this->op('POST', $c['treasury_root'].'/coverage-policies/'.$old['id'].'/void', [
            'version' => 1,
            'note' => 'Cannot disable coverage',
        ])->assertUnprocessable();
        $draft = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s, 1))
            ->assertCreated()
            ->json('data');
        $this->operationUser('accountant');
        $this->review($c, $draft)->assertUnprocessable();
        $this->operationUser();
        $this->op('POST', $this->path($c, $s).'/reopen', ['version' => 2, 'note' => 'New review'])->assertOk();
        $this->operationUser('accountant');
        $this->review($c, $draft)->assertOk();
        $this->assertDatabaseHas('treasury_coverage_policies', [
            'id' => $old['id'],
            'status' => 'approved',
            'current_slot' => null,
        ]);
        $this->close($c, $s, 3)->assertOk()->assertJsonPath('data.balance_reconciliation.policy_id', $draft['id']);
        $this->op('GET', $this->path($c, $s).'/closures')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.sha256', $closed['closure']['sha256']);
        $this->assertFalse($this->report($c)['complete']);
    }

    public function test_date_is_fixed_and_voiding_a_statement_cannot_erase_the_missing_coverage(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $p = $this->activateCoverage($c, $s);
        $this->close($c, $s, 1)->assertOk();
        $dec = $this->treasuryStatement($c, [
            'reference' => 'TEST-DEC',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'closing_balance' => '0',
            'lines' => [],
        ]);
        $this->operationUser();
        $this->op(
            'POST',
            $c['treasury_root'].'/coverage-policies',
            $this->coverageData($c, $dec, 1),
        )->assertUnprocessable();
        $this->op('POST', $this->path($c, $dec).'/void', ['version' => 1, 'note' => 'Test correction'])->assertOk();
        DB::table('treasury_accounts')
            ->where('id', $c['treasury']['id'])
            ->update(['active' => false]);
        $this->calendar($c)->assertUnprocessable();
        $this->assertSame($p['id'], $this->report($c)['policy_id']);
        $this->sqlGuard(
            fn () => DB::table('treasury_coverage_policies')
                ->where('id', $p['id'])
                ->update(['from_date' => '2026-12-01']),
        );
        $this->sqlGuard(
            fn () => DB::table('treasury_coverage_policies')
                ->where('id', $p['id'])
                ->update(['status' => 'void', 'current_slot' => null]),
        );
        $this->sqlGuard(fn () => DB::table('treasury_coverage_policies')->where('id', $p['id'])->delete());
    }

    public function test_evidence_or_snapshot_changes_cannot_pass_review_and_pending_cancellation_leaves_no_mandate(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $key = $this->fixtureId();
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s), $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s), $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $p['id']);
        $this->operationUser('accountant');
        $this->review($c, [...$p, 'sha256' => str_repeat('0', 64)])->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $this->review($c, $p)->assertUnprocessable();
        $this->operationUser();
        $this->op('POST', $c['treasury_root'].'/coverage-policies/'.$p['id'].'/void', [
            'version' => 1,
            'note' => 'Withdraw draft',
        ])->assertOk();
        $this->assertFalse($this->report($c)['configured']);
        $this->sqlGuard(
            fn () => DB::table('treasury_coverage_policies')
                ->where('id', $p['id'])
                ->update(['snapshot' => '{}']),
        );
    }

    public function test_scope_mfa_recent_confirmation_and_accounting_calendar_reopen_are_required(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s))
            ->assertCreated()
            ->json('data');
        $this->close($c, $s, 1)->assertOk();
        $this->calendar($c)->assertOk();
        $this->operationUser('accountant');
        $this->review($c, $p)->assertUnprocessable();
        $other = $this->accountingContext();
        $this->op(
            'POST',
            $other['root'].'/treasury/coverage-policies',
            $this->coverageData($c, $s),
        )->assertNotFound();
        $this->op('POST', $other['root'].'/treasury/coverage-policies/'.$p['id'].'/void', [
            'version' => 1,
            'note' => 'Wrong book',
        ])->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op(
            'POST',
            $c['treasury_root'].'/coverage-policies',
            $this->coverageData($c, $s, 1),
        )->assertForbidden();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['treasury_root'].'/coverage-policies')->assertForbidden();
        $this->operationUser('manager');
        $this->op('GET', $c['treasury_root'].'/coverage-policies')->assertForbidden();
    }

    public function test_exact_opening_across_exercises_and_credit_inflow_orientation(): void
    {
        $c = $this->treasuryContext();
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', [
            ...$this->bindingData($c, 1),
            'inflow_side' => 'credit',
        ])
            ->assertCreated()
            ->json('data');
        foreach ([1, 2] as $i) {
            $d = $this->accountingEntryData($c, '1000000000000000000', '2026-10-31');
            $d['lines'] = array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $d['lines']);
            $e = $this->op('POST', $c['root'].'/entries', $d)
                ->assertCreated()
                ->json('data');
            $this->accountingPost($c, $e)->assertOk();
        }
        $s = $this->treasuryStatement($c, [
            'opening_balance' => '2000000000000000000',
            'closing_balance' => '2000000000000000000',
            'lines' => [],
        ]);
        $p = $this->activateCoverage($c, $s);
        $this->assertSame('2000000000000000000', $p['snapshot']['opening']['ledger']['balance']);
        $this->close($c, $s, 1)
            ->assertOk()
            ->assertJsonPath('data.balance_reconciliation.ledger.balance', '2000000000000000000');
        $dec = $this->treasuryStatement($c, [
            'reference' => 'TEST-DEC',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'opening_balance' => '2000000000000000000',
            'closing_balance' => '2000000000000000000',
            'lines' => [],
        ]);
        $this->close($c, $dec, 1)->assertOk();
        $this->calendar($c)->assertOk();
        $this->op('POST', $c['root'].'/years/'.$c['year']['id'].'/close', [
            'version' => 1,
            'note' => 'Test year close',
        ])->assertOk();
        $this->operationUser();
        $year = $this->op('POST', $c['root'].'/years', [
            'label' => ['fr' => 'Test', 'en' => 'Test'],
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['root'].'/years/'.$year['id'].'/periods', [
            'label' => ['fr' => 'Test', 'en' => 'Test'],
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ])->assertCreated();
        $jan = $this->treasuryStatement($c, [
            'reference' => 'TEST-JAN',
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-01-31',
            'opening_balance' => '2000000000000000000',
            'closing_balance' => '2000000000000000000',
            'lines' => [],
        ]);
        $this->close($c, $jan, 1)
            ->assertOk()
            ->assertJsonPath('data.balance_reconciliation.ledger.balance', '2000000000000000000')
            ->assertJsonPath('data.balance_reconciliation.ledger.line_count', 2);
    }

    public function test_live_creator_cannot_approve_their_own_coverage_even_with_both_roles(): void
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
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $creator = $this->operationUser();
        $p = $this->op('POST', $c['treasury_root'].'/coverage-policies', $this->coverageData($c, $s))
            ->assertCreated()
            ->json('data');
        $creator->forceFill(['roles' => ['owner', 'accountant']])->save();
        $this->review($c, $p)->assertUnprocessable();
        $this->operationUser('accountant');
        $this->review($c, $p)->assertOk();
        $this->close($c, $s, 1)->assertOk();
    }
}
