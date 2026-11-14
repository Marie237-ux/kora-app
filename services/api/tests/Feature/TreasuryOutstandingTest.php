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

final class TreasuryOutstandingTest extends TestCase
{
    use AccountingFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    private function path(array $c, array $s): string
    {
        return $c['treasury_root'].'/statements/'.$s['id'];
    }

    private function next(array $c, string $closing = '0', array $lines = []): array
    {
        return $this->treasuryStatement($c, [
            'reference' => 'TEST-DECEMBER',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'opening_balance' => '0',
            'closing_balance' => $closing,
            'lines' => $lines,
        ]);
    }

    private function close(array $c, array $s, int $version)
    {
        return $this->op('POST', $this->path($c, $s).'/close', ['version' => $version, 'note' => 'Test close']);
    }

    private function datedEntry(array $c, string $date, bool $out = false): string
    {
        $d = $this->accountingEntryData($c, '2500', $date);
        if ($out) {
            $d['lines'] = array_map(fn ($l) => [...$l, 'debit' => $l['credit'], 'credit' => $l['debit']], $d['lines']);
        }
        $entry = $this->op('POST', $c['root'].'/entries', $d)
            ->assertCreated()
            ->json('data');
        $this->accountingPost($c, $entry)->assertOk();

        return (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $entry['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');
    }

    private function sqlGuard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL accepted a forbidden treasury history mutation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('TREASURY_', $e->getMessage());
        }
    }

    public function test_ledger_first_settlement_in_next_statement_keeps_the_original_closure_unchanged(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $carry = $this->treasuryCarry($c, $s, 'ledger', $p['line_id']);
        $this->assertSame(1, $carry['closed']['reconciliation']['unmatched_ledger_lines']);
        $this->assertSame(0, $carry['closed']['reconciliation']['unresolved_ledger_lines']);
        $this->assertSame('2500', $carry['closed']['reconciliation']['outstanding_ledger_net']);
        $dec = $this->next($c, '2500', [[...$this->statementData($c)['lines'][0], 'booked_on' => '2026-12-05']]);
        $this->op('GET', $this->path($c, $dec).'/ledger')
            ->assertOk()
            ->assertJsonPath('data.0.id', $p['line_id'])
            ->assertJsonPath('data.0.entry_date', '2026-11-04');
        $this->op('POST', $this->path($c, $dec).'/matches', $this->matchData($dec, $p['line_id']))->assertCreated();
        $this->close($c, $dec, 2)->assertOk()->assertJsonPath('data.reconciliation.unmatched_ledger_lines', 0);
        $old = $this->op('GET', $this->path($c, $s))->assertOk()->json('data');
        $this->assertSame($carry['closed']['closure'], $old['closure']);
        $this->assertSame($carry['closed']['reconciliation'], $old['reconciliation']);
        $this->assertDatabaseCount('accounting_entries', 1);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_statement_first_settlement_uses_a_later_explicit_posting_and_the_original_bank_row(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c);
        $row = DB::table('treasury_statement_lines')->where('statement_id', $s['id'])->value('id');
        $carry = $this->treasuryCarry($c, $s, 'statement', $row);
        $this->assertSame('-2500', $carry['closed']['reconciliation']['timing_net_difference']);
        $ledger = $this->datedEntry($c, '2026-12-04');
        $dec = $this->treasuryStatement($c, [
            'reference' => 'TEST-DEC',
            'starts_on' => '2026-12-01',
            'ends_on' => '2026-12-31',
            'opening_balance' => '2500',
            'closing_balance' => '2500',
            'lines' => [],
        ]);
        $this->op('GET', $this->path($c, $dec).'/rows')
            ->assertOk()
            ->assertJsonPath('data.0.id', $row)
            ->assertJsonPath('data.0.statement_id', $s['id']);
        $this->op('POST', $this->path($c, $dec).'/matches', [
            ...$this->matchData($dec, $ledger),
            'statement_line_ids' => [$row],
        ])->assertCreated();
        $this->close($c, $dec, 2)->assertOk()->assertJsonPath('data.reconciliation.unresolved_statement_lines', 0);
        $this->op('GET', $this->path($c, $s))
            ->assertOk()
            ->assertJsonPath('data.closure.sha256', $carry['closed']['closure']['sha256'])
            ->assertJsonPath('data.reconciliation.unmatched_statement_lines', 1);
    }

    public function test_unsettled_items_require_new_review_and_can_cross_a_closed_fiscal_year(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $nov = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $first = $this->treasuryCarry($c, $nov, 'ledger', $p['line_id']);
        $dec = $this->next($c);
        $this->close($c, $dec, 1)->assertUnprocessable();
        $this->op('POST', $this->path($c, $dec).'/outstanding', [
            ...$this->outstandingData($c, $dec, 'ledger', $p['line_id']),
            'expected_on' => '2026-12-31',
        ])->assertUnprocessable();
        $renewal = $this->treasuryCarry($c, $dec, 'ledger', $p['line_id']);
        $this->assertSame($first['item']['sha256'], $renewal['item']['snapshot']['incoming'][0]['sha256']);
        $this->operationUser();
        foreach (['periods' => $c['period']['id'], 'years' => $c['year']['id']] as $kind => $id) {
            $this->op('POST', $c['root'].'/'.$kind.'/'.$id.'/close', [
                'version' => 1,
                'note' => 'Test year close',
            ])->assertOk();
        }
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
            'lines' => [[...$this->statementData($c)['lines'][0], 'booked_on' => '2027-01-05']],
        ]);
        $this->op('POST', $this->path($c, $jan).'/matches', $this->matchData($jan, $p['line_id']))->assertCreated();
        $this->close($c, $jan, 2)->assertOk();
        $this->assertDatabaseHas('accounting_years', ['id' => $c['year']['id'], 'status' => 'closed']);
        $this->assertDatabaseHas('accounting_entries', ['id' => $p['entry']['id'], 'sha256' => $p['entry']['sha256']]);
    }

    public function test_pending_decision_blocks_closure_and_matching_until_explicit_withdrawal(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c);
        $path = $this->path($c, $s);
        $data = $this->outstandingData($c, $s, 'ledger', $p['line_id']);
        $key = $this->fixtureId();
        $item = $this->op('POST', $path.'/outstanding', $data, $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $path.'/outstanding', $data, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $item['id']);
        $this->op('POST', $path.'/outstanding', [...$data, 'version' => 2])->assertUnprocessable();
        $this->op('POST', $path.'/outstanding', [...$data, 'note' => 'Different input'], $key)->assertConflict();
        $this->close($c, $s, 2)->assertUnprocessable();
        $this->op('POST', $path.'/void', [
            'version' => 2,
            'note' => 'Cannot orphan an outstanding decision',
        ])->assertUnprocessable();
        $this->op('POST', $path.'/matches', $this->matchData($s, $p['line_id'], 2))->assertUnprocessable();
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/review', [
            'version' => 2,
            'sha256' => $item['sha256'],
            'note' => 'Owner cannot review',
        ])->assertForbidden();
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/void', [
            'version' => 1,
            'note' => 'Stale version',
        ])->assertConflict();
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/void', [
            'version' => 2,
            'note' => 'Correction before matching',
        ])->assertOk();
        $this->op('POST', $path.'/matches', $this->matchData($s, $p['line_id'], 3))->assertCreated();
        $this->close($c, $s, 4)->assertOk();
        $this->assertDatabaseHas('treasury_outstanding', [
            'id' => $item['id'],
            'status' => 'void',
            'active_slot' => null,
        ]);
    }

    public function test_proof_hash_and_sql_history_remain_guarded_through_review_and_closure(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $path = $this->path($c, $s);
        $item = $this->op('POST', $path.'/outstanding', $this->outstandingData($c, $s, 'ledger', $p['line_id']))
            ->assertCreated()
            ->json('data');
        $this->operationUser('accountant');
        $review = ['version' => 2, 'sha256' => $item['sha256'], 'note' => 'Test review'];
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/review', [
            ...$review,
            'sha256' => str_repeat('0', 64),
        ])->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'infected']);
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/review', $review)->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean', 'sha256' => str_repeat('b', 64)]);
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/review', $review)->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['sha256' => $c['evidence']['sha256']]);
        $this->op('POST', $path.'/outstanding/'.$item['id'].'/review', $review)->assertOk();
        $this->sqlGuard(
            fn () => DB::table('treasury_outstanding')
                ->where('id', $item['id'])
                ->update(['expected_on' => '2027-01-01']),
        );
        $this->sqlGuard(fn () => DB::table('treasury_outstanding')->where('id', $item['id'])->delete());
        $closed = $this->close($c, $s, 3)->assertOk()->json('data');
        $this->sqlGuard(
            fn () => DB::table('treasury_outstanding')
                ->where('id', $item['id'])
                ->update(['status' => 'void', 'active_slot' => null]),
        );
        $this->sqlGuard(
            fn () => DB::table('treasury_closures')
                ->where('id', $closed['closure']['id'])
                ->update(['snapshot' => '{}']),
        );
        $this->sqlGuard(fn () => DB::table('treasury_closures')->where('id', $closed['closure']['id'])->delete());
        $this->op('GET', $path.'/closures')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.sha256', $closed['closure']['sha256']);
    }

    public function test_reopening_requires_successor_links_removed_and_retains_every_closure_snapshot(): void
    {
        $c = $this->treasuryContext();
        $p = $this->treasuryPosted($c);
        $nov = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $carry = $this->treasuryCarry($c, $nov, 'ledger', $p['line_id']);
        $dec = $this->next($c, '2500', [[...$this->statementData($c)['lines'][0], 'booked_on' => '2026-12-05']]);
        $m = $this->op('POST', $this->path($c, $dec).'/matches', $this->matchData($dec, $p['line_id']))
            ->assertCreated()
            ->json('data');
        $this->operationUser();
        $reopen = ['version' => 4, 'note' => 'Test authorized reopening'];
        $this->op('POST', $this->path($c, $nov).'/reopen', $reopen)->assertUnprocessable();
        $this->op('POST', $this->path($c, $dec).'/matches/'.$m['id'].'/void', [
            'version' => 2,
            'note' => 'Release successor dependency',
        ])->assertOk();
        $this->op('POST', $this->path($c, $nov).'/reopen', $reopen)->assertOk();
        $this->op(
            'POST',
            $this->path($c, $dec).'/matches',
            $this->matchData($dec, $p['line_id'], 3),
        )->assertUnprocessable();
        $this->close($c, $nov, 5)->assertOk();
        $this->op('GET', $this->path($c, $nov).'/closures')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.1.sha256', $carry['closed']['closure']['sha256']);
        $this->op(
            'POST',
            $this->path($c, $dec).'/matches',
            $this->matchData($dec, $p['line_id'], 3),
        )->assertCreated();
        $this->close($c, $dec, 4)->assertOk();
    }

    public function test_outstanding_totals_above_one_movement_limit_are_exact_and_invalid_sql_slots_are_rejected(): void
    {
        $c = $this->treasuryContext();
        $a = $this->treasuryPosted($c, '1000000000000000000');
        $b = $this->treasuryPosted($c, '1000000000000000000');
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        foreach ([$a['line_id'], $b['line_id']] as $i => $line) {
            $item = $this->op(
                'POST',
                $this->path($c, $s).'/outstanding',
                $this->outstandingData($c, $s, 'ledger', $line, 1 + $i * 2),
            )
                ->assertCreated()
                ->json('data');
            $raw = (array) DB::table('treasury_outstanding')->where('id', $item['id'])->first();
            $this->sqlGuard(
                fn () => DB::table('treasury_outstanding')->insert([
                    ...$raw,
                    'id' => $this->fixtureId(),
                    'active_slot' => null,
                ]),
            );
            $this->sqlGuard(
                fn () => DB::table('treasury_outstanding')
                    ->where('id', $item['id'])
                    ->update([
                        'status' => 'approved',
                        'reviewed_by' => $c['actor']->id,
                        'reviewed_at' => now(),
                        'active_slot' => null,
                    ]),
            );
            $this->operationUser('accountant');
            $this->op('POST', $this->path($c, $s).'/outstanding/'.$item['id'].'/review', [
                'version' => 2 + $i * 2,
                'sha256' => $item['sha256'],
                'note' => 'Test exact amount',
            ])->assertOk();
        }
        $closed = $this->close($c, $s, 5)->assertOk()->json('data');
        $this->assertSame('2000000000000000000', $closed['reconciliation']['outstanding_ledger_net']);
        $this->assertSame('2000000000000000000', $closed['closure']['snapshot']['totals']['timing_net_difference']);
    }

    public function test_zero_net_does_not_hide_two_unresolved_opposite_ledger_movements(): void
    {
        $c = $this->treasuryContext();
        $a = $this->datedEntry($c, '2026-11-04');
        $b = $this->datedEntry($c, '2026-11-04', true);
        $s = $this->treasuryStatement($c, ['closing_balance' => '0', 'lines' => []]);
        $this->op('GET', $this->path($c, $s))
            ->assertOk()
            ->assertJsonPath('data.reconciliation.unmatched_ledger_net', '0')
            ->assertJsonPath('data.reconciliation.unresolved_ledger_lines', 2);
        $this->close($c, $s, 1)->assertUnprocessable();
        foreach ([$a, $b] as $i => $line) {
            $item = $this->op(
                'POST',
                $this->path($c, $s).'/outstanding',
                $this->outstandingData($c, $s, 'ledger', $line, 1 + $i * 2),
            )
                ->assertCreated()
                ->json('data');
            $this->operationUser('accountant');
            $this->op('POST', $this->path($c, $s).'/outstanding/'.$item['id'].'/review', [
                'version' => 2 + $i * 2,
                'sha256' => $item['sha256'],
                'note' => 'Explicit test review',
            ])->assertOk();
        }
        $closed = $this->close($c, $s, 5)->assertOk()->json('data');
        $this->assertCount(2, $closed['closure']['snapshot']['outstanding']);
        $this->assertSame('0', $closed['reconciliation']['outstanding_ledger_net']);
    }

    public function test_scope_draft_dates_recent_confirmation_and_mfa_cannot_be_bypassed(): void
    {
        $c = $this->treasuryContext();
        $s = $this->treasuryStatement($c);
        $d = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $draftLine = (string) DB::table('accounting_entry_lines')
            ->where('entry_id', $d['id'])
            ->orderBy('position')
            ->value('id');
        $data = $this->outstandingData($c, $s, 'ledger', $draftLine);
        $this->op('POST', $this->path($c, $s).'/outstanding', $data)->assertUnprocessable();
        $oldLine = $this->datedEntry($c, '2026-10-01');
        $this->op('POST', $this->path($c, $s).'/outstanding', [
            ...$data,
            'line_id' => $oldLine,
        ])->assertUnprocessable();
        $this->op('POST', $this->path($c, $s).'/outstanding', [
            ...$data,
            'expected_on' => '2026-11-30',
        ])->assertUnprocessable();
        $other = $this->accountingContext();
        $p = $this->treasuryPosted($other);
        $this->op('POST', $this->path($c, $s).'/outstanding', [
            ...$data,
            'line_id' => $p['line_id'],
        ])->assertUnprocessable();
        $this->op('GET', $other['root'].'/treasury/statements/'.$s['id'].'/outstanding')->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('POST', $this->path($c, $s).'/outstanding', $data)->assertForbidden();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $this->path($c, $s).'/closures')->assertForbidden();
        $this->operationUser('manager');
        $this->op('GET', $this->path($c, $s).'/outstanding')->assertForbidden();
    }

    public function test_live_outstanding_item_requires_a_different_accountant_to_approve_it(): void
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
            'note' => 'Test review',
        ])->assertOk();
        $this->op('POST', $c['treasury_root'].'/bindings/'.$c['binding']['id'].'/review', [
            'sha256' => $c['binding']['sha256'],
            'note' => 'Test review',
        ])->assertOk();
        $s = $this->treasuryStatement($c);
        $row = DB::table('treasury_statement_lines')->where('statement_id', $s['id'])->value('id');
        $item = $this->op(
            'POST',
            $this->path($c, $s).'/outstanding',
            $this->outstandingData($c, $s, 'statement', $row),
        )
            ->assertCreated()
            ->json('data');
        $review = ['version' => 2, 'sha256' => $item['sha256'], 'note' => 'Independent test review'];
        $this->op(
            'POST',
            $this->path($c, $s).'/outstanding/'.$item['id'].'/review',
            $review,
        )->assertUnprocessable();
        $this->operationUser('accountant');
        $this->op('POST', $this->path($c, $s).'/outstanding/'.$item['id'].'/review', $review)->assertOk();
        $this->close($c, $s, 3)->assertOk()->assertJsonPath('data.reconciliation.available_funds', null);
    }
}
