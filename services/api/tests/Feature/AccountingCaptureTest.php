<?php

namespace Tests\Feature;

use App\Domain\Accounting\AccountingCapture;
use App\Domain\Accounting\AccountingSources;
use App\Domain\Documents\BillingIdentity;
use App\Domain\Procurement\Records;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\AccountingCaptureFixtures;
use Tests\Support\AccountingFixtures;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class AccountingCaptureTest extends TestCase
{
    use AccountingCaptureFixtures,
        AccountingFixtures,
        DeliveryFixtures,
        OrderFixtures,
        ProcurementFixtures,
        RefreshDatabase;

    private function sync(array $c, int $limit = 100): void
    {
        $this->artisan('accounting:sync-sources', [
            '--book' => $c['book']['id'],
            '--limit' => $limit,
        ])->assertSuccessful();
    }

    private function coverage(array $c): array
    {
        return $this->op('GET', $c['root'].'/coverage?from=2026-01-01&to=2026-12-31')
            ->assertOk()
            ->json('data');
    }

    private function close(array $c)
    {
        return $this->op('POST', $c['root'].'/periods/'.$c['period']['id'].'/close', [
            'version' => 1,
            'note' => 'Test closure',
        ]);
    }

    public function test_capture_starts_disabled_and_configuration_needs_owner_recent_confirmation_and_a_start_date(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $this->op('GET', $c['root'].'/capture-settings')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.from', null);
        $this->sync($c);
        $this->assertDatabaseCount('accounting_sources', 0);
        $this->configureAccountingCapture($c, true, 1, null)->assertUnprocessable();
        $this->operationUser('accountant');
        $this->configureAccountingCapture($c)->assertForbidden();
        $this->operationUser();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->configureAccountingCapture($c)->assertForbidden();
        $this->operationUser();
        $this->configureAccountingCapture($c)->assertOk();
        $this->configureAccountingCapture($c)->assertConflict();
        $this->configureAccountingCapture($c, true, 2, '2026-02-01')->assertUnprocessable();
        $this->artisan('accounting:sync-sources', ['--limit' => 0])->assertExitCode(2);
    }

    public function test_verified_events_are_captured_once_without_acknowledging_other_consumers_or_posting(): void
    {
        $facts = $this->accountingFacts();
        $this->notify($facts['callback'])->assertOk();
        $this->operationUser();
        $this->assertSame(
            1,
            DB::table('outbox_events')
                ->where('deduplication_key', 'CustomerPaymentConfirmed:'.$facts['attempt']['id'])
                ->count(),
        );
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $this->assertSame(2, $this->coverage($c)['unresolved']);
        $this->sync($c);
        $this->sync($c);
        $this->assertDatabaseCount('accounting_sources', 2);
        $this->assertDatabaseCount('accounting_capture_receipts', 2);
        $this->assertDatabaseCount('accounting_capture_attempts', 2);
        $this->assertDatabaseCount('accounting_entries', 0);
        $this->assertSame(0, $this->coverage($c)['unresolved']);
        $this->assertDatabaseHas('accounting_sources', [
            'resource_id' => $facts['document']['id'],
            'created_by' => null,
            'capture_event_id' => $facts['eventId'],
        ]);
        $this->assertDatabaseHas('outbox_events', ['id' => $facts['eventId'], 'processed_at' => null, 'attempts' => 0]);
        $this->close($c)->assertUnprocessable(); // Captured sources still require explicit preparation and posting.
    }

    public function test_historical_missing_events_are_backfilled_with_a_bound_and_coverage_blocks_early_closure(): void
    {
        $this->accountingFacts();
        DB::table('outbox_events')
            ->whereIn('type', ['CustomerInvoiceIssued', 'CustomerPaymentConfirmed'])
            ->delete();
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $this->assertDatabaseCount('accounting_sources', 0);
        $this->assertSame(2, $this->coverage($c)['unresolved']);
        $this->close($c)->assertUnprocessable();
        $this->configureAccountingCapture($c, false, 2, null)->assertOk();
        $this->close($c)->assertUnprocessable(); // Pausing the worker cannot hide prior scope.
        $this->configureAccountingCapture($c, true, 3)->assertOk();
        $this->sync($c, 1);
        $this->assertDatabaseCount('accounting_sources', 1);
        $this->sync($c, 1);
        $this->assertDatabaseCount('accounting_sources', 2);
        $this->sync($c);
        $this->assertDatabaseCount('customer_documents', 1);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertSame(
            2,
            DB::table('outbox_events')
                ->whereIn('type', ['CustomerInvoiceIssued', 'CustomerPaymentConfirmed'])
                ->count(),
        );
    }

    public function test_testmode_receipts_are_excluded_with_a_visible_machine_reason(): void
    {
        $facts = $this->accountingFacts();
        DB::table('payment_attempts')
            ->where('id', $facts['attempt']['id'])
            ->update(['testmode' => true]);
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $this->sync($c);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'resource_id' => $facts['attempt']['id'],
            'status' => 'excluded',
            'reason' => 'TESTMODE_SOURCE',
        ]);
        $this->assertDatabaseCount('accounting_sources', 1);
        $this->assertSame(0, $this->coverage($c)['unresolved']);
        $this->op('GET', $c['root'].'/capture-events?status=excluded')
            ->assertOk()
            ->assertJsonPath('data.0.reason', 'TESTMODE_SOURCE')
            ->assertJsonMissingPath('data.0.payload');
    }

    public function test_live_book_excludes_demo_documents_and_retains_auditable_receipts(): void
    {
        $facts = $this->accountingFacts();
        $actor = $this->operationUser();
        app(BillingIdentity::class)->save(
            [
                'legal_name' => 'Test only',
                'niu' => 'TEST',
                'rccm' => 'TEST',
                'address' => 'Test',
                'city' => 'Test',
                'tax_regime' => 'vat',
                'vat_rate_basis_points' => 1925,
                'mentions' => ['fr' => 'Test', 'en' => 'Test'],
            ],
            $actor,
        );
        $c = $this->accountingContext('live');
        $this->configureAccountingCapture($c)->assertOk();
        $this->sync($c);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'resource_id' => $facts['document']['id'],
            'status' => 'excluded',
            'reason' => 'DEMO_SOURCE',
        ]);
        $this->assertDatabaseCount('accounting_sources', 1);
        $this->assertDatabaseCount('accounting_entries', 0);
    }

    public function test_missing_resource_is_reviewed_and_manual_retry_is_authorized_audited_and_scoped(): void
    {
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $missing = $this->fixtureId();
        Records::event('CustomerInvoiceIssued', $missing, ['id' => $missing]);
        $this->artisan('accounting:sync-sources', ['--book' => $c['book']['id']])->assertFailed();
        $r = DB::table('accounting_capture_receipts')->first();
        $this->assertSame(['review_required', 'SOURCE_MISSING'], [$r->status, $r->reason]);
        $this->assertSame(1, $this->coverage($c)['capture_issues']);
        $this->close($c)->assertUnprocessable();
        $this->operationUser('accountant');
        $this->op('POST', $c['root'].'/capture-events/'.$r->id.'/retry', [
            'note' => 'Test retry',
        ])->assertForbidden();
        $other = $this->accountingContext();
        $this->op('POST', $other['root'].'/capture-events/'.$r->id.'/retry', [
            'note' => 'Test retry',
        ])->assertNotFound();
        $this->op('POST', $c['root'].'/capture-events/'.$r->id.'/retry', [
            'note' => 'Explicit test retry',
        ])->assertOk();
        $this->assertDatabaseHas('audit_events', ['resource_id' => $r->id, 'action' => 'accounting.capture_retry']);
        $this->assertSame('review_required', app(AccountingCapture::class)->process($c['book']['id'], $r->event_id));
        $this->op('GET', $c['root'].'/capture-events/'.$r->id.'/attempts')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.attempt', 2);
    }

    public function test_failed_capture_rolls_back_its_side_effects_and_retries_are_bounded(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $real = app(AccountingSources::class);
        $this->app->instance(
            AccountingSources::class,
            new class($real)
            {
                public function __construct(private AccountingSources $real) {}

                public function capture(...$args): array
                {
                    $this->real->capture(...$args);
                    throw new \RuntimeException('test-only sensitive diagnostic');
                }
            },
        );
        $processor = app(AccountingCapture::class);
        for ($i = 1; $i <= 5; $i++) {
            $this->assertSame(
                $i === 5 ? 'failed' : 'retry_pending',
                $processor->process($c['book']['id'], $facts['eventId']),
            );
            $this->assertDatabaseCount('accounting_sources', 0);
            $this->assertSame('skipped', $processor->process($c['book']['id'], $facts['eventId']));
            $this->travel(17)->minutes();
        }
        $r = DB::table('accounting_capture_receipts')->first();
        $this->assertSame(5, (int) $r->attempts);
        $this->app->instance(AccountingSources::class, $real);
        $this->operationUser();
        $this->op('POST', $c['root'].'/capture-events/'.$r->id.'/retry', [
            'note' => 'Test resolved local fault',
        ])->assertOk();
        $this->assertSame('captured', $processor->process($c['book']['id'], $facts['eventId']));
        $this->assertDatabaseCount('accounting_sources', 1);
        $this->assertDatabaseCount('accounting_capture_attempts', 6);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'id' => $r->id,
            'attempts' => 6,
            'status' => 'captured',
            'reason' => null,
        ]);
    }

    public function test_refund_and_credit_note_events_follow_verification_and_emission(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $this->sync($c);
        $refund = $this->refund($facts['order'], '1000')->assertCreated()->json('data');
        $this->assertSame(0, DB::table('outbox_events')->where('type', 'CustomerRefundPaid')->count());
        $this->transfer($refund, '1000', 'TEST-CAPTURE-REFUND')->assertOk();
        $credit = $this->op('POST', 'refunds/'.$refund['id'].'/credit-note')
            ->assertCreated()
            ->json('data');
        $this->assertSame(0, DB::table('outbox_events')->where('type', 'CustomerCreditNoteIssued')->count());
        $this->op('POST', 'customer-documents/'.$credit['id'].'/issue', [
            'version' => $credit['version'],
        ])->assertOk();
        $this->sync($c);
        $this->assertDatabaseCount('accounting_sources', 4);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'source_type' => 'customer_refund',
            'resource_id' => $refund['id'],
            'status' => 'captured',
        ]);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'source_type' => 'customer_credit_note',
            'resource_id' => $credit['id'],
            'status' => 'captured',
        ]);
        $this->assertDatabaseCount('accounting_entries', 0);
        $this->assertSame(0, $this->coverage($c)['unresolved']);
    }

    public function test_supplier_facts_are_captured_without_inventing_an_expense_or_a_cash_receipt(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $purchase = $this->purchase($ctx, $this->contract($ctx), 10);
        $receipt = $this->receipt($ctx, $purchase, $this->location(), 10);
        $invoice = $this->invoice($ctx, $purchase, 10);
        $payment = $this->payment($ctx, $this->account(), '3000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '3000']]);
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $this->assertSame(3, $this->coverage($c)['unresolved']);
        $this->sync($c);
        $this->assertDatabaseCount('accounting_sources', 3);
        $this->assertDatabaseCount('accounting_entries', 0);
        $amounts = DB::table('accounting_sources')
            ->get()
            ->mapWithKeys(fn ($s) => [$s->source_type => json_decode($s->snapshot, true)['measures']['total']])
            ->all();
        $this->assertEquals(
            ['goods_receipt' => '10000', 'supplier_obligation' => '10000', 'supplier_payment' => '3000'],
            $amounts,
        );
    }

    public function test_consignment_and_quarantined_receipts_are_explicit_exclusions(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $consigned = $this->receipt(
            $ctx,
            $this->purchase($ctx, $this->contract($ctx, 'consignment'), 5),
            $this->location(),
            5,
        );
        $other = $this->fixtureCatalogue();
        $quarantined = $this->receipt(
            $other,
            $this->purchase($other, $this->contract($other), 5),
            $this->location(),
            5,
            ['quality' => 'quarantine'],
        );
        $c = $this->accountingContext();
        $this->configureAccountingCapture($c)->assertOk();
        $this->sync($c);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'resource_id' => $consigned['id'],
            'status' => 'excluded',
            'reason' => 'CONSIGNMENT_NOT_OWNED',
        ]);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'resource_id' => $quarantined['id'],
            'status' => 'excluded',
            'reason' => 'NO_ACCEPTED_VALUE',
        ]);
        $this->assertDatabaseCount('accounting_sources', 0);
        $this->assertSame(0, $this->coverage($c)['unresolved']);
        $this->close($c)->assertOk();
    }

    public function test_malformed_events_and_changed_sources_remain_visible_without_overwriting_snapshots(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $source = $this->op('POST', $c['root'].'/sources', [
            'source_type' => 'customer_invoice',
            'resource_id' => $facts['document']['id'],
        ])
            ->assertCreated()
            ->json('data');
        DB::table('accounting_sources')
            ->where('id', $source['id'])
            ->update(['sha256' => str_repeat('0', 64)]);
        Records::event('CustomerInvoiceIssued', $this->fixtureId(), ['id' => 'invalid-id']);
        $this->configureAccountingCapture($c)->assertOk();
        $this->artisan('accounting:sync-sources', ['--book' => $c['book']['id']])->assertFailed();
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'source_id' => $source['id'],
            'status' => 'review_required',
            'reason' => 'SOURCE_CHANGED',
        ]);
        $this->assertDatabaseHas('accounting_capture_receipts', [
            'resource_id' => null,
            'status' => 'review_required',
            'reason' => 'INVALID_EVENT',
        ]);
        $this->assertDatabaseHas('accounting_sources', ['id' => $source['id'], 'sha256' => str_repeat('0', 64)]);
        $this->assertSame(
            $source['snapshot'],
            json_decode(DB::table('accounting_sources')->where('id', $source['id'])->value('snapshot'), true),
        );
        $coverage = $this->coverage($c);
        $this->assertSame(
            [0, 2, 1],
            [$coverage['unresolved'], $coverage['capture_issues'], $coverage['unidentified_events']],
        );
        $this->close($c)->assertUnprocessable();
    }

    public function test_moving_start_earlier_recovers_prior_exclusions_and_books_keep_independent_receipts(): void
    {
        $facts = $this->accountingFacts();
        $c = $this->accountingContext();
        $date = now('Africa/Douala')->addDay()->toDateString();
        $this->configureAccountingCapture($c, true, 1, $date)->assertOk();
        $this->sync($c);
        $this->assertDatabaseCount('accounting_sources', 0);
        $this->assertSame(
            2,
            DB::table('accounting_capture_receipts')->where('reason', 'BEFORE_CAPTURE_START')->count(),
        );
        $this->configureAccountingCapture($c, true, 2)->assertOk();
        $this->sync($c);
        $this->assertDatabaseCount('accounting_sources', 2);
        $other = $this->accountingContext();
        $this->configureAccountingCapture($other)->assertOk();
        $this->sync($other);
        $this->assertDatabaseCount('accounting_sources', 4);
        $this->assertDatabaseCount('accounting_capture_receipts', 4);
        $this->assertDatabaseCount('accounting_capture_attempts', 6);
    }
}
