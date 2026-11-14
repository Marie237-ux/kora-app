<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\Support\ProfessionalFixtures;
use Tests\TestCase;

final class ProfessionalQuotesTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, ProcurementFixtures, ProfessionalFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
    }

    private function member(array $c, User $member, string $role = 'viewer', int $version = 1)
    {
        return $this->api('POST', 'professional/profiles/'.$c['profile']['id'].'/members', [
            'version' => $version,
            'email' => $member->email,
            'role' => $role,
        ]);
    }

    public function test_company_contacts_are_scoped_and_cannot_grant_global_staff_rights(): void
    {
        $c = $this->professionalRequest();
        $viewer = User::factory()->create(['roles' => ['client']]);
        $this->member($c, $viewer)->assertOk()->assertJsonPath('data.version', 2);
        $this->assertSame([], $viewer->permissions());
        $unverified = User::factory()
            ->unverified()
            ->create(['roles' => ['client']]);
        $this->member($c, $unverified, 'buyer', 2)->assertUnprocessable();
        $this->member($c, $c['client'], 'buyer', 2)->assertUnprocessable();
        $this->client($viewer);
        $this->getJson('/api/v1/professional/profiles/'.$c['profile']['id'])
            ->assertOk()
            ->assertJsonPath('data.identity_verified', false);
        $this->getJson('/api/v1/'.$this->professionalPath($c))->assertOk();
        $this->api('POST', $this->professionalPath($c).'/accept', [
            'version' => 1,
            'revision_id' => $this->fixtureId(),
            'accepted_total' => '1',
            'supplier_unreserved_acknowledged' => true,
        ])->assertForbidden();
        $this->client();
        $this->getJson('/api/v1/professional/profiles/'.$c['profile']['id'])->assertNotFound();
        $this->getJson('/api/v1/'.$this->professionalPath($c))->assertNotFound();
        $this->client($c['client']);
        $this->api('DELETE', 'professional/profiles/'.$c['profile']['id'].'/members/'.$viewer->id, [
            'version' => 2,
        ])->assertOk();
        $this->client($viewer);
        $this->getJson('/api/v1/'.$this->professionalPath($c))->assertNotFound();
    }

    public function test_request_and_issued_revision_are_independent_of_later_cart_and_address_edits(): void
    {
        $c = $this->professionalRequest();
        $this->api('PUT', 'cart/lines/'.$c['cart']['lines'][0]['id'], [
            'version' => $c['cart']['version'],
            'quantity' => 3,
            'selected' => true,
        ])->assertOk();
        $this->api('PUT', 'professional/profiles/'.$c['profile']['id'].'/addresses/'.$c['address']['id'], [
            ...$c['address']['address'],
            'version' => 1,
            'landmark' => 'Nouveau repère fictif',
        ])->assertOk();
        $this->operationUser();
        $quote = $this->issueProfessional($c)->assertOk()->json('data');
        $revision = $this->latestProfessional($quote);
        $this->assertSame(2, $revision['snapshot']['lines'][0]['quantity']);
        $this->assertFalse($revision['snapshot']['stock_reserved']);
        $this->assertTrue($revision['snapshot']['supplier_confirmation_required']);
        $this->assertTrue($revision['snapshot']['demo']);
        $this->assertStringStartsWith('DEMO-DEV-', $quote['number']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('orders', 0);
        $this->client($c['client']);
        $this->getJson('/api/v1/cart')->assertJsonPath('data.lines.0.quantity', 3);
        $this->getJson('/api/v1/account/addresses')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_revisions_are_immutable_and_acceptance_requires_the_current_revision_exact_total_and_acknowledgement(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        $first = $this->issueProfessional($c)->assertOk()->json('data');
        $old = $this->latestProfessional($first);
        $second = $this->issueProfessional([...$c, 'quote' => $first], '900', [
            'loss_reason' => 'Owner-approved test budget',
        ])
            ->assertOk()
            ->json('data');
        $this->assertCount(2, $second['revisions']);
        $this->assertSame($old['snapshot_sha256'], $second['revisions'][0]['snapshot_sha256']);
        $this->client($c['client']);
        $this->acceptProfessional($c, $first)->assertConflict();
        $this->api('POST', $this->professionalPath($c).'/accept', [
            'version' => $second['version'],
            'revision_id' => $old['id'],
            'accepted_total' => '4300',
            'supplier_unreserved_acknowledged' => true,
        ])->assertUnprocessable();
        $this->api('POST', $this->professionalPath($c).'/accept', [
            'version' => $second['version'],
            'revision_id' => $second['current_revision_id'],
            'accepted_total' => '4300',
        ])->assertUnprocessable();
        $accepted = $this->acceptProfessional($c, $second)
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->json('data');
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertStringNotContainsString('purchase_unit', json_encode($accepted));
        $this->assertStringNotContainsString('internal_snapshot', json_encode($accepted));
        $this->issueProfessional([...$c, 'quote' => $accepted])->assertForbidden();
        $this->operationUser();
        $this->issueProfessional([...$c, 'quote' => $accepted])->assertUnprocessable();
    }

    public function test_a_quote_converts_once_at_the_agreed_price_and_uses_the_regular_payment_and_invoice_workflow(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        $issued = $this->issueProfessional($c, '900', ['loss_reason' => 'Owner-approved test budget'])
            ->assertOk()
            ->json('data');
        $hash = $this->latestProfessional($issued)['snapshot_sha256'];
        $this->client($c['client']);
        $accepted = $this->acceptProfessional($c, $issued)->assertOk()->json('data');
        $this->api('PUT', 'cart/lines/'.$c['cart']['lines'][0]['id'], [
            'version' => $c['cart']['version'],
            'quantity' => 4,
            'selected' => true,
        ])->assertOk();
        DB::table('offers')
            ->where('id', $c['n']['second']['offer'])
            ->update(['selling_price' => 1800, 'reference_price' => 1800]);
        $order = $this->convertProfessional($c, $accepted)
            ->assertCreated()
            ->assertJsonPath('data.total', '4300')
            ->assertJsonPath('data.lines.0.unit_price', '900')
            ->assertJsonPath('data.lines.0.quantity', 2)
            ->json('data');
        $this->convertProfessional($c, $accepted)->assertCreated()->assertJsonPath('data.id', $order['id']);
        $this->assertDatabaseCount('orders', 1);
        $attempt = $this->pay($order);
        $this->gateway->paid('TEST-PRO-PAID', $attempt['id'], 4300);
        $this->notify([
            'transaction_id' => 'TEST-PRO-PAID',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->client($c['client']);
        $this->getJson('/api/v1/cart')->assertOk()->assertJsonPath('data.lines.0.quantity', 4);
        $this->api('PUT', 'professional/profiles/'.$c['profile']['id'], [
            'version' => 1,
            'legal_name' => 'Nouvelle identité déclarée (test)',
            'billing_address' => 'Autre adresse (test)',
        ])->assertOk();
        $this->operationUser('accountant');
        $invoice = $this->op('POST', 'orders/'.$order['id'].'/invoice')
            ->assertCreated()
            ->json('data');
        $this->op('POST', 'customer-documents/'.$invoice['id'].'/issue', [
            'version' => $invoice['version'],
        ])->assertOk();
        $snapshot = json_decode(DB::table('customer_documents')->where('id', $invoice['id'])->value('snapshot'), true);
        $this->assertSame('Entreprise fictive de test', $snapshot['buyer']['name']);
        $this->assertSame('Adresse de facturation fictive', $snapshot['buyer']['billing_address']);
        $this->assertSame('TEST-NIU-BUYER', $snapshot['buyer']['niu']);
        $this->assertSame(
            $hash,
            DB::table('professional_quote_revisions')
                ->where('id', $issued['current_revision_id'])
                ->value('snapshot_sha256'),
        );
        foreach (['fr', 'en'] as $locale) {
            $this->get('/api/v1/operations/customer-documents/'.$invoice['id'].'/pdf?locale='.$locale)
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_a_later_delivery_tariff_cannot_add_a_supplement_to_an_accepted_quote(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->grid(
            [[$c['n']['north'], 1200], [$c['n']['south'], 2000]],
            [['from_zone_id' => $c['n']['north']['id'], 'to_zone_id' => $c['n']['south']['id'], 'amount' => 700]],
        );
        $this->client($c['client']);
        $accepted = $this->acceptProfessional($c, $issued)->assertOk()->json('data');
        $this->api('POST', $this->professionalPath($c).'/checkout-preview')
            ->assertOk()
            ->assertJsonPath('data.delivery.total', '2500')
            ->assertJsonPath('data.accepted_total', '4500');
        $order = $this->convertProfessional($c, $accepted)
            ->assertCreated()
            ->assertJsonPath('data.delivery_total', '2500')
            ->assertJsonPath('data.total', '4500')
            ->json('data');
        $event = DB::table('professional_quote_events')
            ->where('quote_id', $c['quote']['id'])
            ->where('action', 'converted')
            ->first();
        $this->assertSame('3000', json_decode($event->data, true)['recalculated_delivery_total']);
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'awaiting_payment']);
    }

    public function test_changed_product_terms_block_conversion_without_a_different_variant_or_warranty_being_substituted(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->client($c['client']);
        $accepted = $this->acceptProfessional($c, $issued)->assertOk()->json('data');
        DB::table('offers')
            ->where('id', $c['n']['second']['offer'])
            ->update(['warranty' => json_encode(['fr' => 'Autre', 'en' => 'Other'])]);
        $this->convertProfessional($c, $accepted)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines', 'error.fields');
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseHas('professional_quotes', ['id' => $c['quote']['id'], 'status' => 'accepted']);
    }

    public function test_expiry_is_effective_before_the_scheduler_and_the_job_is_idempotent(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        $issued = $this->issueProfessional($c, '1000', [
            'valid_until' => now()->addHour()->setTimezone('Africa/Douala')->toIso8601String(),
        ])
            ->assertOk()
            ->json('data');
        $this->travel(61)->minutes();
        $this->client($c['client']);
        $this->getJson('/api/v1/'.$this->professionalPath($c))
            ->assertOk()
            ->assertJsonPath('data.status', 'expired');
        $this->acceptProfessional($c, $issued)->assertUnprocessable();
        $this->artisan('professional:expire-quotes')->assertSuccessful();
        $this->artisan('professional:expire-quotes')->assertSuccessful();
        $this->assertSame(1, DB::table('professional_quote_events')->where('action', 'expired')->count());
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_decline_preserves_the_revision_and_a_new_proposal_requires_new_acceptance(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->client($c['client']);
        $declined = $this->api('POST', $this->professionalPath($c).'/decline', [
            'version' => $issued['version'],
            'reason' => 'Budget de test',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'declined')
            ->json('data');
        $this->operationUser();
        $next = $this->issueProfessional([...$c, 'quote' => $declined])
            ->assertOk()
            ->assertJsonCount(2, 'data.revisions')
            ->json('data');
        $this->client($c['client']);
        $this->convertProfessional($c, $next)->assertUnprocessable();
    }

    public function test_reconfirming_password_and_owner_loss_approval_are_required_before_issuing(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser('procurement');
        $this->issueProfessional($c, '900', ['loss_reason' => 'Not an owner'])->assertUnprocessable();
        $this->operationUser();
        $this->withSession(['auth.password_confirmed_at' => now()->subHour()->timestamp]);
        $this->issueProfessional($c)->assertForbidden();
        $this->operationUser();
        $this->issueProfessional($c, '900')->assertUnprocessable();
        $this->issueProfessional($c, '900', ['loss_reason' => 'Owner test campaign'])->assertOk();
    }

    public function test_missing_supplier_agreements_or_unconfirmed_logistics_cannot_produce_a_fictitious_quote(): void
    {
        $c = $this->professionalRequest();
        $this->operationUser();
        DB::table('purchase_agreements')
            ->where('supplier_id', $c['n']['second']['supplier'])
            ->update(['minimum_quantity' => 3]);
        $this->issueProfessional($c)->assertUnprocessable();
        DB::table('purchase_agreements')
            ->where('supplier_id', $c['n']['second']['supplier'])
            ->update(['minimum_quantity' => 1]);
        DB::table('inventory_profiles')
            ->where('variant_id', $c['n']['second']['variant'])
            ->update(['logistics' => json_encode(['mass_grams' => null, 'transport_class' => 'standard'])]);
        $this->issueProfessional($c)->assertUnprocessable()->assertJsonValidationErrors('delivery', 'error.fields');
        $this->assertDatabaseCount('professional_quote_revisions', 0);
    }

    public function test_revoking_a_company_buyer_blocks_conversion_and_shared_history_hides_other_payers_payment_links(): void
    {
        $c = $this->professionalRequest();
        $buyer = User::factory()->create(['roles' => ['client']]);
        $this->member($c, $buyer, 'buyer')->assertOk();
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->client($buyer);
        $accepted = $this->acceptProfessional($c, $issued)->assertOk()->json('data');
        $this->client($c['client']);
        $this->api('DELETE', 'professional/profiles/'.$c['profile']['id'].'/members/'.$buyer->id, [
            'version' => 2,
        ])->assertOk();
        $this->client($buyer);
        $this->convertProfessional($c, $accepted)->assertNotFound();
        $this->client($c['client']);
        $order = $this->convertProfessional($c, $accepted)->assertCreated()->json('data');
        $this->pay($order);
        $viewer = User::factory()->create(['roles' => ['client']]);
        $this->member($c, $viewer, 'viewer', 3)->assertOk();
        $this->client($viewer);
        $this->getJson('/api/v1/professional/profiles/'.$c['profile']['id'].'/orders/'.$order['id'])
            ->assertOk()
            ->assertJsonPath('data.payable', false)
            ->assertJsonPath('data.payment.payment_url', null);
        $this->api('POST', 'orders/'.$order['id'].'/payments')->assertNotFound();
    }

    public function test_revocation_also_blocks_normal_payment_routes_without_losing_a_payment_already_in_flight(): void
    {
        $c = $this->professionalRequest();
        $buyer = User::factory()->create(['roles' => ['client']]);
        $this->member($c, $buyer, 'buyer')->assertOk();
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->client($buyer);
        $accepted = $this->acceptProfessional($c, $issued)->assertOk()->json('data');
        $order = $this->convertProfessional($c, $accepted)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $this->client($c['client']);
        $this->api('DELETE', 'professional/profiles/'.$c['profile']['id'].'/members/'.$buyer->id, [
            'version' => 2,
        ])->assertOk();
        $this->client($buyer);
        $this->getJson('/api/v1/orders/'.$order['id'])->assertNotFound();
        $this->getJson('/api/v1/orders')->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', 'orders/'.$order['id'].'/payments')->assertNotFound();
        $this->gateway->paid('TEST-REVOKED-PAID', $attempt['id'], 4500);
        $this->notify([
            'transaction_id' => 'TEST-REVOKED-PAID',
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $this->assertDatabaseHas('orders', ['id' => $order['id'], 'status' => 'paid']);
    }

    public function test_replaying_a_previous_command_cannot_bypass_revoked_or_downgraded_company_membership(): void
    {
        $c = $this->professionalRequest();
        $buyer = User::factory()->create(['roles' => ['client']]);
        $this->member($c, $buyer, 'buyer')->assertOk();
        $this->operationUser();
        $issued = $this->issueProfessional($c)->assertOk()->json('data');
        $this->client($buyer);
        $key = $this->fixtureId();
        $input = [
            'version' => $issued['version'],
            'revision_id' => $issued['current_revision_id'],
            'accepted_total' => $this->latestProfessional($issued)['snapshot']['totals']['total'],
            'supplier_unreserved_acknowledged' => true,
        ];
        $url = '/api/v1/'.$this->professionalPath($c).'/accept';
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $input)->assertOk();
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $input)->assertOk();
        $this->client($c['client']);
        $this->member($c, $buyer, 'viewer', 2)->assertOk();
        $this->client($buyer);
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $input)->assertForbidden();
        $this->client($c['client']);
        $this->api('DELETE', 'professional/profiles/'.$c['profile']['id'].'/members/'.$buyer->id, [
            'version' => 3,
        ])->assertOk();
        $this->client($buyer);
        $this->withHeader('Idempotency-Key', $key)->postJson($url, $input)->assertNotFound();
        $this->assertSame(1, DB::table('professional_quote_events')->where('action', 'accepted')->count());
    }
}
