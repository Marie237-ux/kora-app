<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DeliveryFixtures;
use Tests\Support\OrderFixtures;
use Tests\Support\PreparedOrderFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class SupplierPortalTest extends TestCase
{
    use DeliveryFixtures, OrderFixtures, PreparedOrderFixtures, ProcurementFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->useFakeGateway();
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

    /** Invites, accepts and opens the portal; returns the supplier member. */
    private function member(string $supplier, string $email = 'boutique@example.test', string $role = 'manager'): User
    {
        $owner = $this->operationUser();
        $url = $this->op('POST', 'suppliers/'.$supplier.'/portal-invitations', ['email' => $email, 'role' => $role])
            ->assertCreated()
            ->json('data.url');
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

    public function test_invitations_memberships_and_the_portal_switch_keep_each_supplier_apart(): void
    {
        $this->operationUser();
        $a = $this->fixtureCatalogue();
        $b = $this->fixtureCatalogue();
        $url = $this->op('POST', 'suppliers/'.$a['supplier'].'/portal-invitations', [
            'email' => 'Boutique@Example.test',
            'role' => 'manager',
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data.url');
        $token = substr($url, strlen('/supplier-invitations/'));
        $this->assertSame(0, DB::table('supplier_invitations')->where('token_hash', $token)->count());

        // Only the invited address can accept; the same answer for any other person.
        $intruder = User::factory()->create(['email' => 'other@example.test']);
        $this->actAs($intruder);
        $this->portal('POST', 'supplier-invitations/'.$token.'/accept')->assertNotFound();
        $member = User::factory()->create(['email' => 'boutique@example.test', 'roles' => ['supplier']]);
        $this->actAs($member);
        $this->portal('POST', 'supplier-invitations/'.$token.'/accept')
            ->assertOk()
            ->assertJsonPath('data.suppliers.0.id', $a['supplier'])
            ->assertJsonPath('data.enabled', false);
        $this->portal('POST', 'supplier-invitations/'.$token.'/accept')->assertNotFound();

        // Built but closed: KORA opens it when it decides, with a recent confirmation, owner only.
        $this->portal('GET', 'supplier-portal/'.$a['supplier'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'SUPPLIER_PORTAL_DISABLED');
        $this->portal('GET', 'operations/supplier-portal/settings')->assertForbidden();
        $this->operationUser('procurement');
        $this->op('PUT', 'supplier-portal/settings', [
            'enabled' => true,
            'availability' => 'review',
            'version' => 0,
        ])->assertForbidden();
        $this->operationUser();
        $this->withSession(['auth.password_confirmed_at' => now()->subMinutes(16)->timestamp]);
        $this->op('PUT', 'supplier-portal/settings', [
            'enabled' => true,
            'availability' => 'review',
            'version' => 0,
        ])->assertForbidden();
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->op('PUT', 'supplier-portal/settings', ['enabled' => true, 'availability' => 'review', 'version' => 0])
            ->assertOk()
            ->assertJsonPath('data.enabled', true);

        $this->actAs($member);
        $this->portal('GET', 'supplier-portal/'.$a['supplier'])
            ->assertOk()
            ->assertJsonPath('data.role', 'manager')
            ->assertJsonPath('data.shops.0.id', $a['shop']);
        // Another supplier's space, offers and documents do not exist for this member.
        $this->portal('GET', 'supplier-portal/'.$b['supplier'])->assertNotFound();
        $this->portal('GET', 'supplier-portal/'.$b['supplier'].'/offers')->assertNotFound();
        $this->portal('POST', 'supplier-portal/'.$a['supplier'].'/offers/'.$b['offer'].'/availability', [
            'valid_until' => now()->addDay()->toIso8601String(),
            'preparation_minutes' => 30,
        ])->assertNotFound();
        $this->portal('GET', 'operations/supplier-proposals')->assertForbidden();

        // Revocation applies at once.
        $this->operationUser();
        $access = $this->op('GET', 'suppliers/'.$a['supplier'].'/portal-access')
            ->assertOk()
            ->json('data');
        $this->assertSame('accepted', $access['invitations'][0]['status']);
        $this->op('POST', 'supplier-members/'.$access['members'][0]['id'].'/revoke')
            ->assertOk()
            ->assertJsonPath('data.status', 'revoked');
        $this->actAs($member);
        $this->portal('GET', 'supplier-portal/'.$a['supplier'])->assertNotFound();
        $this->portal('GET', 'supplier-portal')->assertOk()->assertJsonCount(0, 'data.suppliers');
    }

    public function test_offers_availability_purchases_documents_and_proposals_stay_under_kora_control(): void
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $other = $this->fixtureCatalogue();
        $contract = $this->contract($ctx);
        $member = $this->member($ctx['supplier']);

        // Its own agreed price, never KORA's selling price.
        $offers = $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/offers')
            ->assertOk()
            ->json('data');
        $this->assertSame([$ctx['offer']], array_column($offers, 'id'));
        $this->assertSame('1000', $offers[0]['reference_price']);
        $this->assertArrayNotHasKey('selling_price', $offers[0]);

        // Availability: pending until the team applies it (review mode), at once in direct mode.
        $declaration = [
            'quantity' => 12,
            'valid_until' => now()->addDays(2)->toIso8601String(),
            'preparation_minutes' => 45,
            'note' => 'Stock en boutique (test).',
        ];
        $pending = $this->portal(
            'POST',
            'supplier-portal/'.$ctx['supplier'].'/offers/'.$ctx['offer'].'/availability',
            $declaration,
        )
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->json('data');
        $this->assertNull(DB::table('supplier_availability')->where('offer_id', $ctx['offer'])->first());
        $this->operationUser();
        $this->op('POST', 'supplier-availability-declarations/'.$pending['id'].'/review', ['decision' => 'apply'])
            ->assertOk()
            ->assertJsonPath('data.status', 'applied');
        $this->assertSame(
            12,
            (int) DB::table('supplier_availability')->where('offer_id', $ctx['offer'])->value('quantity'),
        );
        $this->assertSame(
            'PORTAL-'.$pending['id'],
            DB::table('supplier_availability')->where('offer_id', $ctx['offer'])->value('reference'),
        );
        $this->op('PUT', 'supplier-portal/settings', [
            'enabled' => true,
            'availability' => 'direct',
            'version' => 1,
        ])->assertOk();
        $this->actAs($member);
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/offers/'.$ctx['offer'].'/availability', [
            ...$declaration,
            'quantity' => 3,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'applied');
        $this->assertSame(
            3,
            (int) DB::table('supplier_availability')->where('offer_id', $ctx['offer'])->value('quantity'),
        );

        // A purchase appears only once the team shares it; the supplier's answer never confirms it by itself.
        $this->operationUser();
        $order = $this->purchase($ctx, $contract, 10, false);
        $this->actAs($member);
        $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/purchases')
            ->assertOk()
            ->assertJsonCount(0, 'data');
        $this->operationUser();
        $this->op('POST', 'purchase-orders/'.$order['id'].'/share')->assertOk();
        $this->actAs($member);
        $shared = $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/purchases')
            ->assertOk()
            ->json('data.0');
        $this->assertSame(10, $shared['lines'][0]['quantity']);
        $this->assertArrayNotHasKey('commercial_snapshot', $shared['lines'][0]);
        $this->assertArrayNotHasKey('contract_snapshot', $shared);
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/purchases/'.$order['id'].'/response', [
            'decision' => 'confirmed',
        ])->assertUnprocessable();
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/purchases/'.$order['id'].'/response', [
            'decision' => 'confirmed',
            'reference' => 'BC-TEST-1',
        ])
            ->assertCreated()
            ->assertJsonPath('data.responses.0.reference', 'BC-TEST-1');
        $this->assertSame('draft', DB::table('purchase_orders')->where('id', $order['id'])->value('status'));
        $this->assertSame(1, DB::table('outbox_events')->where('type', 'SupplierResponded')->count());
        try {
            DB::transaction(fn () => DB::table('supplier_responses')->update(['decision' => 'declined']));
            $this->fail('An answer stays as given.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('SUPPLIER_RESPONSE_IMMUTABLE', $e->getMessage());
        }
        $this->operationUser();
        $this->op('GET', 'suppliers/'.$ctx['supplier'].'/portal-responses')
            ->assertOk()
            ->assertJsonPath('data.0.reference', 'BC-TEST-1');
        $this->op('POST', 'purchases/'.$order['id'].'/confirm', [
            'version' => $order['version'],
            'supplier_confirmation_reference' => 'BC-TEST-1',
        ])->assertOk();

        // Statement: its purchases and debts, without KORA's internal revisions.
        $this->actAs($member);
        $statement = $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/statement')
            ->assertOk()
            ->json();
        $this->assertContains('purchase_confirmed', array_column($statement['data'], 'kind'));
        $this->assertNotContains('contract_revision', array_column($statement['data'], 'kind'));
        $this->assertArrayHasKey('balances', $statement);

        // Contracts it can read, and their signed copy only; documents shared both ways, revocable.
        $contracts = $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/contracts')
            ->assertOk()
            ->json('data');
        $this->assertSame('accepted', $contracts[0]['status']);
        $this->assertArrayNotHasKey('recorded_by', $contracts[0]['acceptance']);
        $this->portal(
            'GET',
            'supplier-portal/'.$ctx['supplier'].'/documents/'.$contracts[0]['signed_document_id'],
        )->assertOk();
        $this->operationUser();
        $private = $this->evidence($ctx['supplier'], 'supplier_invoice');
        $foreign = $this->evidence($other['supplier'], 'supplier_invoice');
        $this->op('POST', 'suppliers/'.$ctx['supplier'].'/shared-documents', [
            'document_id' => $foreign['id'],
            'title' => 'Wrong supplier',
        ])->assertUnprocessable();
        $this->actAs($member);
        $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/documents/'.$private['id'])->assertNotFound();
        $this->operationUser();
        $share = $this->op('POST', 'suppliers/'.$ctx['supplier'].'/shared-documents', [
            'document_id' => $private['id'],
            'title' => 'Facture de test',
        ])
            ->assertOk()
            ->json('data.0');
        $this->actAs($member);
        $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/documents/'.$private['id'])->assertOk();
        $upload = $this->post('/api/v1/supplier-portal/'.$ctx['supplier'].'/documents', [
            'file' => UploadedFile::fake()->createWithContent('bl.pdf', "%PDF-1.4\n% Test delivery slip only.\n%%EOF"),
            'title' => 'Bon de livraison (test)',
        ])
            ->assertCreated()
            ->json('data');
        $this->assertSame('supplier_portal', $upload['purpose']);
        $this->operationUser();
        $this->assertContains(
            'inbound',
            array_column(
                $this->op('GET', 'suppliers/'.$ctx['supplier'].'/shared-documents')->json('data'),
                'direction',
            ),
        );
        $this->op(
            'POST',
            'suppliers/'.$ctx['supplier'].'/shared-documents/'.$share['id'].'/revoke',
        )->assertOk();
        $this->actAs($member);
        $this->portal('GET', 'supplier-portal/'.$ctx['supplier'].'/documents/'.$private['id'])->assertNotFound();

        // Proposals: plain text, decided by the team; an accepted sheet becomes a draft, never published.
        $product = [
            'kind' => 'product',
            'shop_id' => $ctx['shop'],
            'payload' => [
                'name' => ['fr' => 'Lampe de bureau (test)', 'en' => null],
                'description' => ['fr' => 'Lampe LED, 3 intensités.', 'en' => null],
                'sku' => 'LAMP-TEST-1',
                'proposed_price' => '15000',
                'attributes' => ['puissance' => '8 W'],
            ],
        ];
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/proposals', [
            ...$product,
            'payload' => [...$product['payload'], 'name' => ['fr' => '<b>Lampe</b>']],
        ])->assertUnprocessable();
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/proposals', [
            ...$product,
            'shop_id' => $other['shop'],
        ])->assertNotFound();
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/proposals', [
            'kind' => 'offer_update',
            'offer_id' => $other['offer'],
            'payload' => ['minimum_quantity' => 2],
        ])->assertNotFound();
        $proposal = $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/proposals', $product)
            ->assertCreated()
            ->assertJsonPath('data.status', 'submitted')
            ->json('data');
        $this->assertStringStartsWith('PROP-', $proposal['number']);
        $profile = $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/proposals', [
            'kind' => 'profile',
            'payload' => ['phone' => '+237 6 99 00 00 02'],
        ])
            ->assertCreated()
            ->json('data');
        $this->operationUser('procurement');
        $this->op('POST', 'supplier-proposals/'.$proposal['id'].'/review', [
            'version' => 1,
            'decision' => 'accept',
            'category_id' => $ctx['category'],
            'slug' => 'lampe-test',
        ])->assertForbidden();
        $this->op('POST', 'supplier-proposals/'.$profile['id'].'/review', [
            'version' => 1,
            'decision' => 'reject',
        ])->assertUnprocessable();
        $this->op('POST', 'supplier-proposals/'.$profile['id'].'/review', [
            'version' => 1,
            'decision' => 'reject',
            'note' => 'Numéro déjà connu (test).',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');
        $this->operationUser();
        $this->op('POST', 'supplier-proposals/'.$proposal['id'].'/review', [
            'version' => 1,
            'decision' => 'accept',
        ])->assertUnprocessable();
        $accepted = $this->op('POST', 'supplier-proposals/'.$proposal['id'].'/review', [
            'version' => 1,
            'decision' => 'accept',
            'category_id' => $ctx['category'],
            'slug' => 'lampe-test',
        ])
            ->assertOk()
            ->assertJsonPath('data.status', 'accepted')
            ->json('data');
        $draft = DB::table('products')->where('id', $accepted['product_id'])->first();
        $this->assertSame('draft', $draft->status);
        $this->assertSame('Lampe de bureau (test)', json_decode($draft->draft, true)['name']['fr']);
        $this->assertSame(
            0,
            DB::table('offers')
                ->join('variants', 'variants.id', '=', 'offers.variant_id')
                ->where('variants.product_id', $accepted['product_id'])
                ->count(),
        );
        $this->actAs($member);
        $this->portal('POST', 'supplier-portal/'.$ctx['supplier'].'/proposals/'.$proposal['id'].'/withdraw', [
            'version' => $accepted['version'],
        ])->assertUnprocessable();
        try {
            DB::transaction(
                fn () => DB::table('supplier_proposals')
                    ->where('id', $proposal['id'])
                    ->update(['payload' => '{}']),
            );
            $this->fail('A proposal is kept as sent.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('SUPPLIER_PROPOSAL_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_collections_and_warranty_cases_show_what_to_hand_over_without_customer_data(): void
    {
        $o = $this->warehouseOrder(1);
        $this->readyOrder($o['order'], 1);
        $this->operationUser();
        $this->point(
            ['id' => DB::table('delivery_zones')->value('id')],
            ['shop_id' => $o['ctx']['shop'], 'district' => 'Akwa', 'landmark' => 'Test landmark only'],
        );
        $purchase = DB::table('purchase_orders')->first();
        $line = DB::table('purchase_lines')->where('purchase_order_id', $purchase->id)->first();
        $collection = $this->op('POST', 'purchases/'.$purchase->id.'/collections', [
            'shop_id' => $o['ctx']['shop'],
            'warehouse_id' => $o['location']['warehouse_id'],
            'lines' => [['purchase_line_id' => $line->id, 'quantity' => 1]],
        ])
            ->assertCreated()
            ->json('data');
        $member = $this->member($o['ctx']['supplier']);

        $seen = $this->portal('GET', 'supplier-portal/'.$o['ctx']['supplier'].'/collections')
            ->assertOk()
            ->json('data.0');
        $this->assertSame($collection['number'], $seen['number']);
        $this->assertFalse($seen['direct']);
        $this->assertSame(1, $seen['lines'][0]['expected_quantity']);
        $this->assertArrayNotHasKey('order_id', $seen);
        $this->portal(
            'POST',
            'supplier-portal/'.$o['ctx']['supplier'].'/collections/'.$collection['id'].'/response',
            ['decision' => 'issue'],
        )->assertUnprocessable();
        $this->portal(
            'POST',
            'supplier-portal/'.$o['ctx']['supplier'].'/collections/'.$collection['id'].'/response',
            ['decision' => 'ready', 'note' => 'Colis prêt au comptoir (test).'],
        )
            ->assertCreated()
            ->assertJsonPath('data.responses.0.decision', 'ready');
        $this->assertSame(
            'unassigned',
            DB::table('supplier_collections')->where('id', $collection['id'])->value('status'),
        );

        // A warranty case attributed to the supplier: serials and finding, never the customer.
        DB::table('after_sales_cases')->insert([
            'id' => (string) Str::uuid(),
            'number' => 'SAV-TEST-1',
            'order_id' => $o['order']['id'],
            'order_line_id' => DB::table('order_lines')->where('order_id', $o['order']['id'])->value('id'),
            'user_id' => DB::table('orders')->where('id', $o['order']['id'])->value('user_id'),
            'kind' => 'breakdown',
            'quantity' => 1,
            'identifiers' => json_encode(['SN-TEST-1']),
            'description' => 'Client description with private details (test).',
            'reported_quickly' => false,
            'status' => 'proposed',
            'decided_at' => now(),
            'resolution' => 'repair',
            'responsibility' => 'supplier',
            'responsibility_note' => 'Panne d\'origine (test).',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cases = $this->portal('GET', 'supplier-portal/'.$o['ctx']['supplier'].'/warranties')
            ->assertOk()
            ->json('data.cases');
        $this->assertSame(['SN-TEST-1'], $cases[0]['identifiers']);
        $this->assertStringNotContainsString('private details', json_encode($cases));
        $this->assertArrayNotHasKey('user_id', $cases[0]);
        $this->assertNotNull($member);
    }
}
