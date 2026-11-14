<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\LetteringFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\TestCase;

final class AccountingLetteringTest extends TestCase
{
    use AccountingFixtures, LetteringFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    private function items(array $c, string $date = '2026-11-04', string $status = 'all', int $page = 1): array
    {
        return $this->op(
            'GET',
            $c['root'].
                '/lettering-items?'.
                http_build_query([
                    'account_id' => $c['account']['id'],
                    'as_of' => $date,
                    'status' => $status,
                    'page' => $page,
                ]),
        )
            ->assertOk()
            ->json('data');
    }

    private function match(array $c, array $a, array $b, string $amount = '1000', string $date = '2026-11-04'): array
    {
        return $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $b, $amount), $date),
        )
            ->assertCreated()
            ->json('data');
    }

    private function cancel(array $c, array $g, string $date = '2026-11-05')
    {
        return $this->op('POST', $c['root'].'/letterings/'.$g['id'].'/void', [
            'version' => $g['version'],
            'sha256' => $g['sha256'],
            'voided_on' => $date,
            'note' => 'Test correction',
        ]);
    }

    private function guard(callable $write): void
    {
        try {
            DB::transaction($write);
            $this->fail('SQL accepted a forbidden lettering operation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ACCOUNTING_LETTERING_', $e->getMessage());
        }
    }

    public function test_partial_then_final_allocation_preserves_the_posted_journal_and_never_changes_business_payments(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit', '400');
        $first = $this->match($c, $a, $b, '400');
        $r = $this->items($c);
        $this->assertSame(['unmatched' => 0, 'partial' => 1, 'matched' => 1], $r['counts']);
        $this->assertSame('600', $r['totals']['remaining_debit']);
        $this->assertSame('0', $r['totals']['remaining_credit']);
        $this->assertSame(1, $this->items($c, status: 'open')['meta']['total']);
        $b2 = $this->letteringEntry($c, 'credit', '600');
        $this->match($c, $a, $b2, '600');
        $this->assertSame(0, $this->items($c, status: 'open')['meta']['total']);
        $this->assertSame('0', $this->items($c)['totals']['remaining_balance']);
        $this->op('GET', $c['root'].'/entries/'.$a['id'])
            ->assertOk()
            ->assertJsonPath('data.sha256', $a['sha256']);
        $this->op('GET', $c['root'].'/letterings/'.$first['id'])
            ->assertOk()
            ->assertJsonPath('data.snapshot', $first['snapshot']);
        $this->op('GET', $c['root'].'/letterings')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('supplier_obligations', 0);
        $this->assertFalse($r['business_settlement_verified']);
    }

    public function test_supplier_allocation_can_match_many_invoices_to_one_payment(): void
    {
        $c = $this->letteringContext('supplier');
        $a = $this->letteringEntry($c, 'credit', '300');
        $b = $this->letteringEntry($c, 'credit', '700');
        $p = $this->letteringEntry($c, 'debit');
        $data = $this->letteringData($c, [
            ['ledger_line_id' => $a['line_id'], 'amount' => '300'],
            ['ledger_line_id' => $b['line_id'], 'amount' => '700'],
            ['ledger_line_id' => $p['line_id'], 'amount' => '1000'],
        ]);
        $this->op('POST', $c['root'].'/letterings', $data)
            ->assertCreated()
            ->assertJsonPath('data.party_type', 'supplier');
        $this->assertSame(['unmatched' => 0, 'partial' => 0, 'matched' => 3], $this->items($c)['counts']);
        $this->assertSame(0, $this->items($c, status: 'open')['meta']['total']);
    }

    public function test_scope_drafts_dates_duplicates_and_invalid_or_excess_amounts_are_rejected_atomically(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        foreach (['0', '-1', '1.2', '010', '1001', '1000000000000000001'] as $amount) {
            $this->op(
                'POST',
                $c['root'].'/letterings',
                $this->letteringData($c, $this->letteringPair($a, $b, $amount)),
            )->assertUnprocessable();
        }
        $draft = $this->letteringEntry($c, 'credit', post: false);
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $draft)),
        )->assertUnprocessable();
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $a)),
        )->assertUnprocessable();
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $b), '2026-11-03'),
        )->assertUnprocessable();
        $different = $this->letteringContext();
        $foreign = $this->letteringEntry($different, 'credit');
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $foreign)),
        )->assertNotFound();
        $otherParty = [...$c, 'party' => $different['party']];
        $wrong = $this->letteringEntry($otherParty, 'credit');
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $wrong)),
        )->assertUnprocessable();
        $unbalanced = $this->letteringPair($a, $b);
        $unbalanced[1]['amount'] = '999';
        $this->op('POST', $c['root'].'/letterings', $this->letteringData($c, $unbalanced))->assertUnprocessable();
        $ordinary = $b;
        $ordinary['line_id'] = (int) DB::table('accounting_entry_lines')
            ->where('entry_id', $b['id'])
            ->where('account_id', $c['accounts']['11']['id'])
            ->value('id');
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $ordinary)),
        )->assertUnprocessable();
        $this->assertDatabaseCount('accounting_letterings', 0);
        $this->assertDatabaseCount('accounting_lettering_lines', 0);
    }

    public function test_cancellation_releases_capacity_on_its_effective_date_and_preserves_earlier_reports_and_hashes(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        $g = $this->match($c, $a, $b);
        $before = $this->items($c);
        $this->cancel($c, [...$g, 'sha256' => str_repeat('0', 64)])->assertUnprocessable();
        $this->cancel($c, [...$g, 'version' => 2])->assertConflict();
        $void = $this->cancel($c, $g)->assertOk()->json('data');
        $this->assertSame($g['snapshot'], $void['snapshot']);
        $this->assertSame($g['sha256'], $void['sha256']);
        $this->assertSame($before, $this->items($c));
        $this->assertSame('1000', $this->items($c, '2026-11-05')['totals']['remaining_debit']);
        $this->cancel($c, $g)->assertConflict();
        $this->match($c, $a, $b, date: '2026-11-05');
        $this->assertSame($before, $this->items($c));
        $this->assertSame('0', $this->items($c, '2026-11-05')['totals']['remaining_debit']);
        $this->assertDatabaseCount('accounting_lettering_lines', 4);
    }

    public function test_effective_dates_cannot_rewrite_later_book_history(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        $g = $this->match($c, $a, $b, '400', '2026-11-06');
        $this->cancel($c, $g, '2026-11-05')->assertUnprocessable();
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $b, '100'), '2026-11-05'),
        )->assertUnprocessable();
        $this->cancel($c, $g, '2026-11-07')->assertOk();
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $b, '100'), '2026-11-06'),
        )->assertUnprocessable();
        $this->match($c, $a, $b, date: '2026-11-07');
    }

    public function test_closed_year_lines_can_be_settled_in_an_open_year_without_changing_their_entries(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        foreach (['periods' => 'period', 'years' => 'year'] as $kind => $key) {
            $this->op('POST', $c['root'].'/'.$kind.'/'.$c[$key]['id'].'/close', [
                'version' => 1,
                'note' => 'Test close',
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
        $b = $this->letteringEntry($c, 'credit', date: '2027-01-04');
        $this->match($c, $a, $b, date: '2027-01-04');
        $this->assertSame('0', $this->items($c, '2027-01-04')['totals']['remaining_balance']);
        $this->assertSame(2, $this->items($c, '2027-01-04')['counts']['matched']);
        $this->op('GET', $c['root'].'/entries/'.$a['id'])
            ->assertOk()
            ->assertJsonPath('data.sha256', $a['sha256']);
    }

    public function test_closed_period_blocks_new_lettering_and_cancellation_but_preserves_reads(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        $g = $this->match($c, $a, $b, '400');
        $this->op('POST', $c['root'].'/periods/'.$c['period']['id'].'/close', [
            'version' => 1,
            'note' => 'Test close',
        ])->assertOk();
        $this->cancel($c, $g)->assertUnprocessable();
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, $this->letteringPair($a, $b, '600')),
        )->assertUnprocessable();
        $this->assertSame('600', $this->items($c)['totals']['remaining_debit']);
    }

    public function test_sql_protects_content_members_transitions_and_remaining_capacity(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        $g = $this->match($c, $a, $b);
        $this->guard(
            fn () => DB::table('accounting_letterings')
                ->where('id', $g['id'])
                ->update(['snapshot' => '{}']),
        );
        $this->guard(fn () => DB::table('accounting_letterings')->where('id', $g['id'])->delete());
        $this->guard(
            fn () => DB::table('accounting_letterings')
                ->where('id', $g['id'])
                ->update(['status' => 'void', 'version' => 2, 'voided_on' => null]),
        );
        $this->guard(
            fn () => DB::table('accounting_lettering_lines')
                ->where('lettering_id', $g['id'])
                ->update(['amount' => 1]),
        );
        $this->guard(fn () => DB::table('accounting_lettering_lines')->where('lettering_id', $g['id'])->delete());
        $this->guard(
            fn () => DB::table('accounting_lettering_lines')->insert([
                'lettering_id' => $g['id'],
                'ledger_line_id' => $a['line_id'],
                'side' => 'debit',
                'amount' => 1,
            ]),
        );
        $this->guard(function () use ($g, $a, $b) {
            $copy = (array) DB::table('accounting_letterings')->where('id', $g['id'])->first();
            $copy['id'] = $this->fixtureId();
            $copy['status'] = 'building';
            DB::table('accounting_letterings')->insert($copy);
            foreach ([[$a, 'debit'], [$b, 'credit']] as [$entry, $side]) {
                DB::table('accounting_lettering_lines')->insert([
                    'lettering_id' => $copy['id'],
                    'ledger_line_id' => $entry['line_id'],
                    'side' => $side,
                    'amount' => 1,
                ]);
            }
            DB::table('accounting_letterings')
                ->where('id', $copy['id'])
                ->update(['status' => 'active']);
        });
        $freeDebit = $this->letteringEntry($c, 'debit');
        $freeCredit = $this->letteringEntry($c, 'credit');
        $this->guard(function () use ($g, $freeDebit, $freeCredit) {
            $copy = (array) DB::table('accounting_letterings')->where('id', $g['id'])->first();
            $copy['id'] = $this->fixtureId();
            $copy['status'] = 'building';
            DB::table('accounting_letterings')->insert($copy);
            DB::table('accounting_lettering_lines')->insert([
                'lettering_id' => $copy['id'],
                'ledger_line_id' => $freeDebit['line_id'],
                'side' => 'debit',
                'amount' => 1,
            ]);
            DB::table('accounting_lettering_lines')->insert([
                'lettering_id' => $copy['id'],
                'ledger_line_id' => $freeCredit['line_id'],
                'side' => 'credit',
                'amount' => 2,
            ]);
            DB::table('accounting_letterings')
                ->where('id', $copy['id'])
                ->update(['status' => 'active']);
        });
    }

    public function test_proof_permissions_recent_confirmation_ownership_and_idempotency_are_enforced(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        $data = $this->letteringData($c, $this->letteringPair($a, $b));
        $key = $this->fixtureId();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $this->op('POST', $c['root'].'/letterings', $data)->assertUnprocessable();
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean']);
        $g = $this->op('POST', $c['root'].'/letterings', $data, $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['root'].'/letterings', $data, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $g['id']);
        $this->op('POST', $c['root'].'/letterings', [...$data, 'note' => 'Changed'], $key)->assertConflict();
        $other = $this->accountingContext();
        $this->op('GET', $other['root'].'/letterings/'.$g['id'])->assertNotFound();
        $this->cancel($other, $g)->assertNotFound();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->cancel($c, $g)->assertForbidden();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['root'].'/letterings')->assertForbidden();
        $this->operationUser('manager');
        $this->op('GET', $c['root'].'/letterings')->assertForbidden();
        $this->operationUser('accountant');
        $this->cancel($c, $g)->assertOk();
    }

    public function test_historical_auxiliary_identity_survives_chart_changes_and_net_zero_is_not_automatically_lettered(): void
    {
        $c = $this->letteringContext();
        $a = $this->letteringEntry($c, 'debit');
        $b = $this->letteringEntry($c, 'credit');
        $this->assertSame('0', $this->items($c)['totals']['remaining_balance']);
        $this->assertSame(2, $this->items($c)['counts']['unmatched']);
        $chart = array_map(
            fn ($row) => $row['code'] === '33' ? [...$row, 'auxiliary_type' => 'none', 'active' => false] : $row,
            $this->accountingChart(),
        );
        $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'TEST-CHANGE',
            'accounts' => $chart,
        ])->assertOk();
        $g = $this->match($c, $a, $b);
        $this->assertSame('customer', $g['snapshot']['lines'][0]['account']['auxiliary_type']);
        $this->assertSame(2, $this->items($c)['counts']['matched']);
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $this->cancel($c, $g)->assertOk();
    }

    public function test_report_totals_are_exact_beyond_one_operation_and_not_limited_to_the_current_page(): void
    {
        $c = $this->letteringContext();
        $pairs = [];
        for ($i = 0; $i < 2; $i++) {
            $a = $this->letteringEntry($c, 'debit', '1000000000000000000');
            $b = $this->letteringEntry($c, 'credit', '1000000000000000000');
            $pairs[] = $this->letteringPair($a, $b, '1000000000000000000');
        }
        $this->op(
            'POST',
            $c['root'].'/letterings',
            $this->letteringData($c, array_merge(...$pairs)),
        )->assertUnprocessable();
        foreach ($pairs as $pair) {
            $this->op('POST', $c['root'].'/letterings', $this->letteringData($c, $pair))->assertCreated();
        }
        for ($i = 0; $i < 21; $i++) {
            $this->letteringEntry($c, 'debit', '1');
        }
        $r = $this->items($c);
        $this->assertSame('2000000000000000021', $r['totals']['debit']);
        $this->assertSame('2000000000000000000', $r['totals']['allocated_debit']);
        $this->assertSame('21', $r['totals']['remaining_balance']);
        $this->assertCount(24, $r['rows']);
        $this->assertSame(25, $r['meta']['total']);
        $second = $this->items($c, page: 2);
        $this->assertCount(1, $second['rows']);
        $this->assertSame($r['totals'], $second['totals']);
        $this->assertSame(21, $this->items($c, status: 'open')['meta']['total']);
    }
}
