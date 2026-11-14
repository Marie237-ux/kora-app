<?php

namespace Tests\Feature;

use App\Domain\Documents\BillingIdentity;
use App\Domain\Documents\DocumentScanner;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AccountingFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class AccountingTest extends TestCase
{
    use AccountingFixtures, DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    private function entry(array $c, array $changes = []): array
    {
        return $this->op('POST', $c['root'].'/entries', [...$this->accountingEntryData($c), ...$changes])
            ->assertCreated()
            ->json('data');
    }

    private function transition(array $c, string $action, int $version = 1, string $kind = 'periods')
    {
        return $this->op(
            'POST',
            $c['root'].
                '/'.
                $kind.
                '/'.
                ($kind === 'years' ? $c['year']['id'] : $c['period']['id']).
                '/'.
                $action,
            ['version' => $version, 'note' => 'Motif de test / test reason'],
        );
    }

    private function guard(callable $write): void
    {
        // Savepoint keeps the PostgreSQL outer RefreshDatabase transaction usable after a rejected SQL write.
        try {
            DB::transaction($write);
            $this->fail('The database accepted a forbidden ledger mutation.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('ACCOUNTING_', $e->getMessage());
        }
    }

    public function test_no_chart_or_calendar_is_seeded_and_live_book_needs_a_real_identity(): void
    {
        $this->operationUser();
        $this->op('GET', 'accounting/books')->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertDatabaseCount('accounting_accounts', 0);
        $this->assertDatabaseCount('accounting_years', 0);
        $this->op('POST', 'accounting/books', [
            'mode' => 'live',
            'label' => ['fr' => 'Test', 'en' => 'Test'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('accounting_books', 0);
    }

    public function test_balancing_idempotency_and_posted_database_immutability(): void
    {
        $c = $this->accountingContext();
        $data = $this->accountingEntryData($c);
        $data['lines'][1]['credit'] = '2499';
        $draft = $this->entry($c, $data);
        $this->assertFalse($draft['balanced']);
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $draft = $this->op('PUT', $c['root'].'/entries/'.$draft['id'], [
            ...$this->accountingEntryData($c),
            'version' => 1,
        ])
            ->assertOk()
            ->json('data');
        $key = $this->fixtureId();
        $posted = $this->op('POST', $c['root'].'/entries/'.$draft['id'].'/post', ['version' => 2], $key)
            ->assertOk()
            ->json('data');
        $this->op('POST', $c['root'].'/entries/'.$draft['id'].'/post', ['version' => 2], $key)
            ->assertOk()
            ->assertJsonPath('data.number', $posted['number']);
        $this->assertSame('DEMO-TEST-20260101-00000001', $posted['number']);
        $this->assertSame(3, $posted['version']);
        $this->op('PUT', $c['root'].'/entries/'.$draft['id'], [
            ...$this->accountingEntryData($c),
            'version' => 3,
        ])->assertUnprocessable();
        $this->op('POST', $c['root'].'/entries/'.$draft['id'].'/cancel', [
            'version' => 3,
            'note' => 'Test',
        ])->assertUnprocessable();
        $id = $draft['id'];
        $this->guard(
            fn () => DB::table('accounting_entries')
                ->where('id', $id)
                ->update(['reference' => 'tampered']),
        );
        $this->guard(fn () => DB::table('accounting_entries')->where('id', $id)->delete());
        $this->guard(
            fn () => DB::table('accounting_entry_lines')
                ->where('entry_id', $id)
                ->update(['debit' => 1]),
        );
        $this->guard(fn () => DB::table('accounting_entry_lines')->where('entry_id', $id)->delete());
        $line = (array) DB::table('accounting_entry_lines')->where('entry_id', $id)->first();
        unset($line['id']);
        $line['position'] = 99;
        $this->guard(fn () => DB::table('accounting_entry_lines')->insert($line));
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $id,
            'sha256' => $posted['sha256'],
            'reference' => 'TEST',
        ]);
        $this->assertDatabaseCount('accounting_entry_revisions', 3);
    }

    public function test_lines_amounts_auxiliaries_and_cross_book_accounts_are_checked(): void
    {
        $c = $this->accountingContext();
        $d = $this->accountingEntryData($c);
        $d['lines'][0]['credit'] = '1';
        $this->op('POST', $c['root'].'/entries', $d)->assertUnprocessable();
        $d['lines'][0]['credit'] = '0';
        $d['lines'][0]['debit'] = '1.5';
        $this->op('POST', $c['root'].'/entries', $d)->assertUnprocessable();
        $d = $this->accountingEntryData($c);
        $d['lines'][0]['account_id'] = $c['accounts']['33']['id'];
        $this->op('POST', $c['root'].'/entries', $d)->assertUnprocessable();
        $d['lines'][0]['party'] = ['type' => 'customer', 'id' => 'user:'.$c['actor']->id];
        $this->entry($c, $d);
        $other = $this->accountingContext();
        $d['lines'][0]['account_id'] = $other['accounts']['33']['id'];
        $this->op('POST', $c['root'].'/entries', $d)->assertNotFound();
        $this->op(
            'GET',
            $other['root'].
                '/entries/'.
                DB::table('accounting_entries')->where('book_id', $c['book']['id'])->value('id'),
        )->assertNotFound();
    }

    public function test_chart_version_changes_require_draft_refresh_and_keep_old_snapshots(): void
    {
        $c = $this->accountingContext();
        $first = $this->accountingPost($c, $this->entry($c))->assertOk()->json('data');
        $draft = $this->entry($c);
        $accounts = $this->accountingChart();
        $accounts[0]['label']['fr'] = 'Libellé révisé de test';
        $chart = $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'TEST-V2',
            'accounts' => $accounts,
        ])
            ->assertOk()
            ->json('data');
        $this->assertSame($c['accounts']['11']['id'], $chart['accounts'][0]['id']);
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $draft = $this->op('PUT', $c['root'].'/entries/'.$draft['id'], [
            ...$this->accountingEntryData($c),
            'version' => 1,
        ])
            ->assertOk()
            ->json('data');
        $this->accountingPost($c, $draft)->assertOk();
        $this->op('GET', $c['root'].'/entries/'.$first['id'])
            ->assertOk()
            ->assertJsonPath('data.lines.0.account.label.fr', 'Compte fictif 11')
            ->assertJsonPath('data.sha256', $first['sha256']);
        $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'stale',
            'accounts' => $accounts,
        ])->assertConflict();
        $accounts[0]['parent_code'] = '99';
        $this->op('PUT', $c['root'].'/chart', [
            'version' => 2,
            'reference' => 'bad parent',
            'accounts' => $accounts,
        ])->assertUnprocessable();
    }

    public function test_reversal_is_exact_and_unique_without_rewriting_the_original(): void
    {
        $c = $this->accountingContext();
        $original = $this->accountingPost($c, $this->entry($c))->assertOk()->json('data');
        $d = [
            'version' => $original['version'],
            'journal_id' => $c['journal']['id'],
            'entry_date' => '2026-11-05',
            'description' => ['fr' => 'Correction fictive', 'en' => 'Test correction'],
            'note' => 'Motif de correction',
        ];
        $reverse = $this->op('POST', $c['root'].'/entries/'.$original['id'].'/reverse', $d)
            ->assertCreated()
            ->json('data');
        $this->assertSame($original['id'], $reverse['reversal_of']);
        foreach ($original['lines'] as $i => $l) {
            $this->assertSame(
                [$l['debit'], $l['credit'], $l['account']],
                [$reverse['lines'][$i]['credit'], $reverse['lines'][$i]['debit'], $reverse['lines'][$i]['account']],
            );
        }
        $this->op('POST', $c['root'].'/entries/'.$original['id'].'/reverse', $d)->assertUnprocessable();
        $this->assertDatabaseHas('accounting_entries', [
            'id' => $original['id'],
            'sha256' => $original['sha256'],
            'status' => 'posted',
        ]);
        $url =
            $c['root'].
            '/reports/trial-balance?'.
            http_build_query(['year_id' => $c['year']['id'], 'from' => '2026-01-01', 'to' => '2026-12-31']);
        $report = $this->op('GET', $url)->assertOk()->json('data');
        $this->assertSame(['0', '5000', '5000'], [$report['closing_balance'], $report['debit'], $report['credit']]);
        $this->assertSame(['0', '0'], array_column($report['rows'], 'closing_balance'));
    }

    public function test_closing_calendar_blocks_drafts_then_requires_explicit_owner_reopening(): void
    {
        $c = $this->accountingContext();
        $draft = $this->entry($c);
        $this->transition($c, 'close')->assertUnprocessable();
        $this->transition($c, 'close', 1, 'years')->assertUnprocessable();
        $this->op('POST', $c['root'].'/entries/'.$draft['id'].'/cancel', [
            'version' => 1,
            'note' => 'Annulation test',
        ])->assertOk();
        $this->transition($c, 'close')->assertOk()->assertJsonPath('data.status', 'closed');
        $this->op('POST', $c['root'].'/entries', $this->accountingEntryData($c))->assertUnprocessable();
        $this->transition($c, 'close', 1, 'years')->assertOk();
        $this->transition($c, 'reopen', 2)->assertUnprocessable();
        $this->operationUser('accountant');
        $this->transition($c, 'reopen', 2, 'years')->assertForbidden();
        $this->operationUser();
        $this->transition($c, 'reopen', 2, 'years')->assertOk();
        $this->transition($c, 'reopen', 2)->assertOk();
        $this->entry($c);
    }

    public function test_chronology_and_calendar_overlap_are_rejected(): void
    {
        $c = $this->accountingContext();
        $this->accountingPost($c, $this->entry($c))->assertOk();
        $backdated = $this->entry($c, ['entry_date' => '2026-11-03']);
        $this->accountingPost($c, $backdated)->assertUnprocessable();
        $this->op('POST', $c['root'].'/years/'.$c['year']['id'].'/periods', [
            'label' => ['fr' => 'Test', 'en' => 'Test'],
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-07-01',
        ])->assertUnprocessable();
    }

    public function test_closed_original_is_reversed_in_an_open_year_with_its_historical_accounts(): void
    {
        $c = $this->accountingContext();
        $original = $this->accountingPost($c, $this->entry($c))->assertOk()->json('data');
        $this->transition($c, 'close')->assertOk();
        $this->transition($c, 'close', 1, 'years')->assertOk();
        $year = $this->op('POST', $c['root'].'/years', [
            'label' => ['fr' => 'Test 2027', 'en' => 'Test 2027'],
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['root'].'/years/'.$year['id'].'/periods', [
            'label' => ['fr' => 'Test 2027', 'en' => 'Test 2027'],
            'starts_on' => '2027-01-01',
            'ends_on' => '2027-12-31',
        ])->assertCreated();
        $accounts = $this->accountingChart();
        $accounts[0]['active'] = false;
        $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'Test retirement',
            'accounts' => $accounts,
        ])->assertOk();
        $this->op('POST', $c['root'].'/entries/'.$original['id'].'/reverse', [
            'version' => 2,
            'journal_id' => $c['journal']['id'],
            'entry_date' => '2027-01-02',
            'description' => ['fr' => 'Correction historique fictive', 'en' => 'Test historical correction'],
            'note' => 'Test cross-year reversal',
        ])
            ->assertCreated()
            ->assertJsonPath('data.year_id', $year['id'])
            ->assertJsonPath('data.chart_version', 1)
            ->assertJsonPath('data.lines.0.account.active', true);
        $this->assertDatabaseHas('accounting_years', ['id' => $c['year']['id'], 'status' => 'closed']);
        $this->assertDatabaseHas('accounting_entries', ['id' => $original['id'], 'sha256' => $original['sha256']]);
    }

    public function test_reports_have_exact_opening_balances_and_totals_above_single_entry_limit(): void
    {
        $c = $this->accountingContext();
        foreach (['2026-01-02', '2026-11-04'] as $date) {
            $this->accountingPost(
                $c,
                $this->entry($c, $this->accountingEntryData($c, '1000000000000000000', $date)),
            )->assertOk();
        }
        $q = ['year_id' => $c['year']['id'], 'from' => '2026-01-01', 'to' => '2026-12-31'];
        $this->op('GET', $c['root'].'/reports/trial-balance?'.http_build_query($q))
            ->assertOk()
            ->assertJsonPath('data.debit', '2000000000000000000')
            ->assertJsonPath('data.credit', '2000000000000000000');
        $q['from'] = '2026-11-01';
        $q['account_id'] = $c['accounts']['11']['id'];
        $this->op('GET', $c['root'].'/reports/ledger?'.http_build_query($q))
            ->assertOk()
            ->assertJsonPath('data.opening_balance', '1000000000000000000')
            ->assertJsonPath('data.closing_balance', '2000000000000000000')
            ->assertJsonPath('data.rows.0.running_balance', '2000000000000000000')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_management_permissions_mfa_and_recent_confirmation_are_enforced(): void
    {
        $c = $this->accountingContext();
        $draft = $this->entry($c);
        $this->operationUser('manager');
        $this->op('GET', 'accounting/books')->assertForbidden();
        $this->operationUser();
        $this->op('POST', $c['root'].'/chart/review', [
            'version' => 1,
            'sha256' => $c['chart']['sha256'],
            'note' => 'Test',
        ])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->accountingPost($c, $draft)->assertForbidden();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/chart/review', [
            'version' => 1,
            'sha256' => $c['chart']['sha256'],
            'note' => 'Test review',
        ])->assertOk();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', $c['root'])->assertForbidden();
    }

    private function issuedInvoice(): array
    {
        $this->useFakeGateway();
        $ctx = $this->checkout();
        $order = $this->order($ctx['quote'])->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->gateway->paid('TEST-ACCOUNTING', $attempt['id'], (int) $order['total']);
        $this->notify([
            'transaction_id' => 'TEST-ACCOUNTING',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->operationUser();
        $draft = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $document = $this->op('POST', 'customer-documents/'.$draft['id'].'/issue', ['version' => $draft['version']])
            ->assertOk()
            ->json('data');

        return compact('document', 'attempt', 'order');
    }

    private function invoiceMapping(array $c): array
    {
        return $this->op('POST', $c['root'].'/mappings', [
            'source_type' => 'customer_invoice',
            'version' => 0,
            'journal_id' => $c['journal']['id'],
            'enabled' => true,
            'note' => 'Schéma fictif de test',
            'lines' => [
                ['measure' => 'total', 'side' => 'debit', 'account_id' => $c['accounts']['33']['id']],
                ['measure' => 'total', 'side' => 'credit', 'account_id' => $c['accounts']['22']['id']],
            ],
        ])
            ->assertCreated()
            ->json('data');
    }

    public function test_issued_invoice_capture_is_unique_and_prepares_one_reviewable_source_entry(): void
    {
        $facts = $this->issuedInvoice();
        $c = $this->accountingContext();
        $mapping = $this->invoiceMapping($c);
        $d = ['source_type' => 'customer_invoice', 'resource_id' => $facts['document']['id']];
        $source = $this->op('POST', $c['root'].'/sources', $d)
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['root'].'/sources', $d)
            ->assertCreated()
            ->assertJsonPath('data.id', $source['id']);
        $this->transition($c, 'close')->assertUnprocessable();
        $draft = $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare')
            ->assertOk()
            ->json('data');
        $this->assertSame('2500', $draft['debit_total']);
        $this->assertSame($mapping['id'], $draft['mapping_id']);
        $this->assertSame(
            'user:'.DB::table('orders')->where('id', $facts['order']['id'])->value('user_id'),
            $draft['lines'][0]['party']['id'],
        );
        $this->op('PUT', $c['root'].'/entries/'.$draft['id'], [
            ...$this->accountingEntryData($c),
            'version' => 1,
        ])->assertUnprocessable();
        $posted = $this->accountingPost($c, $draft)->assertOk()->json('data');
        $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare')
            ->assertOk()
            ->assertJsonPath('data.id', $posted['id']);
        $this->assertDatabaseCount('accounting_sources', 1);
        $this->assertDatabaseCount('accounting_entries', 1);
        $this->assertDatabaseHas('accounting_sources', ['id' => $source['id'], 'posted_entry_id' => $posted['id']]);
        $q = [
            'year_id' => $c['year']['id'],
            'from' => '2026-01-01',
            'to' => '2026-12-31',
            'account_id' => $c['accounts']['33']['id'],
            'party_id' => $draft['lines'][0]['party']['id'],
        ];
        $this->op('GET', $c['root'].'/reports/ledger?'.http_build_query($q))
            ->assertOk()
            ->assertJsonPath('data.debit', '2500')
            ->assertJsonPath('data.meta.total', 1);
    }

    public function test_source_change_and_disabled_mapping_prevent_posting(): void
    {
        $facts = $this->issuedInvoice();
        $c = $this->accountingContext();
        $mapping = $this->invoiceMapping($c);
        $source = $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'customer_invoice',
            'resource_id' => $facts['document']['id'],
        ])
            ->assertCreated()
            ->json('data');
        $draft = $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare')
            ->assertOk()
            ->json('data');
        $this->op('POST', $c['root'].'/mappings', [
            'source_type' => 'customer_invoice',
            'version' => 1,
            'journal_id' => $mapping['journal_id'],
            'enabled' => false,
            'note' => 'Suspension test',
            'lines' => $mapping['lines'],
        ])->assertCreated();
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare', [
            'entry_version' => 1,
        ])->assertUnprocessable();
        $this->op('POST', $c['root'].'/mappings', [
            'source_type' => 'customer_invoice',
            'version' => 2,
            'journal_id' => $mapping['journal_id'],
            'enabled' => true,
            'note' => 'Reprise test',
            'lines' => $mapping['lines'],
        ])->assertCreated();
        $draft = $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare', ['entry_version' => 1])
            ->assertOk()
            ->json('data');
        DB::table('accounting_sources')
            ->where('id', $source['id'])
            ->update(['sha256' => str_repeat('0', 64)]);
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->assertDatabaseMissing('accounting_entries', ['status' => 'posted']);
    }

    public function test_mapping_review_cannot_survive_a_change_of_chart_version(): void
    {
        $facts = $this->issuedInvoice();
        $c = $this->accountingContext();
        $mapping = $this->invoiceMapping($c);
        $source = $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'customer_invoice',
            'resource_id' => $facts['document']['id'],
        ])
            ->assertCreated()
            ->json('data');
        $draft = $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare')
            ->assertOk()
            ->json('data');
        $this->op('PUT', $c['root'].'/chart', [
            'version' => 1,
            'reference' => 'New test chart',
            'accounts' => $this->accountingChart(),
        ])->assertOk();
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare', [
            'entry_version' => 1,
        ])->assertUnprocessable();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/mappings/'.$mapping['id'].'/review', [
            'sha256' => $mapping['sha256'],
            'note' => 'Stale review',
        ])->assertConflict();
        $this->operationUser();
        $this->op('POST', $c['root'].'/mappings', [
            'source_type' => 'customer_invoice',
            'version' => 1,
            'journal_id' => $mapping['journal_id'],
            'enabled' => true,
            'note' => 'Rebased test mapping',
            'lines' => $mapping['lines'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.chart_version', 2);
        $draft = $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare', ['entry_version' => 1])
            ->assertOk()
            ->json('data');
        $this->accountingPost($c, $draft)->assertOk();
    }

    public function test_short_fiscal_years_starting_in_the_same_calendar_year_have_distinct_numbers(): void
    {
        $c = $this->accountingContext('demo', '2026-06-30');
        $first = $this->accountingPost($c, $this->entry($c, ['entry_date' => '2026-06-01']))->assertOk()->json('data');
        $year = $this->op('POST', $c['root'].'/years', [
            'label' => ['fr' => 'Test juillet', 'en' => 'Test July'],
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
        ])
            ->assertCreated()
            ->json('data');
        $this->op('POST', $c['root'].'/years/'.$year['id'].'/periods', [
            'label' => ['fr' => 'Test', 'en' => 'Test'],
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-12-31',
        ])->assertCreated();
        $second = $this->accountingPost($c, $this->entry($c, ['entry_date' => '2026-07-01']))->assertOk()->json('data');
        $this->assertSame('DEMO-TEST-20260101-00000001', $first['number']);
        $this->assertSame('DEMO-TEST-20260701-00000001', $second['number']);
    }

    public function test_live_posting_requires_accountant_review_and_clean_manual_evidence(): void
    {
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Fictional test operator',
                'niu' => 'TEST-NIU',
                'rccm' => 'TEST-RCCM',
                'address' => 'Test address',
                'city' => 'Test city',
                'tax_regime' => 'exempt',
                'mentions' => ['fr' => 'Test', 'en' => 'Test'],
            ],
            $actor,
        );
        $c = $this->accountingContext('live');
        $draft = $this->entry($c);
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/chart/review', [
            'version' => 1,
            'sha256' => $c['chart']['sha256'],
            'note' => 'Review of fictional test chart',
        ])->assertOk();
        $this->accountingPost($c, $draft)->assertUnprocessable();
        Storage::fake('local');
        $this->app->instance(
            DocumentScanner::class,
            new class implements DocumentScanner
            {
                public function scan(string $path): string
                {
                    return 'clean';
                }
            },
        );
        $document = $this->post('/api/v1/operations/documents', [
            'file' => UploadedFile::fake()->createWithContent('accounting.pdf', "%PDF-1.4\n% Test only\n%%EOF"),
            'purpose' => 'accounting_evidence',
        ])
            ->assertCreated()
            ->json('data');
        $draft = $this->op('PUT', $c['root'].'/entries/'.$draft['id'], [
            ...$this->accountingEntryData($c),
            'document_ids' => [$document['id']],
            'version' => 1,
        ])
            ->assertOk()
            ->json('data');
        $posted = $this->accountingPost($c, $draft)->assertOk()->json('data');
        $this->assertSame('TEST-20260101-00000001', $posted['number']);
        $this->operationUser();
        $this->op('POST', 'accounting/books', [
            'mode' => 'live',
            'label' => ['fr' => 'Second test', 'en' => 'Second test'],
        ])->assertUnprocessable();
    }

    public function test_supplier_receipt_obligation_and_payment_are_separate_verified_facts(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $purchase = $this->purchase($ctx, $this->contract($ctx), 10);
        $receipt = $this->receipt($ctx, $purchase, $this->location(), 10);
        $invoice = $this->invoice($ctx, $purchase, 10);
        $obligation = DB::table('supplier_obligations')->where('id', $invoice['obligation']['id'])->first();
        $account = $this->account();
        $payment = $this->payment($ctx, $account, '3000');
        $c = $this->accountingContext();
        $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'supplier_payment',
            'resource_id' => $payment['id'],
        ])->assertUnprocessable();
        $payment = $this->verify($ctx, $payment, [['obligation_id' => $obligation->id, 'amount' => '3000']]);
        $sources = [];
        foreach (
            [
                'goods_receipt' => $receipt['id'],
                'supplier_obligation' => $obligation->id,
                'supplier_payment' => $payment['id'],
            ] as $type => $id
        ) {
            $sources[$type] = $this->op('POST', $c['root'].'/sources', ['source_type' => $type, 'resource_id' => $id])
                ->assertCreated()
                ->json('data');
        }
        $this->assertSame('10000', $sources['goods_receipt']['snapshot']['measures']['total']);
        $this->assertSame('10000', $sources['supplier_obligation']['snapshot']['measures']['total']);
        $this->assertSame('3000', $sources['supplier_payment']['snapshot']['measures']['total']);
        $mapping = [
            'source_type' => 'supplier_payment',
            'version' => 0,
            'journal_id' => $c['journal']['id'],
            'enabled' => true,
            'note' => 'Explicit fictional test mapping',
            'lines' => [
                ['measure' => 'total', 'side' => 'debit', 'account_id' => $c['accounts']['44']['id']],
                ['measure' => 'total', 'side' => 'credit', 'account_id' => $c['accounts']['11']['id']],
            ],
        ];
        $this->op('POST', $c['root'].'/mappings', $mapping)->assertCreated();
        $draft = $this->op('POST', $c['root'].'/sources/'.$sources['supplier_payment']['id'].'/prepare')
            ->assertOk()
            ->json('data');
        $this->assertSame('supplier:'.$ctx['supplier'], $draft['lines'][0]['party']['id']);
        $this->accountingPost($c, $draft)->assertOk();
        $this->transition($c, 'close')->assertUnprocessable();
        $this->assertDatabaseCount('accounting_entries', 1);
    }

    public function test_testmode_customer_payment_is_rejected_and_real_verified_receipt_is_distinct_from_invoice(): void
    {
        $facts = $this->issuedInvoice();
        $c = $this->accountingContext();
        $d = ['source_type' => 'customer_payment', 'resource_id' => $facts['attempt']['id']];
        DB::table('payment_attempts')
            ->where('id', $facts['attempt']['id'])
            ->update(['testmode' => true]);
        $this->op('POST', $c['root'].'/sources', $d)->assertUnprocessable();
        // Fixture only: no provider request or real-world receipt is made by this test.
        DB::table('payment_attempts')
            ->where('id', $facts['attempt']['id'])
            ->update(['testmode' => false]);
        $this->op('POST', $c['root'].'/sources', $d)
            ->assertCreated()
            ->assertJsonPath('data.snapshot.measures.total', '2500')
            ->assertJsonPath('data.snapshot.metadata.provider_fees', null);
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_credit_note_and_verified_refund_are_captured_as_distinct_sources(): void
    {
        $facts = $this->issuedInvoice();
        $refund = $this->refund($facts['order'], '1000')->assertCreated()->json('data');
        $c = $this->accountingContext();
        $data = ['source_type' => 'customer_refund', 'resource_id' => $refund['id']];
        $this->op('POST', $c['root'].'/sources', $data)->assertUnprocessable();
        $this->transfer($refund, '1000', 'TEST-ACCOUNTING-REFUND')->assertOk();
        $this->op('POST', $c['root'].'/sources', $data)
            ->assertCreated()
            ->assertJsonPath('data.snapshot.measures.total', '1000');
        $draft = $this->op('POST', 'refunds/'.$refund['id'].'/credit-note')
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'customer-documents/'.$draft['id'].'/issue', [
            'version' => $draft['version'],
        ])->assertOk();
        $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'customer_credit_note',
            'resource_id' => $draft['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.snapshot.measures.total', '1000')
            ->assertJsonPath('data.snapshot.measures.vat', '0');
        $this->assertDatabaseCount('accounting_sources', 2);
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_demo_documents_are_excluded_from_the_live_book(): void
    {
        $demo = $this->issuedInvoice();
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Test only',
                'niu' => 'TEST',
                'rccm' => 'TEST',
                'address' => 'Test only',
                'city' => 'Test',
                'tax_regime' => 'vat',
                'vat_rate_basis_points' => 1925,
                'mentions' => ['fr' => 'Test', 'en' => 'Test'],
            ],
            $actor,
        );
        $c = $this->accountingContext('live');
        $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'customer_invoice',
            'resource_id' => $demo['document']['id'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('accounting_sources', 0);
    }

    public function test_live_source_mapping_must_be_reviewed(): void
    {
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Test only',
                'niu' => 'TEST',
                'rccm' => 'TEST',
                'address' => 'Test only',
                'city' => 'Test',
                'tax_regime' => 'vat',
                'vat_rate_basis_points' => 1925,
                'mentions' => ['fr' => 'Test', 'en' => 'Test'],
            ],
            $actor,
        );
        $facts = $this->issuedInvoice();
        $c = $this->accountingContext('live');
        $mapping = $this->invoiceMapping($c);
        $source = $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'customer_invoice',
            'resource_id' => $facts['document']['id'],
        ])
            ->assertCreated()
            ->json('data');
        $this->assertSame(
            ['2500', '2096', '404'],
            [
                $source['snapshot']['measures']['total'],
                $source['snapshot']['measures']['net'],
                $source['snapshot']['measures']['vat'],
            ],
        );
        $draft = $this->op('POST', $c['root'].'/sources/'.$source['id'].'/prepare')
            ->assertOk()
            ->json('data');
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/chart/review', [
            'version' => 1,
            'sha256' => $c['chart']['sha256'],
            'note' => 'Test chart review',
        ])->assertOk();
        $this->accountingPost($c, $draft)->assertUnprocessable();
        $this->op('POST', $c['root'].'/mappings/'.$mapping['id'].'/review', [
            'sha256' => $mapping['sha256'],
            'note' => 'Explicit test mapping review',
        ])->assertOk();
        $this->accountingPost($c, $draft)->assertOk();
    }
}
