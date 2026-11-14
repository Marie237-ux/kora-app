<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\Support\WithdrawalFixtures;
use Tests\TestCase;

final class WithdrawalsTest extends TestCase
{
    use AccountingFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures, WithdrawalFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
    }

    private function summary(array $c): array
    {
        return $this->op('GET', $c['treasury_root'].'/withdrawal-summary')
            ->assertOk()
            ->json('data');
    }

    private function closeRequest(array $c, array $w, string $action, string $remaining)
    {
        return $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/'.$action, [
            'version' => $w['version'],
            'sha256' => $w['sha256'],
            'date' => '2026-11-05',
            'note' => 'Test '.$action,
            'document_id' => $c['evidence']['id'],
            'accepted_remaining' => $remaining,
        ]);
    }

    private function reverse(array $c, array $w, array $p)
    {
        return $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts/'.$p['id'].'/reverse',
            $this->withdrawalReversal($c, $p),
        );
    }

    private function guard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL accepted an invalid withdrawal mutation.');
        } catch (QueryException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_requests_are_reviewed_and_partial_receipts_link_the_existing_transfer_once_with_actual_fees(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c, approve: false);
        $this->assertSame('1000', $this->summary($c)['totals']['pending_review_gross']);
        $this->operationUser('accountant');
        $entry = $this->withdrawalEntry($c);
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $entry, ['version' => 1]),
        )->assertUnprocessable();
        $this->operationUser('accountant');
        $w = $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/approve', [
            'version' => 1,
            'sha256' => $w['sha256'],
            'date' => '2026-11-05',
            'note' => 'Test',
        ])
            ->assertOk()
            ->json('data');
        $key = $this->fixtureId();
        $body = $this->withdrawalReceiptData($c, $entry);
        $p = $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts', $body, $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts', $body, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $p['id']);
        $this->assertSame('400', $this->summary($c)['totals']['pending_gross']);
        $this->withdrawalReceipt($c, $w, $this->withdrawalEntry($c, '400', '0'), ['reference' => 'TEST-SECOND']);
        $totals = $this->summary($c)['totals'];
        $this->assertSame('1000', $totals['received_gross']);
        $this->assertSame('980', $totals['received_net']);
        $this->assertSame('20', $totals['fees_paid']);
        $this->assertSame('0', $totals['pending_gross']);
        $this->assertDatabaseCount('accounting_entries', 2);
        $this->assertDatabaseCount('withdrawal_receipts', 2);
        $this->assertDatabaseCount('accounting_sources', 0);
    }

    public function test_forecast_shows_known_pending_withdrawals_without_adding_internal_transfers_to_company_outflows(): void
    {
        $c = $this->withdrawalContext();
        $this->withdrawal($c, approve: false);
        $this->op('GET', $c['treasury_root'].'/forecast?from=2026-11-05&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.cash.pending_withdrawals', '1000')
            ->assertJsonPath('data.totals.known_outflows', '0')
            ->assertJsonPath('data.cash.available_funds', null)
            ->assertJsonPath('data.withdrawals.scope', 'registered_withdrawals_only');
    }

    public function test_cancellation_accepts_only_the_current_remainder_and_preserves_actual_receipts(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $p = $this->withdrawalReceipt($c, $w, $this->withdrawalEntry($c));
        $this->closeRequest($c, $w, 'cancel', '1000')->assertConflict();
        $this->closeRequest($c, $w, 'void', '400')->assertUnprocessable();
        $cancelled = $this->closeRequest($c, $w, 'cancel', '400')->assertOk()->json('data');
        $this->assertSame($w['sha256'], $cancelled['sha256']);
        $this->assertSame('600', $cancelled['balance']['received_gross']);
        $this->assertSame('400', $cancelled['balance']['cancelled_gross']);
        $this->assertSame('0', $this->summary($c)['totals']['pending_gross']);
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals',
            $this->withdrawalData($c, ['replaces_id' => $w['id']]),
        )->assertUnprocessable();
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $this->withdrawalEntry($c, '400', '0'), [
                'version' => 3,
                'reference' => 'TEST-SECOND',
            ]),
        )->assertUnprocessable();
        $this->reverse($c, $w, $p)->assertOk();
        $this->assertSame('0', $this->summary($c)['totals']['pending_gross']);
        $this->assertSame('1000', $this->summary($c)['totals']['cancelled_gross']);
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $this->withdrawalEntry($c, '700', '20'), [
                'version' => 3,
                'replaces_id' => $p['id'],
            ]),
        )->assertUnprocessable();
        $this->withdrawalReceipt($c, $w, $this->withdrawalEntry($c), ['version' => 3, 'replaces_id' => $p['id']]);
        $totals = $this->summary($c)['totals'];
        $this->assertSame('600', $totals['received_gross']);
        $this->assertSame('400', $totals['cancelled_gross']);
        $this->assertSame('0', $totals['pending_gross']);
    }

    public function test_receipt_corrections_are_exact_atomic_and_cannot_be_bypassed_by_ordinary_reversals(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $e = $this->withdrawalEntry($c);
        $p = $this->withdrawalReceipt($c, $w, $e);
        $this->op('POST', $c['root'].'/entries/'.$e['id'].'/reverse', [
            ...$this->withdrawalReversal($c, $p),
            'version' => 2,
        ])->assertUnprocessable();
        $draft = $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))
            ->assertCreated()
            ->json('data');
        $this->guard(
            fn () => DB::table('accounting_entries')
                ->where('id', $draft['id'])
                ->update(['reversal_of' => $e['id'], 'status' => 'posted']),
        );
        DB::table('accounting_periods')
            ->where('id', $c['period']['id'])
            ->update(['status' => 'closed']);
        $this->reverse($c, $w, $p)->assertUnprocessable();
        $this->assertDatabaseHas('withdrawal_receipts', ['id' => $p['id'], 'status' => 'recorded']);
        $this->assertDatabaseCount('accounting_entries', 2);
        DB::table('accounting_periods')
            ->where('id', $c['period']['id'])
            ->update(['status' => 'open']);
        $reversed = $this->reverse($c, $w, $p)->assertOk()->json('data');
        $this->assertSame($p['snapshot'], $reversed['snapshot']);
        $this->assertSame('1000', $this->summary($c)['totals']['pending_gross']);
        $counter = DB::table('accounting_entries')->where('id', $reversed['reversal_entry_id'])->first();
        $this->assertSame($e['id'], $counter->reversal_of);
        $this->assertSame(
            '600',
            (string) DB::table('accounting_entry_lines')
                ->where('entry_id', $counter->id)
                ->where('account_id', $c['accounts']['11']['id'])
                ->value('debit'),
        );
        $this->op('POST', $c['root'].'/entries/'.$counter->id.'/reverse', [
            ...$this->withdrawalReversal($c, $p),
            'version' => 2,
        ])->assertUnprocessable();
        $this->reverse($c, $w, $p)->assertConflict();
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $e, ['replaces_id' => $p['id']]),
        )->assertUnprocessable();
    }

    public function test_recording_errors_can_replace_request_and_transfer_references_only_with_explicit_predecessors(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $p = $this->withdrawalReceipt($c, $w, $this->withdrawalEntry($c));
        $this->reverse($c, $w, $p)->assertOk();
        $this->closeRequest($c, $w, 'void', '1000')->assertOk();
        $this->op('POST', $c['treasury_root'].'/withdrawals', $this->withdrawalData($c))->assertUnprocessable();
        $new = $this->withdrawal($c, ['replaces_id' => $w['id'], 'amount' => '900']);
        $entry = $this->withdrawalEntry($c, '900', '30');
        $body = $this->withdrawalReceiptData($c, $entry);
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$new['id'].'/receipts',
            $body,
        )->assertUnprocessable();
        $replacement = $this->withdrawalReceipt($c, $new, $entry, ['replaces_id' => $p['id']]);
        $this->assertSame(2, $new['revision']);
        $this->assertSame(2, $replacement['revision']);
        $this->assertSame($p['id'], $replacement['snapshot']['replaces']['id']);
        $this->assertSame('900', $this->summary($c)['totals']['received_gross']);
        $this->assertDatabaseCount('withdrawals', 2);
        $this->assertDatabaseCount('withdrawal_receipts', 2);
        $this->assertDatabaseCount('accounting_entries', 3);
    }

    public function test_both_sides_must_be_unmatched_before_correction_and_closed_statements_derive_reconciliation(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $entry = $this->withdrawalEntry($c);
        $p = $this->withdrawalReceipt($c, $w, $entry);
        $matches = [];
        foreach (
            ['source' => ['600', '-600', 'out'], 'destination' => ['580', '580', 'in']] as $side => [$amount, $closing, $direction]
        ) {
            $cc = [...$c, 'binding' => $c[$side.'_binding']];
            $s = $this->treasuryStatement($cc, [
                'ends_on' => '2026-11-05',
                'closing_balance' => $closing,
                'lines' => [
                    [
                        'external_id' => 'TEST-'.$side,
                        'booked_on' => '2026-11-05',
                        'reference' => 'TEST',
                        'direction' => $direction,
                        'amount' => $amount,
                    ],
                ],
            ]);
            $m = $this->op(
                'POST',
                $c['treasury_root'].'/statements/'.$s['id'].'/matches',
                $this->matchData($s, (string) $entry[$side.'_line_id']),
            )
                ->assertCreated()
                ->json('data');
            $this->reverse($c, $w, $p)->assertUnprocessable();
            $this->guard(
                fn () => DB::table('withdrawal_receipts')
                    ->where('id', $p['id'])
                    ->update(['status' => 'reversing']),
            );
            $s = $this->op('POST', $c['treasury_root'].'/statements/'.$s['id'].'/close', [
                'version' => 2,
                'note' => 'Test close',
            ])
                ->assertOk()
                ->json('data');
            $matches[] = [$s, $m];
        }
        $this->assertSame('600', $this->summary($c)['totals']['reconciled_gross']);
        $this->assertSame('0', $this->summary($c)['totals']['unreconciled_received_gross']);
        $this->operationUser();
        foreach ($matches as [$s, $m]) {
            $s = $this->op('POST', $c['treasury_root'].'/statements/'.$s['id'].'/reopen', [
                'version' => $s['version'],
                'note' => 'Test correction',
            ])
                ->assertOk()
                ->json('data');
            $this->op('POST', $c['treasury_root'].'/statements/'.$s['id'].'/matches/'.$m['id'].'/void', [
                'version' => $s['version'],
                'note' => 'Test correction',
            ])->assertOk();
        }
        $this->reverse($c, $w, $p)->assertOk();
        $this->assertSame('0', $this->summary($c)['totals']['received_gross']);
    }

    public function test_scope_wrong_sides_fees_duplicate_entries_and_other_treasury_movements_are_refused(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $entry = $this->withdrawalEntry($c);
        foreach (
            [
                ['accepted_fee' => '21'],
                ['accepted_net' => '579'],
                ['accepted_gross' => '599'],
                ['destination_line_id' => $entry['source_line_id']],
            ] as $changes
        ) {
            $this->op(
                'POST',
                $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
                $this->withdrawalReceiptData($c, $entry, $changes),
            )->assertUnprocessable();
        }
        $this->withdrawalReceipt($c, $w, $entry);
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $entry, ['reference' => 'OTHER']),
        )->assertUnprocessable();
        $other = $this->withdrawalContext();
        $foreign = $this->withdrawalEntry($other);
        $this->operationUser('accountant');
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $foreign),
        )->assertNotFound();
        $this->op('GET', $other['treasury_root'].'/withdrawals/'.$w['id'])->assertNotFound();
        $third = $this->account();
        $this->operationUser();
        $this->op('POST', $c['treasury_root'].'/bindings', [
            'treasury_account_id' => $third['id'],
            'account_id' => $c['accounts']['55']['id'],
            'inflow_side' => 'debit',
            'version' => 0,
            'note' => 'Test third treasury',
        ])->assertCreated();
        $bad = $this->withdrawalEntry($c, '300', '20');
        $this->operationUser('accountant');
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $bad, ['reference' => 'BAD-THIRD']),
        )->assertUnprocessable();
        $this->assertSame('600', $this->summary($c)['totals']['received_gross']);
    }

    public function test_dates_proofs_and_current_configuration_are_checked_without_accepting_future_receipts(): void
    {
        $c = $this->withdrawalContext();
        foreach (
            [
                ['amount' => '0'],
                ['amount' => '1.5'],
                ['requested_on' => '2026-11-06'],
                ['requested_on' => '2026-11-04'],
                ['source_binding_id' => $c['destination_binding']['id']],
            ] as $changes
        ) {
            $this->op(
                'POST',
                $c['treasury_root'].'/withdrawals',
                $this->withdrawalData($c, $changes),
            )->assertUnprocessable();
        }
        $w = $this->withdrawal($c, approve: false);
        $this->operationUser('accountant');
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $approval = ['version' => 1, 'sha256' => $w['sha256'], 'date' => '2026-11-05', 'note' => 'Test'];
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/approve',
            $approval,
        )->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean']);
        $w = $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/approve', $approval)
            ->assertOk()
            ->json('data');
        $e = $this->withdrawalEntry($c);
        $future = $this->withdrawalEntry($c, '600', '20', '2026-11-06');
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $future),
        )->assertUnprocessable();
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $e, ['received_at' => '2026-11-05T07:00:00Z']),
        )->assertUnprocessable();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts',
            $this->withdrawalReceiptData($c, $e),
        )->assertForbidden();
        $this->operationUser('support');
        $this->op('GET', $c['treasury_root'].'/withdrawals')->assertForbidden();
        $this->operationUser('accountant');
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['treasury_root'].'/withdrawal-summary')->assertForbidden();
    }

    public function test_sql_keeps_history_scope_capacity_and_intermediate_states_protected(): void
    {
        $c = $this->withdrawalContext();
        $w = $this->withdrawal($c);
        $p = $this->withdrawalReceipt($c, $w, $this->withdrawalEntry($c));
        $this->guard(
            fn () => DB::table('withdrawals')
                ->where('id', $w['id'])
                ->update(['amount' => 2000]),
        );
        $this->guard(fn () => DB::table('withdrawals')->where('id', $w['id'])->delete());
        $this->guard(fn () => DB::table('withdrawal_receipts')->where('id', $p['id'])->delete());
        $this->guard(
            fn () => DB::table('withdrawal_receipts')
                ->where('id', $p['id'])
                ->update(['fee' => 0]),
        );
        $this->guard(
            fn () => DB::table('withdrawal_receipts')
                ->where('id', $p['id'])
                ->update(['status' => 'reversed', 'version' => 2, 'reference_slot' => null]),
        );
        $this->guard(
            fn () => DB::table('withdrawals')
                ->where('id', $w['id'])
                ->update(['status' => null, 'reference_slot' => null]),
        );
        $entry = $this->withdrawalEntry($c, '700', '20');
        $row = (array) DB::table('withdrawal_receipts')->where('id', $p['id'])->first();
        $this->guard(
            fn () => DB::table('withdrawal_receipts')->insert([
                ...$row,
                'id' => $this->fixtureId(),
                'reference' => 'TEST-OVERRUN',
                'entry_id' => $entry['id'],
                'source_line_id' => $entry['source_line_id'],
                'destination_line_id' => $entry['destination_line_id'],
                'gross' => 700,
                'net' => 680,
            ]),
        );
        $this->assertDatabaseCount('withdrawal_receipts', 1);
    }

    public function test_live_reviewers_are_distinct_from_request_creator_and_transfer_poster(): void
    {
        $c = $this->withdrawalContext('live');
        $creator = auth()->user();
        $w = $this->withdrawal($c, approve: false);
        $creator->forceFill(['roles' => ['owner', 'accountant']])->save();
        $approval = ['version' => 1, 'sha256' => $w['sha256'], 'date' => '2026-11-05', 'note' => 'Test'];
        $this->op(
            'POST',
            $c['treasury_root'].'/withdrawals/'.$w['id'].'/approve',
            $approval,
        )->assertUnprocessable();
        $this->operationUser('accountant');
        $w = $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/approve', $approval)
            ->assertOk()
            ->json('data');
        $entry = $this->withdrawalEntry($c);
        $body = $this->withdrawalReceiptData($c, $entry);
        $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts', $body)->assertUnprocessable();
        $this->app['auth']->forgetGuards();
        $this->actingAs($creator, 'web');
        $this->op('POST', $c['treasury_root'].'/withdrawals/'.$w['id'].'/receipts', $body)->assertUnprocessable();
        $this->operationUser('accountant');
        $this->withdrawalReceipt($c, $w, $entry);
    }

    public function test_credit_oriented_bindings_preserve_both_transfer_sides_and_fee_arithmetic(): void
    {
        $c = $this->withdrawalContext(inflow: 'credit');
        $w = $this->withdrawal($c);
        $p = $this->withdrawalReceipt($c, $w, $this->withdrawalEntry($c));
        $this->assertSame('600', $p['snapshot']['gross']);
        $this->assertSame('580', $p['snapshot']['net']);
        $this->assertSame('20', $p['snapshot']['fee']);
    }

    public function test_summary_totals_are_exact_beyond_native_integers_and_are_not_limited_by_list_pagination(): void
    {
        $c = $this->withdrawalContext();
        foreach (range(1, 25) as $i) {
            $this->withdrawal(
                $c,
                ['reference' => 'TEST-LARGE-'.$i, 'amount' => '1000000000000000000'],
                approve: false,
            );
        }
        $this->op('GET', $c['treasury_root'].'/withdrawals')
            ->assertOk()
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('meta.total', 25);
        $s = $this->summary($c);
        $this->assertSame('25000000000000000000', $s['totals']['pending_gross']);
        $this->assertNull($s['available_funds']);
        $this->assertFalse($s['availability_verified']);
        $other = $this->withdrawalContext();
        $this->assertSame('0', $this->summary($other)['totals']['pending_gross']);
    }
}
