<?php

namespace Tests\Feature;

use App\Domain\Documents\BillingIdentity;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingFixtures;
use Tests\Support\BalanceObservationFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\TreasuryFixtures;
use Tests\TestCase;

final class BalanceObservationsTest extends TestCase
{
    use AccountingFixtures, BalanceObservationFixtures, ProcurementFixtures, RefreshDatabase, TreasuryFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T11:00:00Z'));
    }

    private function report(array $c): array
    {
        return $this->op('GET', $c['treasury_root'].'/balance-observation-summary')
            ->assertOk()
            ->json('data');
    }

    public function test_reviewed_balance_evidence_exposes_dated_own_funds_without_posting_or_certifying_current_cash(): void
    {
        $c = $this->treasuryContext();
        $o = $this->balanceObservation($c, approve: false);
        $this->assertSame('pending_review', $this->report($c)['accounts'][0]['state']);
        $this->operationUser('accountant');
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$o['id'].'/approve', [
            'version' => 1,
            'sha256' => $o['sha256'],
            'note' => 'Test',
        ])->assertOk();
        $r = $this->report($c);
        $this->assertSame('800', $r['totals']['available_funds']);
        $this->assertSame('0', $r['totals']['provider_held_funds']);
        $this->assertSame('2026-11-05T10:00:00+00:00', $r['as_of']);
        $this->assertFalse($r['current_availability_verified']);
        $this->assertDatabaseCount('accounting_entries', 0);
        $this->op('GET', $c['treasury_root'].'/forecast?from=2026-11-05&to=2026-11-20')
            ->assertOk()
            ->assertJsonPath('data.balance_observations.totals.available_funds', '800')
            ->assertJsonPath('data.cash.available_funds', null);
    }

    public function test_a_new_pending_observation_and_expiry_never_restore_an_older_approved_balance(): void
    {
        $c = $this->treasuryContext();
        $this->balanceObservation($c);
        $new = $this->balanceObservation(
            $c,
            ['sequence' => 1, 'reference' => 'TEST-NEW', 'available_funds' => '700'],
            approve: false,
        );
        $this->assertNull($this->report($c)['totals']['available_funds']);
        $this->operationUser('accountant');
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$new['id'].'/approve', [
            'version' => 1,
            'sha256' => $new['sha256'],
            'note' => 'Test',
        ])->assertOk();
        $this->travelTo(CarbonImmutable::parse('2026-11-05T12:00:00Z'));
        $this->assertSame('expired', $this->report($c)['accounts'][0]['state']);
        $this->assertNull($this->report($c)['totals']['available_funds']);
    }

    public function test_provider_funds_and_cash_are_separate_and_different_instants_are_never_added(): void
    {
        $c = $this->treasuryContext();
        $this->balanceObservation($c);
        $this->operationUser();
        $provider = $this->op('POST', 'treasury/accounts', [
            'code' => 'TEST-PROVIDER',
            'name' => 'Fictional provider',
            'kind' => 'monetbil',
        ])
            ->assertCreated()
            ->json('data');
        $b = $this->op('POST', $c['treasury_root'].'/bindings', [
            'treasury_account_id' => $provider['id'],
            'account_id' => $c['accounts']['22']['id'],
            'inflow_side' => 'debit',
            'version' => 0,
            'note' => 'Test',
        ])
            ->assertCreated()
            ->json('data');
        foreach ([['reported_balance' => '-1'], ['withdrawable_funds' => '5001'], ['available_funds' => '0']] as $bad) {
            $this->op(
                'POST',
                $c['treasury_root'].'/balance-observations',
                $this->balanceObservationData($c, [
                    'binding_id' => $b['id'],
                    'reported_balance' => '5000',
                    'available_funds' => null,
                    'withdrawable_funds' => '3000',
                    ...$bad,
                ]),
            )->assertUnprocessable();
        }
        $this->balanceObservation($c, [
            'binding_id' => $b['id'],
            'reported_balance' => '5000',
            'available_funds' => null,
            'withdrawable_funds' => '3000',
        ]);
        $r = $this->report($c);
        $this->assertSame(
            ['available_funds' => '800', 'provider_held_funds' => '5000', 'withdrawable_funds' => '3000'],
            $r['totals'],
        );
        $this->balanceObservation($c, [
            'sequence' => 1,
            'observed_at' => '2026-11-05T10:01:00Z',
            'available_funds' => '750',
        ]);
        $r = $this->report($c);
        $this->assertTrue($r['all_accounts_reviewed']);
        $this->assertFalse($r['same_instant']);
        $this->assertNull($r['as_of']);
        $this->assertNull($r['totals']['available_funds']);
    }

    public function test_live_review_requires_a_separate_author_and_unbound_accounts_remain_visible_as_missing(): void
    {
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Fictional balance operator',
                'niu' => 'TEST',
                'rccm' => 'TEST',
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
        $actor = $this->operationUser();
        $o = $this->balanceObservation($c, approve: false);
        $actor->forceFill(['roles' => ['owner', 'accountant']])->save();
        $body = ['version' => 1, 'sha256' => $o['sha256'], 'note' => 'Test'];
        $this->op(
            'POST',
            $c['treasury_root'].'/balance-observations/'.$o['id'].'/approve',
            $body,
        )->assertUnprocessable();
        $this->operationUser('accountant');
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$o['id'].'/approve', $body)->assertOk();
        $this->account();
        $r = $this->report($c);
        $this->assertCount(2, $r['accounts']);
        $this->assertFalse($r['all_accounts_reviewed']);
        $this->assertNull($r['totals']['available_funds']);
    }

    public function test_changed_configuration_or_quarantined_evidence_removes_the_balance_from_usable_evidence(): void
    {
        $c = $this->treasuryContext();
        $this->balanceObservation($c);
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'pending']);
        $this->assertSame('evidence_unavailable', $this->report($c)['accounts'][0]['state']);
        DB::table('private_documents')
            ->where('id', $c['evidence']['id'])
            ->update(['scan_status' => 'clean']);
        $this->operationUser();
        $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c, 1))->assertCreated();
        $this->assertSame('configuration_changed', $this->report($c)['accounts'][0]['state']);
        $this->assertNull($this->report($c)['totals']['available_funds']);
    }

    public function test_invalid_amounts_dates_and_stale_sequences_are_refused_and_retries_are_idempotent(): void
    {
        $c = $this->treasuryContext();
        foreach (
            [
                ['reported_balance' => '1.5'],
                ['reported_balance' => '-0'],
                ['reported_balance' => '1000000000000000001'],
                ['available_funds' => '1001'],
                ['withdrawable_funds' => '1'],
                ['observed_at' => '2026-11-05T11:01:00Z'],
                ['expires_at' => '2026-11-05T10:00:00Z'],
                ['expires_at' => '2026-11-06T10:00:01Z'],
            ] as $changes
        ) {
            $this->op(
                'POST',
                $c['treasury_root'].'/balance-observations',
                $this->balanceObservationData($c, $changes),
            )->assertUnprocessable();
        }
        $key = $this->fixtureId();
        $body = $this->balanceObservationData($c);
        $o = $this->op('POST', $c['treasury_root'].'/balance-observations', $body, $key)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['treasury_root'].'/balance-observations', $body, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $o['id']);
        $this->op('POST', $c['treasury_root'].'/balance-observations', $body)->assertConflict();
        $this->op(
            'POST',
            $c['treasury_root'].'/balance-observations',
            $this->balanceObservationData($c, ['sequence' => 1, 'observed_at' => '2026-11-05T09:59:00Z']),
        )->assertUnprocessable();
        $this->assertDatabaseCount('treasury_balance_observations', 1);
    }

    public function test_void_preserves_snapshots_and_requires_a_new_clean_proof_without_falling_back(): void
    {
        $c = $this->treasuryContext();
        $old = $this->balanceObservation($c);
        $o = $this->balanceObservation($c, ['sequence' => 1]);
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$o['id'].'/void', [
            'version' => 2,
            'sha256' => $o['sha256'],
            'note' => 'Test correction',
            'document_id' => $c['evidence']['id'],
        ])
            ->assertOk()
            ->assertJsonPath('data.snapshot', $o['snapshot'])
            ->assertJsonPath('data.status', 'void');
        $r = $this->report($c);
        $this->assertSame('void', $r['accounts'][0]['state']);
        $this->assertNull($r['totals']['available_funds']);
        $this->op('GET', $c['treasury_root'].'/balance-observations/'.$old['id'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_sql_guards_history_scope_latest_sequence_and_complete_transitions(): void
    {
        $c = $this->treasuryContext();
        $o = $this->balanceObservation($c, approve: false);
        $writes = [
            fn () => DB::table('treasury_balance_observations')
                ->where('id', $o['id'])
                ->update(['reported_balance' => 900]),
            fn () => DB::table('treasury_balance_observations')->where('id', $o['id'])->delete(),
            fn () => DB::table('treasury_balance_observations')
                ->where('id', $o['id'])
                ->update(['status' => 'approved', 'version' => 2]),
            fn () => DB::table('treasury_balance_observations')
                ->where('id', $o['id'])
                ->update(['status' => null]),
        ];
        foreach ($writes as $write) {
            try {
                DB::transaction($write);
                $this->fail('SQL accepted an invalid observation.');
            } catch (QueryException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $new = $this->balanceObservation($c, ['sequence' => 1], approve: false);
        $row = (array) DB::table('treasury_balance_observations')->where('id', $new['id'])->first();
        foreach (
            [
                ['available_funds' => 1001],
                ['document_sha256' => str_repeat('a', 64)],
                ['expires_at' => $row['observed_at']],
                ['sequence' => 7],
            ] as $invalid
        ) {
            try {
                DB::transaction(
                    fn () => DB::table('treasury_balance_observations')->insert([
                        ...$row,
                        'id' => $this->fixtureId(),
                        'sequence' => 3,
                        ...$invalid,
                    ]),
                );
                $this->fail('SQL accepted invalid balance evidence.');
            } catch (QueryException $e) {
                $this->assertNotSame('', $e->getMessage());
            }
        }
        $this->operationUser('accountant');
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$o['id'].'/approve', [
            'version' => 1,
            'sha256' => $o['sha256'],
            'note' => 'Test',
        ])->assertUnprocessable();
        try {
            DB::transaction(
                fn () => DB::table('treasury_balance_observations')
                    ->where('id', $o['id'])
                    ->update([
                        'status' => 'approved',
                        'version' => 2,
                        'reviewed_by' => auth()->id(),
                        'reviewed_at' => now(),
                        'review_note' => 'Test',
                    ]),
            );
            $this->fail('SQL approved an older observation.');
        } catch (QueryException $e) {
            $this->assertNotSame('', $e->getMessage());
        }
    }

    public function test_reads_stay_in_the_book_and_mutations_require_permissions_mfa_and_recent_confirmation(): void
    {
        $c = $this->treasuryContext();
        $o = $this->balanceObservation($c);
        $other = $this->treasuryContext();
        $this->assertCount(1, $this->report($other)['accounts']);
        $this->assertSame('missing', $this->report($other)['accounts'][0]['state']);
        $this->op('GET', $other['treasury_root'].'/balance-observations/'.$o['id'])->assertNotFound();
        $this->op('GET', $c['treasury_root'].'/balance-observation-summary?as_of=2026-11-04')->assertUnprocessable();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op(
            'POST',
            $c['treasury_root'].'/balance-observations',
            $this->balanceObservationData($c, ['sequence' => 1]),
        )->assertForbidden();
        $this->operationUser('support');
        $this->op('GET', $c['treasury_root'].'/balance-observations')->assertForbidden();
        $this->operationUser('accountant');
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['treasury_root'].'/balance-observation-summary')->assertForbidden();
    }

    public function test_negative_bank_balances_never_create_own_available_funds_and_unknown_is_distinct_from_zero(): void
    {
        $c = $this->treasuryContext();
        $this->assertNull($this->report($c)['totals']['available_funds']);
        $this->balanceObservation($c, ['reported_balance' => '-100', 'available_funds' => '0']);
        $this->assertSame('0', $this->report($c)['totals']['available_funds']);
        $this->balanceObservation($c, ['sequence' => 1, 'expires_at' => '2026-11-05T10:30:00Z'], approve: false);
        $this->operationUser('accountant');
        $o = $this->op('GET', $c['treasury_root'].'/balance-observations')
            ->assertOk()
            ->json('data.0');
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$o['id'].'/approve', [
            'version' => 1,
            'sha256' => $o['sha256'],
            'note' => 'Test',
        ])->assertUnprocessable();
    }

    public function test_summary_uses_all_accounts_with_exact_totals_beyond_native_integers(): void
    {
        $c = $this->treasuryContext();
        $extra = [];
        foreach (range(100, 108) as $code) {
            $extra[] = [
                'code' => (string) $code,
                'parent_code' => null,
                'label' => ['fr' => 'Compte fictif', 'en' => 'Test account'],
                'kind' => 'other',
                'auxiliary_type' => 'none',
                'postable' => true,
                'active' => true,
            ];
        }
        $chart = $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'TEST-LARGE',
            'accounts' => [...$this->accountingChart(), ...$extra],
        ])
            ->assertOk()
            ->json('data');
        $c['accounts'] = collect($chart['accounts'])->keyBy('code')->all();
        $c['binding'] = $this->op('POST', $c['treasury_root'].'/bindings', $this->bindingData($c, 1))
            ->assertCreated()
            ->json('data');
        $this->balanceObservation($c, [
            'reported_balance' => '1000000000000000000',
            'available_funds' => '1000000000000000000',
        ]);
        foreach (range(100, 108) as $code) {
            $this->operationUser();
            $a = $this->account();
            $b = $this->op('POST', $c['treasury_root'].'/bindings', [
                'treasury_account_id' => $a['id'],
                'account_id' => $c['accounts'][$code]['id'],
                'inflow_side' => 'debit',
                'version' => 0,
                'note' => 'Test',
            ])
                ->assertCreated()
                ->json('data');
            $this->balanceObservation($c, [
                'binding_id' => $b['id'],
                'reported_balance' => '1000000000000000000',
                'available_funds' => '1000000000000000000',
            ]);
        }
        $this->assertSame('10000000000000000000', $this->report($c)['totals']['available_funds']);
        $this->assertFalse($this->report($c)['current_availability_verified']);
    }

    public function test_paginated_history_does_not_limit_selection_of_the_latest_observation(): void
    {
        $c = $this->treasuryContext();
        $old = $this->balanceObservation($c);
        foreach (range(1, 24) as $sequence) {
            $latest = $this->balanceObservation(
                $c,
                ['sequence' => $sequence, 'reference' => 'TEST-'.$sequence],
                approve: false,
            );
        }
        $this->op('GET', $c['treasury_root'].'/balance-observations?treasury_account_id='.$c['treasury']['id'])
            ->assertOk()
            ->assertJsonCount(24, 'data')
            ->assertJsonPath('meta.total', 25)
            ->assertJsonPath('data.0.sequence', 25);
        $this->op('GET', $c['treasury_root'].'/balance-observations?page=2')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $old['id']);
        $this->assertNull($this->report($c)['totals']['available_funds']);
        $this->operationUser('accountant');
        $this->op('POST', $c['treasury_root'].'/balance-observations/'.$latest['id'].'/approve', [
            'version' => 1,
            'sha256' => $latest['sha256'],
            'note' => 'Test',
        ])->assertOk();
        $this->assertSame(25, $this->report($c)['accounts'][0]['observation']['sequence']);
        $this->assertSame('800', $this->report($c)['totals']['available_funds']);
    }
}
