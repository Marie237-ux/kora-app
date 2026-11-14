<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class SupplierSubscriptionsTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-13 10:00:00', 'Africa/Douala'));
    }

    private function actAs(User $user): void
    {
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')
            ->withSession(['mfa_verified' => true, 'auth.password_confirmed_at' => now()->timestamp])
            ->withHeaders(['Origin' => 'http://localhost:3000', 'Accept' => 'application/json']);
    }

    private function portal(string $method, string $path, array $data = [])
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid())->json($method, '/api/v1/'.$path, $data);
    }

    private function member(string $supplier, string $role, string $email): User
    {
        $this->operationUser();
        $url = $this->op('POST', 'suppliers/'.$supplier.'/portal-invitations', [
            'email' => $email,
            'role' => $role,
        ])->json('data.url');
        $settings = $this->op('GET', 'supplier-portal/settings')->json('data');
        if (! $settings['enabled']) {
            $this->op('PUT', 'supplier-portal/settings', [
                'enabled' => true,
                'availability' => 'review',
                'version' => $settings['version'],
            ])->assertOk();
        }
        $user = User::factory()->create(['email' => $email, 'roles' => ['supplier']]);
        $this->actAs($user);
        $this->portal(
            'POST',
            'supplier-invitations/'.substr($url, strlen('/supplier-invitations/')).'/accept',
        )->assertOk();

        return $user;
    }

    private function plan(string $kind, int $price, array $benefits, string $code): array
    {
        $plan = $this->op('POST', 'supplier-plans', [
            'code' => $code,
            'kind' => $kind,
            'name' => ['fr' => 'Formule '.$code, 'en' => 'Plan '.$code],
            'description' => ['fr' => 'Formule de test.', 'en' => 'Test plan.'],
            'price' => $price,
            'period_days' => 30,
            'benefits' => $benefits,
        ])
            ->assertCreated()
            ->json('data');

        return $this->op('POST', 'supplier-plans/'.$plan['id'].'/status', [
            'action' => 'activate',
            'version' => $plan['version'],
        ])
            ->assertOk()
            ->json('data');
    }

    public function test_plans_are_off_by_default_priced_by_the_company_and_paid_before_any_period(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $manager = $this->member($ctx['supplier'], 'manager', 'gerant@example.test');

        // Nothing offered until the owner decides; no plan exists by default.
        $this->operationUser();
        $this->op('GET', 'supplier-plans')->assertOk()->assertJsonCount(0, 'data');
        $standard = $this->plan(
            'subscription',
            15000,
            ['items' => [['fr' => 'Portail et relevés', 'en' => 'Portal and statements']]],
            'STANDARD',
        );
        $this->op('PUT', 'supplier-plans/'.$standard['id'], [
            ...$standard,
            'name' => ['fr' => 'Autre', 'en' => 'Other'],
            'description' => ['fr' => 'x', 'en' => 'x'],
            'price' => 1,
        ])->assertUnprocessable();
        $this->op('POST', 'supplier-plans', [
            'code' => 'VIS-BAD',
            'kind' => 'visibility',
            'name' => ['fr' => 'V', 'en' => 'V'],
            'description' => ['fr' => 'V', 'en' => 'V'],
            'price' => 5000,
            'period_days' => 30,
            'benefits' => [],
        ])->assertUnprocessable();
        $this->operationUser('procurement');
        $this->op('POST', 'supplier-plans', [
            'code' => 'X',
            'kind' => 'subscription',
            'name' => ['fr' => 'X', 'en' => 'X'],
            'description' => ['fr' => 'X', 'en' => 'X'],
            'price' => 1,
            'period_days' => 30,
            'benefits' => [],
        ])->assertForbidden();

        $this->actAs($manager);
        $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/plans')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $standard['id'],
            'accept_terms' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('plan_id', 'error.fields');

        $this->operationUser();
        $this->op('PUT', 'supplier-offers/settings', [
            'subscriptions_enabled' => true,
            'visibility_enabled' => false,
            'slots_per_placement' => 2,
            'renewal_notice_days' => 7,
            'version' => 0,
        ])->assertOk();

        // A staff member of the supplier cannot commit it; the manager consents to the plan as shown.
        $staff = $this->member($ctx['supplier'], 'staff', 'employe@example.test');
        $this->actAs($staff);
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $standard['id'],
            'accept_terms' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('role', 'error.fields');
        $this->actAs($manager);
        $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/plans')
            ->assertOk()
            ->assertJsonPath('data.0.price', '15000');
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $standard['id'],
        ])->assertUnprocessable();
        $sub = $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $standard['id'],
            'accept_terms' => true,
            'renew' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'awaiting_payment')
            ->assertJsonPath('data.consent.method', 'portal')
            ->assertJsonPath('data.plan.price', 15000)
            ->json('data');
        $this->assertStringStartsWith('ABO-', $sub['number']);
        $request = $sub['payment_requests'][0];
        $this->assertSame('15000', $request['amount']);
        $this->assertNull($sub['starts_on']);

        // A plan change later never rewrites what was accepted.
        $this->operationUser();
        $this->op('POST', 'supplier-plans/'.$standard['id'].'/status', [
            'action' => 'retire',
            'version' => $standard['version'],
        ])->assertOk();
        $this->assertSame(
            15000,
            json_decode(DB::table('supplier_subscriptions')->value('plan_snapshot'), true)['price'],
        );

        // Paid only with a real reference and proof, by finance with a recent confirmation; then the period starts.
        $this->op('POST', 'supplier-billing-requests/'.$request['id'].'/paid', [
            'channel' => 'mobile_money',
            'reference' => 'MM-TEST-1',
            'paid_at' => now()->toIso8601String(),
        ])->assertUnprocessable();
        $proof = $this->evidence($ctx['supplier'], 'payment_evidence')['id'];
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('POST', 'supplier-billing-requests/'.$request['id'].'/paid', [
            'channel' => 'mobile_money',
            'reference' => 'MM-TEST-1',
            'paid_at' => now()->toIso8601String(),
            'evidence_id' => $proof,
        ])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->op('POST', 'supplier-billing-requests/'.$request['id'].'/paid', [
            'channel' => 'mobile_money',
            'reference' => 'MM-TEST-1',
            'paid_at' => now()->toIso8601String(),
            'evidence_id' => $proof,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'paid');
        $this->op('POST', 'supplier-billing-requests/'.$request['id'].'/paid', [
            'channel' => 'mobile_money',
            'reference' => 'MM-TEST-2',
            'paid_at' => now()->toIso8601String(),
            'evidence_id' => $proof,
        ])->assertUnprocessable();
        $active = DB::table('supplier_subscriptions')->first();
        $this->assertSame(
            ['active', '2026-11-13', '2026-12-12'],
            [$active->status, $active->starts_on, $active->ends_on],
        );
        try {
            DB::transaction(
                fn () => DB::table('supplier_billing_requests')
                    ->where('id', $request['id'])
                    ->update(['amount' => 1, 'version' => 9]),
            );
            $this->fail('A paid request stays as paid.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('SUPPLIER_BILLING_IMMUTABLE', $e->getMessage());
        }

        // Renewal asked by the supplier: a new identified request before the end, never a debit; it extends
        // the period only once paid. Without payment, the period simply ends.
        $this->travelTo(CarbonImmutable::parse('2026-12-07 06:00:00', 'Africa/Douala'));
        $this->artisan('supplier-subscriptions:daily')->assertSuccessful();
        $this->artisan('supplier-subscriptions:daily')->assertSuccessful();
        $renewal = DB::table('supplier_billing_requests')->where('status', 'issued')->get();
        $this->assertCount(1, $renewal);
        $this->operationUser();
        $this->op('POST', 'supplier-billing-requests/'.$renewal[0]->id.'/paid', [
            'channel' => 'bank_transfer',
            'reference' => 'VIR-TEST-1',
            'paid_at' => now()->toIso8601String(),
            'evidence_id' => $this->evidence($ctx['supplier'], 'payment_evidence')['id'],
        ])->assertOk();
        $this->assertSame(
            ['2026-11-13', '2027-01-11'],
            [
                DB::table('supplier_subscriptions')->value('starts_on'),
                DB::table('supplier_subscriptions')->value('ends_on'),
            ],
        );

        // Cancellation by the manager: the paid period runs to its end, nothing renews, then it closes.
        $this->actAs($manager);
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/subscriptions/'.$sub['id'].'/cancel', [
            'reason' => 'Plus besoin (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.renew', false);
        $this->travelTo(CarbonImmutable::parse('2027-01-12 06:00:00', 'Africa/Douala'));
        $this->artisan('supplier-subscriptions:daily')->assertSuccessful();
        $this->assertSame('cancelled', DB::table('supplier_subscriptions')->value('status'));
        $this->assertSame(0, DB::table('supplier_billing_requests')->where('status', 'issued')->count());
    }

    public function test_visibility_is_consented_limited_labelled_and_never_mixed_into_the_catalogue(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $other = $this->fixtureCatalogue();
        $this->operationUser();
        $this->op('PUT', 'supplier-offers/settings', [
            'subscriptions_enabled' => false,
            'visibility_enabled' => true,
            'slots_per_placement' => 2,
            'renewal_notice_days' => 7,
            'version' => 0,
        ])->assertOk();
        $visibility = $this->plan('visibility', 0, ['placement' => 'category_top', 'max_offers' => 1], 'VIS-CAT');
        $slug = DB::table('categories')->where('id', $ctx['category'])->value('slug');
        $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // Proposed by the team: nothing shows before the supplier's consent, here a signed document.
        $this->op('POST', 'suppliers/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $visibility['id'],
            'offer_ids' => [$other['offer']],
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('offer_ids', 'error.fields');
        $this->op('POST', 'suppliers/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $visibility['id'],
            'offer_ids' => [$ctx['offer'], $this->fixtureId()],
        ])->assertUnprocessable();
        $proposed = $this->op('POST', 'suppliers/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $visibility['id'],
            'offer_ids' => [$ctx['offer']],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'proposed')
            ->json('data');
        $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)->assertJsonCount(
            0,
            'data',
        );
        $signed = $this->evidence($ctx['supplier'], 'supplier_contract');
        $this->op('POST', 'supplier-subscriptions/'.$proposed['id'].'/consent', [
            'document_id' => $signed['id'],
            'signer_name' => 'Test signer',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.consent.method', 'signed_document');

        // Labelled and apart; the organic listing is unchanged and other placements stay empty.
        $sponsored = $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)
            ->assertOk()
            ->json('data');
        $this->assertSame($ctx['offer'], $sponsored[0]['offer']['id']);
        $this->assertTrue($sponsored[0]['sponsored']);
        $this->assertSame('Sponsorisé', $sponsored[0]['label']);
        $this->assertSame(
            'Sponsored',
            $this->withHeader('X-Kora-Locale', 'en')
                ->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)
                ->json('data.0.label'),
        );
        $this->getJson('/api/v1/catalogue/sponsored?placement=search_top')->assertJsonCount(0, 'data');
        $this->assertArrayNotHasKey('sponsored', $this->getJson('/api/v1/catalogue?category='.$slug)->json('data.0'));

        // An offer withdrawn from sale is not promoted; the visibility ends with the period or at once by the team.
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)->assertJsonCount(
            0,
            'data',
        );
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'published']);
        $this->operationUser();
        $this->op('POST', 'supplier-subscriptions/'.$proposed['id'].'/cancel', [
            'reason' => 'Contenu non conforme (test).',
            'immediate' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');
        $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)->assertJsonCount(
            0,
            'data',
        );
        try {
            DB::transaction(
                fn () => DB::table('supplier_subscriptions')
                    ->where('id', $proposed['id'])
                    ->update(['status' => 'active', 'version' => 9]),
            );
            $this->fail('A closed subscription stays closed.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('SUPPLIER_SUBSCRIPTION_IMMUTABLE', $e->getMessage());
        }

        // Turning visibility off hides every placement at once.
        $again = $this->op('POST', 'suppliers/'.$ctx['supplier'].'/subscriptions', [
            'plan_id' => $visibility['id'],
            'offer_ids' => [$ctx['offer']],
        ])->json('data');
        $this->op('POST', 'supplier-subscriptions/'.$again['id'].'/consent', [
            'document_id' => $signed['id'],
            'signer_name' => 'Test signer',
        ])->assertOk();
        $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)->assertJsonCount(
            1,
            'data',
        );
        $this->op('PUT', 'supplier-offers/settings', [
            'subscriptions_enabled' => false,
            'visibility_enabled' => false,
            'slots_per_placement' => 2,
            'renewal_notice_days' => 7,
            'version' => 1,
        ])->assertOk();
        $this->getJson('/api/v1/catalogue/sponsored?placement=category_top&category='.$slug)->assertJsonCount(
            0,
            'data',
        );
    }
}
