<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class LoyaltyTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    /** Explicitly fictitious test rules. No programme is created by application startup. */
    private function rules(array $changes = []): array
    {
        return [
            ...[
                'earn_step_xaf' => '1000',
                'earn_points' => 10,
                'rounding' => 'floor',
                'max_points_per_order' => 100,
                'excluded_category_ids' => [],
                'validation_delay_days' => 0,
                'points_valid_days' => null,
                'budget_points' => 1000,
                'tier_window_days' => 365,
                'include_professional' => false,
                'tiers' => [
                    [
                        'key' => 'test-basic',
                        'label' => ['fr' => 'Base fictive', 'en' => 'Test basic'],
                        'threshold_xaf' => '0',
                    ],
                    [
                        'key' => 'test-plus',
                        'label' => ['fr' => 'Plus fictif', 'en' => 'Test plus'],
                        'threshold_xaf' => '2000',
                    ],
                ],
                'conditions' => [
                    'fr' => 'Simulation de test sans valeur commerciale.',
                    'en' => 'Test simulation with no commercial value.',
                ],
            ],
            ...$changes,
        ];
    }

    private function configure(array $changes = [], int $version = 1, bool $enabled = true)
    {
        return $this->op('PUT', 'loyalty/program', [
            'version' => $version,
            'enabled' => $enabled,
            'rules' => $this->rules($changes),
        ]);
    }

    private function loyaltyOrder(array $rules = [], int $quantity = 1, bool $excludeSoldCategory = false): array
    {
        $this->operationUser();
        $this->configure($rules)->assertOk();
        $c = $this->checkout(false);
        if ($excludeSoldCategory) {
            $this->operationUser();
            $category = DB::table('products')->where('id', $c['n']['second']['product'])->value('category_id');
            $this->configure([...$rules, 'excluded_category_ids' => [$category]], 2)->assertOk();
            $this->client($c['client']);
        }
        $card = $this->api('POST', 'account/loyalty/card')->assertCreated()->json('data');
        $cart = $this->getJson('/api/v1/cart')->json('data');
        if ($quantity !== 1) {
            $line = collect($cart['lines'])->firstWhere('offer_id', $c['n']['second']['offer']);
            $this->api('PUT', 'cart/lines/'.$line['id'], [
                'version' => $cart['version'],
                'quantity' => $quantity,
                'selected' => true,
            ])->assertOk();
            $cart = $this->getJson('/api/v1/cart')->json('data');
        }
        $quote = $this->quote($cart, $this->address('Akwa'))->assertCreated()->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $c['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])
            ->assertOk()
            ->json('data');
        $order = $this->order($quote, (string) ((int) $quote['total'] + 1000 * $quantity))
            ->assertCreated()
            ->json('data');

        return [...$c, 'card' => $card, 'order' => $order];
    }

    private function paid(array $c): void
    {
        $this->client($c['client']);
        $attempt = $this->pay($c['order']);
        $this->gateway->paid('TEST-LOYALTY-PAID', $attempt['id'], (int) $c['order']['total']);
        $this->notify([
            'transaction_id' => 'TEST-LOYALTY-PAID',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->client($c['client']);
    }

    private function delivered(array $c): void
    {
        // Test fixture for the future trusted fulfilment writer. There is no HTTP endpoint to fake delivery.
        DB::table('orders')
            ->where('id', $c['order']['id'])
            ->update(['delivered_at' => now()]);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
    }

    private function card()
    {
        return $this->getJson('/api/v1/account/loyalty')->assertOk();
    }

    private function adjustment(array $c, int $points, ?string $key = null)
    {
        return $this->op(
            'POST',
            'loyalty/accounts/'.$c['card']['id'].'/adjustments',
            [
                'points' => $points,
                'explanation' => ['fr' => 'Correction fictive motivée.', 'en' => 'Reasoned test correction.'],
            ],
            $key,
        );
    }

    public function test_programme_starts_disabled_and_the_card_qr_is_opaque_revocable_and_consult_only(): void
    {
        $client = $this->client();
        $this->card()
            ->assertJsonPath('data.program.enabled', false)
            ->assertJsonPath('data.program.rules', null)
            ->assertJsonPath('data.card', null);
        $card = $this->api('POST', 'account/loyalty/card')->assertCreated()->json('data');
        $this->api('POST', 'account/loyalty/card')->assertCreated()->assertJsonPath('data.id', $card['id']);
        $public = $this->getJson($card['qr_path'])
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.capabilities', ['consult_only']);
        foreach (['balance', 'user_id', 'email', 'qr_hash', 'phone', 'address', $client->email] as $private) {
            $this->assertStringNotContainsString($private, $public->getContent());
        }
        $this->postJson($card['qr_path'], ['points' => 100])->assertStatus(405);
        $new = $this->api('POST', 'account/loyalty/card/rotate-qr', ['version' => $card['version']])
            ->assertOk()
            ->json('data');
        $this->getJson($card['qr_path'])->assertNotFound();
        $this->getJson($new['qr_path'])->assertOk();
        $this->client();
        $this->card()->assertJsonPath('data.card', null);
        $this->getJson('/api/v1/account/loyalty/movements')->assertNotFound();
        $this->app['auth']->guard('web')->logout();
        $this->app['auth']->forgetGuards();
        $this->getJson($new['qr_path'])->assertOk();
        $this->getJson('/api/v1/account/loyalty')->assertUnauthorized();
    }

    public function test_payment_and_recorded_delivery_are_both_required_and_the_reward_is_unique(): void
    {
        $c = $this->loyaltyOrder();
        $this->delivered($c);
        $this->card()->assertJsonPath('data.card.available_points', '0');
        $this->assertDatabaseCount('loyalty_grants', 0);
        DB::table('orders')
            ->where('id', $c['order']['id'])
            ->update(['delivered_at' => null]);
        $this->paid($c);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()->assertJsonPath('data.card.available_points', '0');
        $this->delivered($c);
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()
            ->assertJsonPath('data.card.balance', '10')
            ->assertJsonPath('data.card.progression.eligible_spend', '1000');
        $this->assertDatabaseCount('loyalty_grants', 1);
        $this->getJson('/api/v1/account/loyalty/grants')->assertOk()->assertJsonPath('data.0.eligible_amount', '1000');
        $this->getJson('/api/v1/account/loyalty/movements?locale=en')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_frozen_rules_survive_a_new_revision_and_category_exclusions_are_based_on_the_sale(): void
    {
        $c = $this->loyaltyOrder();
        $this->operationUser();
        $category = DB::table('products')->where('id', $c['n']['second']['product'])->value('category_id');
        $this->configure(['earn_points' => 99, 'excluded_category_ids' => [$category]], 2)->assertOk();
        $this->configure([], 2)->assertConflict();
        $this->paid($c);
        $this->delivered($c);
        $this->card()->assertJsonPath('data.card.balance', '10');
        $this->operationUser();
        $this->op('GET', 'loyalty/program/revisions')->assertOk()->assertJsonCount(2, 'data');
        $this->client($c['client']);
        $this->assertStringNotContainsString('budget_points', $this->card()->getContent());
    }

    public function test_validation_delay_and_expiry_are_idempotent_and_expired_points_are_not_available_before_the_job(): void
    {
        $c = $this->loyaltyOrder(['validation_delay_days' => 1, 'points_valid_days' => 2]);
        $this->paid($c);
        $this->delivered($c);
        $this->card()->assertJsonPath('data.card.pending_points', '10')->assertJsonPath('data.card.balance', '0');
        $this->travel(25)->hours();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()
            ->assertJsonPath('data.card.available_points', '10')
            ->assertJsonPath('data.card.pending_points', '0');
        $this->travel(3)->days();
        $this->card()->assertJsonPath('data.card.available_points', '0')->assertJsonPath('data.card.balance', '0');
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->assertSame(1, DB::table('loyalty_movements')->where('kind', 'expired')->count());
    }

    public function test_insufficient_budget_is_visible_without_unfunded_points_and_can_be_resolved_without_duplicate_gain(): void
    {
        $c = $this->loyaltyOrder(['budget_points' => 0]);
        $this->paid($c);
        $this->delivered($c);
        $this->card()->assertJsonPath('data.card.available_points', '0');
        $this->getJson('/api/v1/account/loyalty/grants')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'funding_required');
        $this->operationUser();
        $this->configure(['budget_points' => 10], 2)->assertOk();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->configure(['budget_points' => 9], 3)->assertUnprocessable();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.available_points', '10');
        $this->assertDatabaseCount('loyalty_grants', 1);
    }

    public function test_corrections_require_permission_recent_password_and_a_reason_and_do_not_change_tier_progression(): void
    {
        $c = $this->loyaltyOrder([], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->card()->assertJsonPath('data.card.progression.tier.key', 'test-plus');
        $this->adjustment($c, -10)->assertForbidden();
        $this->operationUser('support');
        $this->adjustment($c, -10)->assertForbidden();
        $this->operationUser();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->adjustment($c, -10)->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $key = $this->fixtureId();
        $this->adjustment($c, -30, $key)
            ->assertOk()
            ->assertJsonPath('data.balance', '0');
        $this->adjustment($c, -30, $key)
            ->assertOk()
            ->assertJsonPath('data.balance', '0');
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.progression.eligible_spend', '3000')
            ->assertJsonPath('data.card.progression.tier.key', 'test-plus');
    }

    public function test_a_paid_partial_return_reverses_only_its_gain_and_never_blocks_cash_when_points_are_already_gone(): void
    {
        $c = $this->loyaltyOrder([], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->operationUser();
        $this->adjustment($c, -30)->assertOk();
        $refund = $this->refund($c['order'], '1000')->assertCreated()->json('data');
        $payload = [
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => ['fr' => 'Retour fictif d’une unité.', 'en' => 'Test return of one unit.'],
        ];
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', $payload)->assertUnprocessable();
        $this->transfer($refund, '1000', 'TEST-LOYALTY-REFUND')->assertOk()->assertJsonPath('data.status', 'paid');
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', $payload)
            ->assertOk()
            ->assertJsonPath('data.eligible_amount', '1000');
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', $payload)->assertConflict();
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.balance', '-10')
            ->assertJsonPath('data.card.available_points', '0')
            ->assertJsonPath('data.card.progression.eligible_spend', '2000');
        $this->assertDatabaseHas('customer_refunds', ['id' => $refund['id'], 'status' => 'paid', 'amount' => 1000]);
    }

    public function test_new_credits_settle_a_prior_negative_balance_without_recreating_the_debt_at_expiry(): void
    {
        $c = $this->loyaltyOrder(['points_valid_days' => 1], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->operationUser();
        $this->adjustment($c, -40)
            ->assertOk()
            ->assertJsonPath('data.balance', '-10');
        $this->adjustment($c, 20)->assertOk()->assertJsonPath('data.balance', '10');
        $this->travel(2)->days();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.balance', '0');
    }

    public function test_suspension_preserves_history_and_progression_rolls_over_with_time(): void
    {
        $c = $this->loyaltyOrder(['tier_window_days' => 1], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->operationUser();
        $this->op('PUT', 'loyalty/accounts/'.$c['card']['id'].'/status', [
            'version' => 1,
            'status' => 'suspended',
            'reason' => 'Suspension fictive documentée.',
        ])
            ->assertOk()
            ->assertJsonPath('data.balance', '30')
            ->assertJsonPath('data.available_points', '0');
        $this->travel(2)->days();
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.balance', '30')
            ->assertJsonPath('data.card.progression.eligible_spend', '0')
            ->assertJsonPath('data.card.progression.tier.key', 'test-basic');
    }

    public function test_excluded_items_earn_nothing_and_have_no_tier_progression(): void
    {
        $c = $this->loyaltyOrder([], 3, true);
        $this->paid($c);
        $this->delivered($c);
        $this->card()
            ->assertJsonPath('data.card.balance', '0')
            ->assertJsonPath('data.card.progression.eligible_spend', '0');
        $this->getJson('/api/v1/account/loyalty/grants')->assertOk()->assertJsonPath('data.0.eligible_amount', '0');
    }

    public function test_returns_before_delivery_and_during_validation_reduce_the_pending_gain_without_double_attribution(): void
    {
        $c = $this->loyaltyOrder(['validation_delay_days' => 5], 3);
        $this->paid($c);
        $this->operationUser();
        $payload = [
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => ['fr' => 'Attribution de test.', 'en' => 'Test attribution.'],
        ];
        $first = $this->refund($c['order'], '1000')->assertCreated()->json('data');
        $this->transfer($first, '1000', 'TEST-LOYALTY-BEFORE')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$first['id'].'/lines', $payload)->assertOk();
        $this->delivered($c);
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.pending_points', '20');
        $this->operationUser();
        $second = $this->refund($c['order'], '1000')->assertCreated()->json('data');
        $this->transfer($second, '1000', 'TEST-LOYALTY-PENDING')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$second['id'].'/lines', [
            ...$payload,
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 2]],
        ])->assertUnprocessable();
        $this->assertSame(1, DB::table('loyalty_refund_lines')->count());
        $this->op('POST', 'loyalty/refunds/'.$second['id'].'/lines', $payload)->assertOk();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.pending_points', '10');
        $this->travel(6)->days();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()
            ->assertJsonPath('data.card.balance', '10')
            ->assertJsonPath('data.card.pending_points', '0')
            ->assertJsonPath('data.card.progression.eligible_spend', '1000');
    }

    public function test_a_return_after_expiry_does_not_claw_back_unused_points_twice(): void
    {
        $c = $this->loyaltyOrder(['points_valid_days' => 1], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->operationUser();
        $this->adjustment($c, -10)->assertOk();
        $this->travel(2)->days();
        $this->operationUser();
        $payload = [
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => ['fr' => 'Retour de test après expiration.', 'en' => 'Test return after expiry.'],
        ];
        $first = $this->refund($c['order'], '1000')->assertCreated()->json('data');
        $this->transfer($first, '1000', 'TEST-LOYALTY-EXPIRED-1')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$first['id'].'/lines', $payload)->assertOk();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.balance', '0');
        $this->operationUser();
        $second = $this->refund($c['order'], '2000')->assertCreated()->json('data');
        $this->transfer($second, '2000', 'TEST-LOYALTY-EXPIRED-2')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$second['id'].'/lines', [
            ...$payload,
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 3]],
        ])->assertUnprocessable();
        $this->op('POST', 'loyalty/refunds/'.$second['id'].'/lines', [
            ...$payload,
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 2]],
        ])->assertOk();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.balance', '-10');
    }

    public function test_verified_supplier_rupture_refunds_automatically_attribute_the_missing_units(): void
    {
        $c = $this->loyaltyOrder([], 3);
        $this->paid($c);
        $line = $c['order']['lines'][0]['id'];
        $this->operationUser();
        $case = $this->op('POST', 'orders/'.$c['order']['id'].'/lines/'.$line.'/unavailable', [
            'version' => (int) DB::table('order_lines')->where('id', $line)->value('supply_version'),
            'missing_quantity' => 1,
            'message' => ['fr' => 'Indisponibilité de test.', 'en' => 'Test unavailability.'],
            'reference' => 'TEST-LOYALTY-SUPPLY',
        ])
            ->assertOk()
            ->json('data');
        $this->client($c['client']);
        $case = $this->api('POST', 'orders/'.$c['order']['id'].'/supply-cases/'.$case['id'].'/choice', [
            'version' => $case['version'],
            'choice' => 'refund_missing',
            'accepted_refund_amount' => '1000',
        ])
            ->assertOk()
            ->json('data');
        $this->operationUser();
        $refund = ['id' => $case['refund_id']];
        $this->transfer($refund, '1000', 'TEST-LOYALTY-SUPPLY-REFUND')->assertOk();
        $this->assertDatabaseHas('loyalty_refund_lines', [
            'refund_id' => $refund['id'],
            'order_line_id' => $line,
            'quantity' => 1,
        ]);
        $this->delivered($c);
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.available_points', '20')
            ->assertJsonPath('data.card.progression.eligible_spend', '2000');
    }

    public function test_nearest_rounding_and_per_order_cap_are_configured_without_changing_the_code(): void
    {
        $c = $this->loyaltyOrder(
            ['earn_step_xaf' => '2000', 'earn_points' => 2, 'rounding' => 'nearest', 'max_points_per_order' => 3],
            3,
        );
        $this->paid($c);
        $this->delivered($c);
        // 3 000 / 2 000 rounds to two steps, then 4 points are capped at 3.
        $this->card()->assertJsonPath('data.card.available_points', '3');
    }

    public function test_pre_delivery_returns_reduce_the_base_before_the_first_full_step_rounding(): void
    {
        $c = $this->loyaltyOrder(['earn_step_xaf' => '2000'], 3);
        $this->paid($c);
        $this->operationUser();
        $refund = $this->refund($c['order'], '2000')->assertCreated()->json('data');
        $this->transfer($refund, '2000', 'TEST-LOYALTY-NET-BASE')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', [
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 2]],
            'explanation' => ['fr' => 'Retour de test avant remise.', 'en' => 'Test return before handover.'],
        ])->assertOk();
        $this->delivered($c);
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.available_points', '0')
            ->assertJsonPath('data.card.progression.eligible_spend', '1000');
        $this->getJson('/api/v1/account/loyalty/grants')
            ->assertOk()
            ->assertJsonPath('data.0.award_base_amount', '1000')
            ->assertJsonPath('data.0.points', '0');
    }

    public function test_existing_newer_credits_offset_a_return_debt_without_reviving_it_when_they_expire(): void
    {
        $c = $this->loyaltyOrder(['points_valid_days' => 1], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->operationUser();
        $this->adjustment($c, -20)->assertOk();
        $this->travel(2)->days();
        $this->operationUser();
        $this->adjustment($c, 50)->assertOk()->assertJsonPath('data.balance', '50');
        $refund = $this->refund($c['order'], '3000')->assertCreated()->json('data');
        $this->transfer($refund, '3000', 'TEST-LOYALTY-OFFSET')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$refund['id'].'/lines', [
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 3]],
            'explanation' => ['fr' => 'Retour fictif avec compensation.', 'en' => 'Test return with offset.'],
        ])->assertOk();
        $this->client($c['client']);
        $this->card()->assertJsonPath('data.card.balance', '30');
        $this->travel(2)->days();
        $this->artisan('loyalty:reconcile')->assertSuccessful();
        $this->card()->assertJsonPath('data.card.balance', '0');
    }

    public function test_returns_on_both_sides_of_expiry_do_not_revoke_the_same_unused_points_twice(): void
    {
        $c = $this->loyaltyOrder(['points_valid_days' => 1], 3);
        $this->paid($c);
        $this->delivered($c);
        $this->operationUser();
        $first = $this->refund($c['order'], '1000')->assertCreated()->json('data');
        $this->transfer($first, '1000', 'TEST-LOYALTY-EXPIRY-BEFORE')->assertOk();
        $payload = [
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 1]],
            'explanation' => ['fr' => 'Retour de test.', 'en' => 'Test return.'],
        ];
        $this->op('POST', 'loyalty/refunds/'.$first['id'].'/lines', $payload)->assertOk();
        $this->travel(2)->days();
        $this->operationUser();
        $second = $this->refund($c['order'], '2000')->assertCreated()->json('data');
        $this->transfer($second, '2000', 'TEST-LOYALTY-EXPIRY-AFTER')->assertOk();
        $this->op('POST', 'loyalty/refunds/'.$second['id'].'/lines', [
            ...$payload,
            'lines' => [['order_line_id' => $c['order']['lines'][0]['id'], 'quantity' => 2]],
        ])->assertOk();
        $this->client($c['client']);
        $this->card()
            ->assertJsonPath('data.card.balance', '0')
            ->assertJsonPath('data.card.progression.eligible_spend', '0');
    }
}
