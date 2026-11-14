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

final class TreasuryReconciliationTest extends TestCase
{
    use AccountingFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    private function path(array $c, array $s, string $suffix = ''): string
    {
        return $c['treasury_root'].'/statements/'.$s['id'].$suffix;
    }

    private function transition(array $c, array $s, string $action, int $version)
    {
        return $this->op('POST', $this->path($c, $s, '/'.$action), [
            'version' => $version,
            'note' => 'Test audited action',
        ]);
    }

    private function guard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('A forbidden treasury mutation was accepted.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TREASURY_', $e->getMessage());
        }
    }

    public function test_proof_balances_exact_match_idempotency_and_no_financial_side_effects(): void
    {
        $c = $this->treasuryContext();
        $posted = $this->treasuryPosted($c);
        $key = $this->fixtureId();
        $s = $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c), $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c), $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $s['id']);
        $this->assertSame(1, $s['reconciliation']['unmatched_statement_lines']);
        $this->assertSame(1, $s['reconciliation']['unmatched_ledger_lines']);
        $this->transition($c, $s, 'close', 1)->assertUnprocessable();
        $match = $this->op('POST', $this->path($c, $s, '/matches'), $this->matchData($s, $posted['line_id']))
            ->assertCreated()
            ->json('data');
        $this->assertSame('2500', $match['net_amount']);
        $this->guard(
            fn () => DB::table('treasury_match_rows')->insert([
                'match_id' => $match['id'],
                'statement_line_id' => $match['statement_line_ids'][0],
            ]),
        );
        $this->transition($c, $s, 'close', 2)
            ->assertOk()
            ->assertJsonPath('data.status', 'closed')
            ->assertJsonPath('data.reconciliation.available_funds', null)
            ->assertJsonPath('data.reconciliation.availability_verified', false);
        $this->op('GET', $this->path($c, $s, '/ledger'))
            ->assertOk()
            ->assertJsonPath('data.0.id', $posted['line_id'])
            ->assertJsonPath('data.0.match_id', $match['id']);
        $this->op('GET', $this->path($c, $s, '/rows'))->assertOk()->assertJsonPath('data.0.match_id', $match['id']);
        $this->assertDatabaseCount('accounting_entries', 1);
        $this->assertDatabaseCount('supplier_payments', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('customer_refunds', 0);
    }

    public function test_import_rejects_bad_sums_dates_duplicates_precision_and_unclean_evidence(): void
    {
        $c = $this->treasuryContext();
        $base = $this->statementData($c);
        foreach (
            [
                ['closing_balance' => '2499'],
                ['opening_balance' => '-0'],
                ['closing_balance' => '2.5'],
                ['opening_balance' => 0],
                ['lines' => [[...$base['lines'][0], 'amount' => '0']]],
                ['lines' => [[...$base['lines'][0], 'amount' => '1000000000000000001']]],
                ['lines' => [[...$base['lines'][0], 'booked_on' => '2026-10-31']]],
                ['lines' => [$base['lines'][0], $base['lines'][0]], 'closing_balance' => '5000'],
            ] as $change
        ) {
            $this->op('POST', $c['treasury_root'].'/statements', [...$base, ...$change])->assertUnprocessable();
        }
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $this->op('POST', $c['treasury_root'].'/statements', $base)->assertUnprocessable();
        $this->assertDatabaseCount('treasury_statements', 0);
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean']);
        $s = $this->treasuryStatement($c);
        $this->op('POST', $c['treasury_root'].'/statements', $base)->assertUnprocessable();
        $this->assertDatabaseCount('treasury_statements', 1);
        $this->guard(
            fn () => DB::table('treasury_statements')
                ->where('id', $s['id'])
                ->update(['closing_balance' => '999']),
        );
        $this->guard(
            fn () => DB::table('treasury_statement_lines')
                ->where('statement_id', $s['id'])
                ->update(['amount' => 999]),
        );
        $this->guard(fn () => DB::table('treasury_statement_lines')->where('statement_id', $s['id'])->delete());
        $this->guard(
            fn () => DB::table('treasury_statement_lines')->insert([
                'id' => $this->fixtureId(),
                'statement_id' => $s['id'],
                'position' => 2,
                'external_id' => 'SQL-APPEND',
                'booked_on' => '2026-11-04',
                'reference' => 'TEST',
                'direction' => 'in',
                'amount' => 1,
            ]),
        );
    }

    public function test_net_settlement_requires_explicit_posted_fee_and_supports_grouped_lines(): void
    {
        $c = $this->treasuryContext();
        $gross = $this->treasuryPosted($c);
        $d = $this->accountingEntryData($c, '100');
        $d['lines'] = array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $d['lines']);
        $fee = $this->op('POST', $c['root'].'/entries', $d)
            ->assertCreated()
            ->json('data');
        $this->accountingPost($c, $fee)->assertOk();
        $feeLine = (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $fee['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');
        $base = $this->statementData($c);
        $s = $this->treasuryStatement($c, [
            'closing_balance' => '2400',
            'lines' => [[...$base['lines'][0], 'amount' => '2400']],
        ]);
        $data = $this->matchData($s, $gross['line_id']);
        $this->op('POST', $this->path($c, $s, '/matches'), $data)->assertUnprocessable();
        $this->op('POST', $this->path($c, $s, '/matches'), [
            ...$data,
            'ledger_line_ids' => [$gross['line_id'], $feeLine],
        ])
            ->assertCreated()
            ->assertJsonPath('data.net_amount', '2400');
        $this->transition($c, $s, 'close', 2)->assertOk();
        $this->assertDatabaseCount('accounting_entries', 2);
    }

    public function test_wrong_account_draft_other_book_and_already_matched_lines_are_rejected(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $d = $this->matchData($s, $p['line_id']);
        $otherAccount = (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $p['entry']['id'])
            ->where('account_id', $c['accounts']['22']['id'])
            ->value('id');
        $this->op('POST', $this->path($c, $s, '/matches'), [
            ...$d,
            'ledger_line_ids' => [$otherAccount],
        ])->assertUnprocessable();
        $draft = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $draftLine = (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $draft['id'])
            ->orderBy('position')
            ->value('id');
        $this->op('POST', $this->path($c, $s, '/matches'), [
            ...$d,
            'ledger_line_ids' => [$draftLine],
        ])->assertUnprocessable();
        $other = $this->accountingContext();
        $this->op('GET', $other['root'].'/treasury/statements/'.$s['id'])->assertNotFound();
        $this->op('POST', $this->path($c, $s, '/matches'), $d)->assertCreated();
        $this->op('POST', $this->path($c, $s, '/matches'), [...$d, 'version' => 2])->assertUnprocessable();
        $this->op('POST', $this->path($c, $s, '/matches'), $d)->assertConflict();
        $this->assertDatabaseCount('treasury_matches', 1);
    }

    public function test_undo_retains_history_and_releases_unique_line_slots(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $d = $this->matchData($s, $p['line_id']);
        $m = $this->op('POST', $this->path($c, $s, '/matches'), $d)->assertCreated()->json('data');
        $this->op('POST', $this->path($c, $s, '/matches/'.$m['id'].'/void'), [
            'version' => 2,
            'note' => 'Test correction',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'void');
        $this->op('POST', $this->path($c, $s, '/matches'), [...$d, 'version' => 3])->assertCreated();
        $this->assertDatabaseCount('treasury_matches', 2);
        $this->assertDatabaseCount('treasury_match_ledger', 2);
        $this->assertSame(1, DB::table('treasury_match_ledger')->where('active_slot', 1)->count());
        $this->guard(fn () => DB::table('treasury_match_ledger')->where('match_id', $m['id'])->delete());
        $this->op('GET', $this->path($c, $s, '/matches'))->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_closure_guards_new_postings_reversal_and_calendar_until_authorized_reopening(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $this->op('POST', $c['root'].'/periods/'.$c['period']['id'].'/close', [
            'version' => 1,
            'note' => 'Test',
        ])->assertUnprocessable();
        $this->op('POST', $this->path($c, $s, '/matches'), $this->matchData($s, $p['line_id']))->assertCreated();
        $this->transition($c, $s, 'close', 2)->assertOk();
        $draft = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->guard(
            fn () => DB::table('accounting_entries')
                ->where('id', $draft['id'])
                ->update([
                    'status' => 'posted',
                    'number' => 'SQL-BYPASS',
                    'posted_snapshot' => '{}',
                    'sha256' => str_repeat('a', 64),
                ]),
        );
        $this->op('POST', $c['root'].'/entries/'.$p['entry']['id'].'/reverse', [
            'version' => 2,
            'journal_id' => $c['journal']['id'],
            'entry_date' => '2026-11-04',
            'description' => ['fr' => 'Test', 'en' => 'Test'],
            'note' => 'Test',
        ])->assertUnprocessable();
        $this->assertDatabaseCount('accounting_entries', 2);
        $this->operationUser('accountant');
        $this->transition($c, $s, 'reopen', 3)->assertForbidden();
        $this->operationUser();
        $this->transition($c, $s, 'reopen', 3)->assertOk();
        $this->accountingPost($c, $draft)->assertOk();
        $this->transition($c, $s, 'close', 4)->assertUnprocessable();
        $this->assertDatabaseHas('accounting_entries', ['id' => $p['entry']['id'], 'sha256' => $p['entry']['sha256']]);
    }

    public function test_continuity_successor_order_and_void_preserve_original_statement(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $next = [
            'reference' => 'TEST-DECEMBER',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'opening_balance' => '2500',
            'closing_balance' => '2500',
            'lines' => [],
        ];
        $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c, $next))->assertUnprocessable();
        $this->op('POST', $this->path($c, $s, '/matches'), $this->matchData($s, $p['line_id']))->assertCreated();
        $this->transition($c, $s, 'close', 2)->assertOk();
        foreach (
            [
                ['starts_on' => '2026-11-30'],
                ['starts_on' => '2026-12-02'],
                ['opening_balance' => '2499', 'closing_balance' => '2499'],
            ] as $bad
        ) {
            $this->op(
                'POST',
                $c['treasury_root'].'/statements',
                $this->statementData($c, [...$next, ...$bad]),
            )->assertUnprocessable();
        }
        $dec = $this->treasuryStatement($c, $next);
        $this->transition($c, $dec, 'close', 1)->assertOk();
        $this->transition($c, $s, 'reopen', 3)->assertUnprocessable();
        $this->transition($c, $dec, 'reopen', 2)->assertOk();
        $this->transition($c, $s, 'reopen', 3)->assertOk();
        $this->transition($c, $s, 'void', 4)->assertUnprocessable();
        $this->transition($c, $dec, 'void', 3)->assertOk()->assertJsonPath('data.status', 'void');
        $this->assertDatabaseCount('treasury_statements', 2);
        $this->op('GET', $this->path($c, $dec))->assertOk()->assertJsonPath('data.sha256', $dec['sha256']);
    }

    public function test_signed_opening_and_aggregate_totals_remain_exact_above_single_money_limit(): void
    {
        $c = $this->treasuryContext();
        $a = $this->treasuryPosted($c, '1000000000000000000');
        $b = $this->treasuryPosted($c, '1000000000000000000');
        $base = $this->statementData($c);
        $s = $this->treasuryStatement($c, [
            'opening_balance' => '-7',
            'closing_balance' => '1999999999999999993',
            'lines' => [
                [...$base['lines'][0], 'external_id' => 'A', 'amount' => '1000000000000000000'],
                [...$base['lines'][0], 'external_id' => 'B', 'amount' => '1000000000000000000'],
            ],
        ]);
        $this->assertSame('2000000000000000000', $s['reconciliation']['unmatched_ledger_net']);
        $this->op('POST', $this->path($c, $s, '/matches'), [
            ...$this->matchData($s, $a['line_id']),
            'ledger_line_ids' => [$a['line_id'], $b['line_id']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.net_amount', '2000000000000000000');
        $this->transition($c, $s, 'close', 2)->assertOk();
    }

    public function test_binding_revisions_chart_change_protection_and_permissions(): void
    {
        $c = $this->treasuryContext();
        $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c))->assertConflict();
        $this->treasuryStatement($c);
        $this->op('POST', $c['treasury_root'].'/bindings', [
            ...$this->bindingData($c, 1),
            'account_id' => $c['accounts']['22']['id'],
        ])->assertUnprocessable();
        $otherTreasury = $this->account();
        $this->op('POST', $c['treasury_root'].'/bindings', [
            ...$this->bindingData($c),
            'treasury_account_id' => $otherTreasury['id'],
        ])->assertUnprocessable();
        $this->guard(
            fn () => DB::table('treasury_bindings')
                ->where('id', $c['binding']['id'])
                ->update(['inflow_side' => 'credit']),
        );
        $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'TEST 2',
            'accounts' => $this->accountingChart(),
        ])->assertOk();
        $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c))->assertUnprocessable();
        $this->operationUser('manager');
        $this->op('GET', $c['treasury_root'].'/bindings')->assertForbidden();
        $this->operationUser();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c, 1))->assertForbidden();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['treasury_root'].'/statements')->assertForbidden();
    }

    public function test_live_import_requires_accountant_review_of_current_chart_and_binding(): void
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
        $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c))->assertUnprocessable();
        $review = ['sha256' => $c['binding']['sha256'], 'note' => 'Test review'];
        $this->op(
            'POST',
            $c['treasury_root'].'/bindings/'.$c['binding']['id'].'/review',
            $review,
        )->assertForbidden();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/chart/review', [
            'version' => 1,
            'sha256' => $c['chart']['sha256'],
            'note' => 'Test review',
        ])->assertOk();
        $this->op('POST', $c['treasury_root'].'/statements', $this->statementData($c))->assertUnprocessable();
        $this->op('POST', $c['treasury_root'].'/bindings/'.$c['binding']['id'].'/review', [
            ...$review,
            'sha256' => str_repeat('0', 64),
        ])->assertUnprocessable();
        $this->op('POST', $c['treasury_root'].'/bindings/'.$c['binding']['id'].'/review', $review)->assertOk();
        $this->treasuryStatement($c);
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_credit_inflow_orientation_and_evidence_revocation_before_closure(): void
    {
        $c = $this->treasuryContext();
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', [
            ...$this->bindingData($c, 1),
            'inflow_side' => 'credit',
        ])
            ->assertCreated()
            ->json('data');
        $d = $this->accountingEntryData($c);
        $d['lines'] = array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $d['lines']);
        $entry = $this->op('POST', $c['root'].'/entries', $d)
            ->assertCreated()
            ->json('data');
        $this->accountingPost($c, $entry)->assertOk();
        $line = (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $entry['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');
        $s = $this->treasuryStatement($c);
        $this->op('POST', $this->path($c, $s, '/matches'), $this->matchData($s, $line))
            ->assertCreated()
            ->assertJsonPath('data.net_amount', '2500');
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'infected']);
        $this->transition($c, $s, 'close', 2)->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean', 'sha256' => str_repeat('b', 64)]);
        $this->transition($c, $s, 'close', 2)->assertUnprocessable();
        $this->assertDatabaseHas('treasury_statements', ['id' => $s['id'], 'status' => 'open', 'version' => 2]);
    }

    public function test_void_allows_corrected_import_and_closed_calendar_forbids_reopening(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c);
        $this->transition($c, $s, 'void', 1)->assertOk();
        $corrected = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $this->assertNotSame($s['id'], $corrected['id']);
        $this->transition($c, $corrected, 'close', 1)->assertOk();
        $this->op('POST', $c['root'].'/periods/'.$c['period']['id'].'/close', [
            'version' => 1,
            'note' => 'Test closure',
        ])->assertOk();
        $this->transition($c, $corrected, 'reopen', 2)->assertUnprocessable();
        $this->guard(
            fn () => DB::table('treasury_statements')
                ->where('id', $s['id'])
                ->update(['status' => 'open']),
        );
        foreach (['bank', 'mobile_money', 'monetbil', 'cash'] as $kind) {
            $this->op('POST', 'treasury/accounts', [
                'code' => 'TEST-'.substr($this->fixtureId(), 0, 8),
                'name' => 'Fictional test account',
                'kind' => $kind,
            ])->assertCreated();
        }
    }
}
