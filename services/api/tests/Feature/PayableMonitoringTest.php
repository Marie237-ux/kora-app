<?php

namespace Tests\Feature;

use App\Domain\Notifications\PayableReminderHandler;
use App\Domain\Procurement\Payables;
use App\Domain\Procurement\Records;
use App\Domain\Shared\OutboxProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class PayableMonitoringTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02T08:59:00Z')); // Monday 09:59 Douala
    }

    private function policy(array $overrides = []): array
    {
        return $this->op(
            'PUT',
            'settings/payable-reminders',
            array_replace(
                [
                    'enabled' => true,
                    'due_soon_days' => 7,
                    'local_time' => '10:00',
                    'weekdays' => [1, 2, 3, 4, 5],
                    'repeat_after_days' => 2,
                    'reason' => 'Test-only reminder policy',
                ],
                $overrides,
            ),
        )
            ->assertOk()
            ->json('data');
    }

    private function debt(int $offset = 5, string $basis = 'invoice_date'): array
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', $basis, [
            'schedule' => [['basis_points' => 10000, 'offset_days' => $offset]],
        ]);
        $order = $this->purchase($ctx, $contract);
        $invoice = $this->invoice($ctx, $order, 100);

        return compact('ctx', 'contract', 'order', 'invoice');
    }

    private function scan(): void
    {
        $this->artisan('payables:scan-alerts')->assertSuccessful();
    }

    private function reminder(): object
    {
        return DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->first();
    }

    public function test_no_invented_reminder_policy_and_settings_require_owner_version_and_valid_slot(): void
    {
        $d = $this->debt();
        $this->op('GET', 'settings/payable-reminders')->assertOk()->assertJsonPath('data', null);
        $this->scan();
        $this->assertDatabaseCount('operational_alerts', 0);
        $this->policy();
        $this->scan();
        $this->op('GET', 'payables/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.amount', '100000')
            ->assertJsonPath('data.0.severity', 'due_soon');
        $this->assertSame(0, DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->count());
        $this->op('PUT', 'settings/payable-reminders', [
            'enabled' => true,
            'due_soon_days' => 7,
            'local_time' => '25:00',
            'weekdays' => [1, 1],
            'repeat_after_days' => 0,
            'reason' => 'Invalid',
        ])->assertUnprocessable();
        $this->op('PUT', 'settings/payable-reminders', [
            'enabled' => true,
            'due_soon_days' => 7,
            'local_time' => '10:00',
            'weekdays' => [1],
            'repeat_after_days' => 1,
            'reason' => 'Missing version',
        ])->assertConflict();
        $this->operationUser('procurement');
        $this->op('GET', 'settings/payable-reminders')->assertForbidden();
        $this->op('GET', 'payables/alerts')->assertOk();
        $this->operationUser('stock');
        $this->op('GET', 'payables/alerts')->assertForbidden();
    }

    public function test_configured_local_slot_and_repeat_interval_queue_once_and_worker_never_consumes_unsupported_events(): void
    {
        $this->debt();
        $this->policy();
        $this->travel(1)->minutes();
        $this->scan();
        $this->scan();
        $event = $this->reminder();
        $this->assertNotNull($event);
        $this->assertDatabaseCount('operational_notifications', 0);
        $this->artisan('outbox:process')->assertSuccessful();
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertDatabaseCount('operational_notifications', 1);
        $this->assertDatabaseHas('outbox_events', ['id' => $event->id, 'attempts' => 1]);
        $this->assertSame(
            1,
            DB::table('outbox_events')
                ->where('type', '!=', PayableReminderHandler::TYPE)
                ->whereNull('processed_at')
                ->count(),
        );
        $this->travel(1)->days();
        $this->scan();
        $this->assertSame(1, DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->count());
        $this->travel(1)->days();
        $this->scan();
        $this->assertSame(2, DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->count());
        $this->op('GET', 'system/outbox?state=unsupported')
            ->assertOk()
            ->assertJsonPath('data.0.state', 'unsupported')
            ->assertJsonMissingPath('data.0.payload');
    }

    public function test_settlement_before_worker_delivery_suppresses_a_stale_reminder(): void
    {
        $d = $this->debt();
        $this->policy();
        $this->travel(1)->minutes();
        $this->scan();
        $event = $this->reminder();
        $payment = $this->payment($d['ctx'], $this->account(), '100000');
        $this->verify($d['ctx'], $payment, [
            ['obligation_id' => $d['invoice']['obligation']['id'], 'amount' => '100000'],
        ]);
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertDatabaseCount('operational_notifications', 0);
        $this->assertDatabaseHas('operational_alerts', ['state' => 'resolved', 'amount' => 0]);
        $this->assertDatabaseHas('outbox_attempts', ['event_id' => $event->id, 'result' => 'suppressed']);
        $this->op('GET', 'payables/alerts')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_disabling_reminders_suppresses_queued_delivery_but_keeps_financial_alerts(): void
    {
        $this->debt();
        $this->policy();
        $this->travel(1)->minutes();
        $this->scan();
        $this->policy(['version' => 1, 'enabled' => false]);
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertDatabaseCount('operational_notifications', 0);
        $this->op('GET', 'payables/alerts')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.state', 'active');
        $this->artisan('outbox:process', ['--limit' => 0])->assertExitCode(2);
    }

    public function test_rescheduling_retires_old_alerts_and_old_reminders_without_rewriting_history(): void
    {
        $d = $this->debt();
        $this->policy();
        $this->travel(1)->minutes();
        $this->scan();
        $event = $this->reminder();
        $doc = $this->evidence($d['ctx']['supplier'], 'supplier_contract');
        $this->op('POST', 'payables/obligations/'.$d['invoice']['obligation']['id'].'/reschedule', [
            'version' => 1,
            'document_id' => $doc['id'],
            'reason' => 'Test agreed extension',
            'installments' => [['amount' => '100000', 'due_on' => now('Africa/Douala')->addDays(60)->toDateString()]],
        ])->assertOk();
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertDatabaseCount('operational_notifications', 0);
        $this->assertDatabaseHas('outbox_attempts', ['event_id' => $event->id, 'result' => 'suppressed']);
        $this->assertSame(2, DB::table('payable_schedule')->count());
        $this->op('GET', 'payables/alerts?state=resolved')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.amount', '0');
    }

    public function test_notification_reads_are_per_user_localized_and_protected_by_current_permissions_and_mfa(): void
    {
        $this->debt();
        $this->policy();
        $this->travel(1)->minutes();
        $this->scan();
        $this->artisan('outbox:process')->assertSuccessful();
        $n = $this->op('GET', 'notifications?locale=en')
            ->assertOk()
            ->assertJsonPath('unread_total', 1)
            ->assertJsonPath('data.0.title', 'A supplier payable needs your attention.')
            ->assertJsonMissingPath('data.0.amount')
            ->json('data.0');
        $key = $this->fixtureId();
        $this->op('POST', 'notifications/'.$n['id'].'/read', [], $key)->assertOk();
        $this->op('POST', 'notifications/'.$n['id'].'/read', [], $key)->assertOk();
        $this->op('GET', 'notifications?unread=1')->assertJsonPath('meta.total', 0)->assertJsonPath('unread_total', 0);
        $this->operationUser('procurement');
        $this->op('GET', 'notifications')->assertJsonPath('unread_total', 1)->assertJsonPath('data.0.read_at', null);
        $this->op('POST', 'notifications/'.$this->fixtureId().'/read')->assertNotFound();
        $this->withSession(['mfa_verified' => false]);
        $this->op('GET', 'notifications')->assertForbidden();
        $this->operationUser('client');
        $this->op('GET', 'notifications')->assertForbidden();
        $this->assertDatabaseCount('operational_notification_reads', 1);
    }

    public function test_interruption_after_effect_rolls_back_then_retry_delivers_once(): void
    {
        $this->debt();
        $this->policy();
        $this->travel(1)->minutes();
        $this->scan();
        $event = $this->reminder();
        $interrupt = true;
        DB::listen(function (QueryExecuted $query) use (&$interrupt) {
            if (
                $interrupt &&
                str_contains(strtolower($query->sql), 'insert') &&
                str_contains($query->sql, 'operational_notifications')
            ) {
                $interrupt = false;
                throw new \RuntimeException('Test interruption, not an actual remote failure');
            }
        });
        $this->artisan('outbox:process')->assertFailed();
        $this->assertDatabaseCount('operational_notifications', 0);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'attempts' => 1,
            'processed_at' => null,
            'last_error_code' => 'HANDLER_FAILED',
        ]);
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertDatabaseCount('outbox_attempts', 1);
        $this->travel(61)->seconds();
        $this->artisan('outbox:process')->assertSuccessful();
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertDatabaseCount('operational_notifications', 1);
        $this->assertDatabaseCount('outbox_attempts', 2);
        $this->assertDatabaseHas('outbox_events', ['id' => $event->id, 'attempts' => 2, 'last_error_code' => null]);
    }

    public function test_invalid_payload_is_quarantined_after_five_attempts_with_authorized_audited_retry(): void
    {
        $this->operationUser();
        Records::event(PayableReminderHandler::TYPE, 'TEST INVALID', ['private' => 'Test-only private payload']);
        $event = $this->reminder();
        $processor = app(OutboxProcessor::class);
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('failed', $processor->process($event->id));
            $this->travel(3601)->seconds();
        }
        $this->assertSame('skipped', $processor->process($event->id));
        $this->assertDatabaseHas('outbox_events', [
            'id' => $event->id,
            'attempts' => 5,
            'last_error_code' => 'INVALID_PAYLOAD',
        ]);
        $this->operationUser('procurement');
        $this->op('GET', 'system/outbox')->assertForbidden();
        $this->op('POST', 'system/outbox/'.$event->id.'/retry', ['reason' => 'Unauthorized'])->assertForbidden();
        $this->operationUser();
        $this->withSession(['auth.password_confirmed_at' => now()->subSeconds(901)->timestamp]);
        $this->op('POST', 'system/outbox/'.$event->id.'/retry', ['reason' => 'Stale'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'REAUTHENTICATION_REQUIRED');
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->op('GET', 'system/outbox?state=failed')
            ->assertOk()
            ->assertJsonPath('data.0.last_error_code', 'INVALID_PAYLOAD')
            ->assertJsonMissingPath('data.0.payload');
        $this->op('GET', 'system/outbox/'.$event->id.'/attempts')->assertJsonPath('meta.total', 5);
        $this->op('POST', 'system/outbox/'.$event->id.'/retry', ['reason' => 'Test operator retry'])
            ->assertOk()
            ->assertJsonPath('data.prior_attempts', 5);
        $this->assertSame('failed', $processor->process($event->id));
        $this->assertDatabaseCount('outbox_attempts', 6);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'outbox.retry_requested')->count());
    }

    public function test_forecast_keeps_undated_debt_and_commitments_separate_from_unknown_cash(): void
    {
        $d = $this->debt(0);
        $order2 = $this->purchase($d['ctx'], $this->contract($d['ctx'], 'credit', 'first_receipt'), 20);
        $this->invoice($d['ctx'], $order2, 10); // 10 000 undated recognized, 10 000 still committed
        $payment = $this->payment($d['ctx'], $this->account(), '30000');
        $this->verify($d['ctx'], $payment, [
            ['obligation_id' => $d['invoice']['obligation']['id'], 'amount' => '30000'],
        ]);
        $day = now('Africa/Douala')->toDateString();
        $this->op('GET', 'payables/forecast?from='.$day.'&to='.$day.'&supplier_id='.$d['ctx']['supplier'])
            ->assertOk()
            ->assertJsonPath('data.totals.within_window', '70000')
            ->assertJsonPath('data.totals.undated', '10000')
            ->assertJsonPath('data.totals.recognized_outstanding', '80000')
            ->assertJsonPath('data.totals.unrecognized_commitment', '10000')
            ->assertJsonPath('data.treasury.available_funds', null)
            ->assertJsonPath('data.treasury.free_cash', null)
            ->assertJsonPath('data.days.0.amount', '70000');
        $this->op(
            'GET',
            'payables/forecast?from='.$day.'&to='.now()->addDays(367)->toDateString(),
        )->assertUnprocessable();
    }

    public function test_filters_apply_after_earlier_installments_are_covered_and_contracts_are_isolated(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', 'invoice_date', [
            'schedule' => [['basis_points' => 5000, 'offset_days' => 0], ['basis_points' => 5000, 'offset_days' => 10]],
        ]);
        $order = $this->purchase($ctx, $contract);
        $invoice = $this->invoice($ctx, $order, 100);
        $payment = $this->payment($ctx, $this->account(), '60000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '60000']]);
        $from = now('Africa/Douala')->addDay()->toDateString();
        $to = now('Africa/Douala')->addDays(15)->toDateString();
        $this->op('GET', 'payables/schedule?from='.$from.'&contract_id='.$contract['id'].'&status=pending')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.outstanding', '40000')
            ->assertJsonPath('data.0.settled', '10000');
        $this->op('GET', 'payables/schedule?contract_id='.$contract['id'].'&status=settled')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.outstanding', '0');
        $this->op('GET', 'payables/schedule?contract_id='.$this->fixtureId())->assertJsonPath('meta.total', 0);
        $this->op('GET', 'payables/forecast?from='.$from.'&to='.$to)
            ->assertJsonPath('data.totals.within_window', '40000')
            ->assertJsonPath('data.days.0.amount', '40000');
    }

    public function test_overdue_alerts_work_without_reminders_and_do_not_send_on_unconfigured_weekdays(): void
    {
        $this->debt(0);
        $this->travel(1)->days();
        $this->scan();
        $this->op('GET', 'payables/alerts')
            ->assertJsonPath('data.0.severity', 'past_due')
            ->assertJsonPath('reminder_policy_configured', false);
        $this->assertSame(0, DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->count());
        $this->policy(['weekdays' => [1], 'local_time' => '00:00']);
        $this->scan(); // Tuesday, not Monday
        $this->assertSame(0, DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->count());
    }

    public function test_credit_limit_alert_uses_actual_commitment_and_resolves_when_remainder_falls_below_limit(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $contract = $this->contract($ctx, 'credit', 'invoice_date', ['credit_limit' => '50000']);
        $order = $this->purchase($ctx, $contract, 100, false);
        $this->op('POST', 'purchases/'.$order['id'].'/confirm', [
            'version' => 1,
            'supplier_confirmation_reference' => 'TEST CONFIRMED',
            'credit_override_reason' => 'Test owner credit exception',
        ])->assertOk();
        $invoice = $this->invoice($ctx, $order, 100);
        $this->policy(['local_time' => '00:00']);
        $this->scan();
        $original = $this->op('GET', 'payables/alerts?severity=credit_limit')->json('data.0');
        $this->op('GET', 'payables/alerts?severity=credit_limit')
            ->assertOk()
            ->assertJsonPath('data.0.amount', '50000')
            ->assertJsonPath('data.0.context.commitment', '100000');
        $payment = $this->payment($ctx, $this->account(), '60000');
        $this->verify($ctx, $payment, [['obligation_id' => $invoice['obligation']['id'], 'amount' => '60000']]);
        $this->scan();
        $this->op('GET', 'payables/alerts?severity=credit_limit')->assertJsonPath('meta.total', 0);
        $newOrder = $this->purchase($ctx, $contract, 20, false);
        $this->op('POST', 'purchases/'.$newOrder['id'].'/confirm', [
            'version' => 1,
            'supplier_confirmation_reference' => 'TEST NEW CONFIRMATION',
            'credit_override_reason' => 'Test reactivation of credit warning',
        ])->assertOk();
        $this->scan();
        $this->op('GET', 'payables/alerts?severity=credit_limit')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $original['id'])
            ->assertJsonPath('data.0.cycle', 2)
            ->assertJsonPath('data.0.amount', '10000');
        $this->artisan('outbox:process')->assertSuccessful();
        $this->assertSame(2, DB::table('outbox_events')->where('type', PayableReminderHandler::TYPE)->count());
        $this->assertDatabaseCount('operational_notifications', 1); // old cycle suppressed, current cycle delivered
    }

    public function test_unbilled_overage_cost_follows_acceptance_sequence_even_with_reverse_uuid_order(): void
    {
        $counter = 0xFFFFFFFFFF;
        Str::createUuidsUsing(function () use (&$counter) {
            return Uuid::fromString('ffffffff-ffff-4fff-8fff-'.sprintf('%012x', $counter--));
        });
        try {
            $this->operationUser();
            $this->freezeTime();
            $ctx = $this->fixtureCatalogue();
            $order = $this->purchase($ctx, $this->contract($ctx), 1);
            $location = $this->location();
            $this->receipt($ctx, $order, $location, 2, [
                'quality' => 'quarantine',
                'unit_cost' => 1200,
                'variance_reason' => 'Test first price variance and excess',
            ]);
            $this->receipt($ctx, $order, $location, 1, [
                'quality' => 'quarantine',
                'unit_cost' => 1300,
                'variance_reason' => 'Test second price variance and excess',
            ]);
            $amendment = $this->evidence($ctx['supplier'], 'supplier_contract');
            // Real API records two acceptances in the same second; fixture UUIDs deliberately decrease.
            foreach (DB::table('receipt_lines')->orderBy('unit_cost')->get() as $line) {
                $version = $this->op('GET', 'purchases/'.$order['id'])->json('data.version');
                $this->op('POST', 'receipt-lines/'.$line->id.'/approve-overage', [
                    'version' => $version,
                    'document_id' => $amendment['id'],
                    'reason' => 'Test accepted extra cost',
                ])->assertOk();
            }
            $this->invoice($ctx, $order, 1);
            $doc = $this->evidence($ctx['supplier'], 'supplier_invoice');
            $bill = $this->op('POST', 'purchase-invoices', [
                'purchase_order_id' => $order['id'],
                'number' => 'TEST-FIRST-EXTRA',
                'issued_on' => now('Africa/Douala')->toDateString(),
                'document_id' => $doc['id'],
                'lines' => [
                    [
                        'purchase_line_id' => $order['lines'][0]['id'],
                        'quantity' => 1,
                        'unit_payable' => 1200,
                        'variance_reason' => 'Test first accepted extra price',
                    ],
                ],
                'charges' => [],
            ])
                ->assertCreated()
                ->json('data');
            $this->op('POST', 'purchase-invoices/'.$bill['id'].'/approve', [
                'version' => $bill['version'],
            ])->assertOk();
            $day = now('Africa/Douala')->toDateString();
            $this->op('GET', 'payables/forecast?from='.$day.'&to='.$day)
                ->assertOk()
                ->assertJsonPath('data.totals.unrecognized_commitment', '1300')
                ->assertJsonPath('data.totals.recognized_outstanding', '2200');
            $this->assertSame(3500, app(Payables::class)->commitment($ctx['supplier']));
        } finally {
            Str::createUuidsNormally();
        }
    }
}
