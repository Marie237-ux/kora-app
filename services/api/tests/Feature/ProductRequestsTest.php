<?php

namespace Tests\Feature;

use App\Domain\Media\MediaLibrary;
use App\Domain\Procurement\Records;
use App\Domain\ProductRequests\DraftCleanup;
use App\Models\User;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class ProductRequestsTest extends TestCase
{
    use ProcurementFixtures, RefreshDatabase;

    private function client(?User $user = null): User
    {
        $user ??= User::factory()->create(['roles' => ['client'], 'password' => 'TestOnly12345!']);
        $this->withSession(['password_hash_web' => $user->getAuthPassword()]);
        $this->app['session']->save();
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withHeaders([
            'Origin' => 'http://localhost:4321',
            'Accept' => 'application/json',
        ]);

        return $user;
    }

    private function requestApi(string $method, string $path, array $data = [], ?string $key = null)
    {
        return $this->withHeader('Idempotency-Key', $key ?? $this->fixtureId())->json(
            $method,
            '/api/v1/'.$path,
            $data,
        );
    }

    private function draft(array $details = [], string $locale = 'fr'): array
    {
        return $this->requestApi('POST', 'product-request-drafts', [
            'locale' => $locale,
            'details' => array_replace(
                [
                    'name' => 'Produit recherché de test',
                    'description' => 'Description précise de la variante recherchée pour les tests.',
                    'quantity' => 2,
                ],
                $details,
            ),
        ])
            ->assertCreated()
            ->json('data');
    }

    private function submitted(): array
    {
        $user = $this->client();
        $draft = $this->draft();
        $r = $this->requestApi('POST', 'product-requests/'.$draft['id'].'/submit', [
            'version' => $draft['version'],
            'reviewed' => true,
        ])
            ->assertOk()
            ->json('data');

        return ['user' => $user, 'request' => $r];
    }

    private function drain(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->artisan('outbox:process')->assertSuccessful();
        }
    }

    public function test_guest_draft_and_photo_survive_real_login_but_are_private_to_the_claiming_account(): void
    {
        Storage::fake('local');
        $this->withSession([])->withHeaders(['Origin' => 'http://localhost:4321', 'Accept' => 'application/json']);
        $draft = $this->draft();
        $image = UploadedFile::fake()->image('private.png', 40, 30);
        $photo = $this->post('/api/v1/product-requests/'.$draft['id'].'/photos', ['file' => $image])
            ->assertCreated()
            ->json('data');
        $this->get($photo['photo']['url'])->assertOk()->assertHeader('Content-Type', 'image/webp');
        $this->requestApi('POST', 'product-requests/'.$draft['id'].'/submit', [
            'version' => $photo['request_version'],
            'reviewed' => true,
        ])->assertUnauthorized();
        $user = User::factory()->create(['roles' => ['client'], 'password' => 'TestOnly12345!']);
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'TestOnly12345!'])->assertOk();
        $submitted = $this->requestApi('POST', 'product-requests/'.$draft['id'].'/submit', [
            'version' => $photo['request_version'],
            'reviewed' => true,
        ])
            ->assertOk()
            ->json('data');
        $this->assertSame('submitted', $submitted['state']);
        $this->assertCount(1, $submitted['photos']);
        $this->assertDatabaseHas('product_requests', [
            'id' => $draft['id'],
            'user_id' => $user->id,
            'guest_owner_hash' => null,
        ]);
        $this->client();
        $this->requestApi('GET', 'product-requests/'.$draft['id'])->assertNotFound();
        $this->get($photo['photo']['url'])->assertNotFound();
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_another_guest_session_cannot_read_or_overwrite_a_draft_and_command_replay_is_exact(): void
    {
        $this->withSession([])->withHeaders(['Origin' => 'http://localhost:4321', 'Accept' => 'application/json']);
        $key = $this->fixtureId();
        $body = ['locale' => 'fr', 'details' => []];
        $first = $this->requestApi('POST', 'product-request-drafts', $body, $key)->assertCreated()->json('data');
        $this->requestApi('POST', 'product-request-drafts', $body, $key)
            ->assertCreated()
            ->assertJsonPath('data.id', $first['id']);
        $body['locale'] = 'en';
        $this->requestApi('POST', 'product-request-drafts', $body, $key)->assertConflict();
        $this->withSession(['kora_anonymous_owner' => str_repeat('a', 64)]);
        $this->requestApi('GET', 'product-requests/'.$first['id'])->assertNotFound();
        $this->requestApi('PUT', 'product-request-drafts/'.$first['id'], [
            'version' => 1,
            'locale' => 'en',
            'details' => [],
        ])->assertNotFound();
        $this->assertDatabaseCount('product_requests', 1);
    }

    public function test_submission_needs_review_quantity_and_sufficient_information_and_never_accepts_a_forged_owner(): void
    {
        $user = $this->client();
        $r = $this->requestApi('POST', 'product-request-drafts', [
            'locale' => 'en',
            'details' => [],
            'user_id' => 1234,
            'state' => 'published',
        ])
            ->assertCreated()
            ->json('data');
        $this->assertDatabaseHas('product_requests', ['id' => $r['id'], 'user_id' => $user->id, 'state' => 'draft']);
        $this->requestApi('POST', 'product-requests/'.$r['id'].'/submit', [
            'version' => 1,
            'reviewed' => false,
        ])->assertUnprocessable();
        $this->requestApi('POST', 'product-requests/'.$r['id'].'/submit', [
            'version' => 1,
            'reviewed' => true,
        ])->assertUnprocessable();
        $updated = $this->requestApi('PUT', 'product-request-drafts/'.$r['id'], [
            'version' => 1,
            'locale' => 'en',
            'details' => [
                'description' => 'Specific test product description without any photo.',
                'quantity' => 3,
                'budget' => '50000',
                'reference_url' => 'https://example.test/item',
            ],
        ])
            ->assertOk()
            ->json('data');
        $this->requestApi('POST', 'product-requests/'.$r['id'].'/submit', [
            'version' => $updated['version'],
            'reviewed' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.details.budget', '50000');
        $this->requestApi('PUT', 'product-request-drafts/'.$r['id'], [
            'version' => 3,
            'locale' => 'en',
            'details' => [],
        ])->assertUnprocessable();
        $this->requestApi('POST', 'product-request-drafts', [
            'locale' => 'en',
            'details' => ['reference_url' => 'file:///etc/passwd'],
        ])->assertUnprocessable();
        $this->requestApi('POST', 'product-request-drafts', [
            'locale' => 'en',
            'details' => ['reference_url' => 'https://user:secret@example.test/p'],
        ])->assertUnprocessable();
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_photos_are_normalized_deduplicated_and_not_public_catalogue_media(): void
    {
        Storage::fake('local');
        $this->client();
        $r = $this->draft();
        $file = UploadedFile::fake()->image('private.png', 80, 70);
        $first = $this->post('/api/v1/product-requests/'.$r['id'].'/photos', ['file' => $file])
            ->assertCreated()
            ->json('data');
        $this->post('/api/v1/product-requests/'.$r['id'].'/photos', ['file' => $file])
            ->assertCreated()
            ->assertJsonPath('data.photo.id', $first['photo']['id']);
        $this->assertDatabaseCount('product_request_photos', 1);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertCount(1, Storage::disk('local')->allFiles('product-requests'));
        $record = DB::table('product_request_photos')->first();
        $response = $this->get($first['photo']['url'])->assertOk();
        $this->assertSame($record->sha256, hash('sha256', $response->getContent()));
        $this->get('/api/v1/media/'.$record->id.'/public')->assertNotFound();
        $this->post('/api/v1/product-requests/'.$r['id'].'/photos', [
            'file' => UploadedFile::fake()->createWithContent('attack.svg', '<svg onload="alert(1)"/>'),
        ])->assertUnprocessable();
        $this->requestApi('POST', 'product-requests/'.$r['id'].'/photos/'.$record->id.'/remove', [
            'version' => $first['request_version'],
        ])->assertOk();
        $this->get($first['photo']['url'])->assertNotFound();
    }

    public function test_staff_notes_proposals_and_grouping_never_leak_to_the_customer_or_another_account(): void
    {
        $s = $this->submitted();
        $id = $s['request']['id'];
        $staff = $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $group = $this->op('POST', 'product-request-groups', ['title' => 'Internal test grouping'])
            ->assertCreated()
            ->json('data');
        $version = $this->op('GET', 'product-requests/'.$id)->json('data.version');
        $this->op('POST', 'product-requests/'.$id.'/assignment', [
            'version' => $version,
            'assignee_id' => $staff->id,
            'priority' => 'high',
            'group_id' => $group['id'],
        ])->assertOk();
        $version = $this->op('GET', 'product-requests/'.$id)->json('data.version');
        $this->op('POST', 'product-requests/'.$id.'/messages', [
            'version' => $version,
            'body' => 'PRIVATE INTERNAL ONLY',
            'locale' => 'fr',
            'visibility' => 'internal',
        ])->assertCreated();
        $version = $this->op('GET', 'product-requests/'.$id)->json('data.version');
        $this->op('POST', 'product-requests/'.$id.'/candidates', [
            'version' => $version,
            'supplier_id' => $ctx['supplier'],
            'quoted_amount' => '45000',
            'notes' => 'PRIVATE SOURCING NOTE',
            'reference' => 'TEST QUOTE',
        ])->assertCreated();
        $this->client($s['user']);
        $this->requestApi('GET', 'product-requests/'.$id)
            ->assertOk()
            ->assertJsonMissingPath('data.group_id')
            ->assertJsonMissingPath('data.customer_id')
            ->assertJsonMissingPath('data.assignee_id');
        $this->requestApi('GET', 'product-requests/'.$id.'/messages')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
        $events = $this->requestApi('GET', 'product-requests/'.$id.'/events')
            ->assertOk()
            ->getContent();
        $this->assertStringNotContainsString('PRIVATE', $events);
        $this->assertStringNotContainsString($group['id'], $events);
        $this->op('GET', 'product-requests/'.$id.'/candidates')->assertForbidden();
        $this->client();
        $this->requestApi('GET', 'product-requests/'.$id)->assertNotFound();
    }

    public function test_clarification_and_reply_have_history_and_an_internal_account_notification_without_email(): void
    {
        $s = $this->submitted();
        $id = $s['request']['id'];
        $this->operationUser('support');
        $this->op('POST', 'product-requests/'.$id.'/state', [
            'version' => $s['request']['version'],
            'state' => 'needs_details',
            'message' => 'Veuillez préciser la dimension.',
        ])->assertOk();
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 1);
        $this->client($s['user']);
        $request = $this->requestApi('GET', 'product-requests/'.$id)->json('data');
        $key = $this->fixtureId();
        $body = ['version' => $request['version'], 'body' => 'La dimension souhaitée est de 30 cm.'];
        $this->requestApi('POST', 'product-requests/'.$id.'/messages', $body, $key)
            ->assertCreated()
            ->assertJsonPath('data.state', 'reviewing');
        $this->requestApi('POST', 'product-requests/'.$id.'/messages', $body, $key)->assertCreated();
        $this->requestApi('GET', 'product-requests/'.$id.'/messages')
            ->assertOk()
            ->assertJsonPath('meta.total', 2);
        $n = $this->requestApi('GET', 'notifications?locale=en')
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'product_request_message')
            ->assertJsonPath('data.0.title', 'KORA replied to your request.')
            ->json('data.0');
        $this->requestApi('POST', 'notifications/'.$n['id'].'/read')->assertOk();
        $this->requestApi('GET', 'notifications?unread=1')->assertJsonPath('meta.total', 0);
        $this->client();
        $this->requestApi('POST', 'notifications/'.$n['id'].'/read')->assertNotFound();
    }

    public function test_draft_offer_and_failed_publication_never_announce_availability_then_actual_publication_notifies_once(): void
    {
        $s = $this->submitted();
        $id = $s['request']['id'];
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $media = app(MediaLibrary::class)->upload(
            UploadedFile::fake()->image('catalogue.png', 50, 50),
            ['fr' => 'Test', 'en' => 'Test'],
            Auth::guard('web')->user(),
        );
        $media = app(MediaLibrary::class)->setPublic($media['id'], $media['version'], true, Auth::guard('web')->user());
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'draft', 'image_path' => $media['url']]);
        $this->op('POST', 'product-requests/'.$id.'/offer', [
            'version' => $s['request']['version'],
            'offer_id' => $ctx['offer'],
            'is_alternative' => false,
            'identity_verified' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.offer', null);
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 0);
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update(['status' => 'draft']);
        $this->postJson('/api/v1/operations/catalogue/offers/'.$ctx['offer'].'/publish', [
            'version' => 1,
        ])->assertUnprocessable();
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 0);
        DB::table('products')
            ->where('id', $ctx['product'])
            ->update(['status' => 'published']);
        $this->postJson('/api/v1/operations/catalogue/offers/'.$ctx['offer'].'/publish', [
            'version' => 1,
        ])->assertOk();
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 1);
        $r = DB::table('product_requests')->find($id);
        $this->assertSame('published', $r->state);
        Records::event('ProductRequestOfferReady', 'TEST REPLAY', [
            'request_id' => $id,
            'offer_id' => $ctx['offer'],
            'proposal' => false,
        ]);
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 1);
        $this->client($s['user']);
        $this->requestApi('GET', 'notifications')
            ->assertJsonPath('data.0.offer.price.amount', 1000)
            ->assertJsonPath('data.0.offer.availability', 'unconfirmed');
        DB::table('offers')
            ->where('id', $ctx['offer'])
            ->update(['status' => 'archived']);
        $this->requestApi('GET', 'notifications')->assertJsonPath('data.0.offer', null);
        $this->requestApi('GET', 'product-requests/'.$id)->assertJsonPath('data.currently_on_site', false);
    }

    public function test_alternative_requires_customer_choice_and_never_creates_a_reservation_or_payment(): void
    {
        $s = $this->submitted();
        $id = $s['request']['id'];
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $this->op('POST', 'product-requests/'.$id.'/offer', [
            'version' => $s['request']['version'],
            'offer_id' => $ctx['offer'],
            'is_alternative' => true,
        ])->assertOk();
        $this->drain();
        $this->assertDatabaseHas('account_notifications', ['kind' => 'product_request_proposal']);
        $this->assertDatabaseMissing('account_notifications', ['kind' => 'product_request_available']);
        $this->client($s['user']);
        $r = $this->requestApi('GET', 'product-requests/'.$id)->json('data');
        $this->requestApi('POST', 'product-requests/'.$id.'/alternative', [
            'version' => $r['version'],
            'accept' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.state', 'preparing');
        $this->drain();
        $this->assertDatabaseHas('account_notifications', ['kind' => 'product_request_available']);
        $this->assertDatabaseCount('inventory_reservations', 0);
        $this->assertDatabaseCount('supplier_payments', 0);
    }

    public function test_product_draft_reuses_only_customer_text_and_never_publishes_photos_or_translation(): void
    {
        $s = $this->submitted();
        $id = $s['request']['id'];
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $data = [
            'version' => $s['request']['version'],
            'product' => [
                'category_id' => $ctx['category'],
                'slug' => 'request-test-product',
                'variants' => [['sku' => 'REQUEST-TEST-SKU', 'attributes' => []]],
            ],
        ];
        $created = $this->op('POST', 'product-requests/'.$id.'/product-draft', $data)
            ->assertCreated()
            ->json('data');
        $this->assertSame('draft', $created['product']['status']);
        $this->assertSame('', $created['product']['draft']['name']['en']);
        $this->assertSame('Produit recherché de test', $created['product']['draft']['name']['fr']);
        $this->assertDatabaseCount('media_assets', 0);
        $this->assertDatabaseCount('account_notifications', 0);
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 0);
        $this->client($s['user']);
        $this->requestApi('GET', 'product-requests/'.$id)
            ->assertJsonMissingPath('data.draft_product_id')
            ->assertJsonPath('data.offer', null);
    }

    public function test_expired_drafts_and_private_session_snapshots_are_purged_without_touching_submitted_requests(): void
    {
        Storage::fake('local');
        $owner = $this->client();
        $key = $this->fixtureId();
        $body = ['locale' => 'fr', 'details' => ['name' => 'Private expired draft']];
        $draft = $this->requestApi('POST', 'product-request-drafts', $body, $key)->assertCreated()->json('data');
        $photo = $this->post('/api/v1/product-requests/'.$draft['id'].'/photos', [
            'file' => UploadedFile::fake()->image('expiry.png'),
        ])
            ->assertCreated()
            ->json('data');
        $submitted = $this->submitted()['request'];
        $this->client($owner);
        $this->travel(8)->days();
        $this->requestApi('GET', 'product-requests/'.$draft['id'])->assertUnprocessable();
        $this->artisan('product-requests:cleanup-drafts')->assertSuccessful();
        $this->assertDatabaseMissing('product_requests', ['id' => $draft['id']]);
        $this->assertDatabaseHas('product_requests', ['id' => $submitted['id'], 'state' => 'submitted']);
        $this->assertDatabaseCount('product_request_photos', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('product-requests'));
        $this->assertSame(0, DB::table('session_command_receipts')->whereNotNull('result')->count());
    }

    public function test_expired_session_key_cannot_replay_private_draft_details_even_before_cleanup(): void
    {
        $this->withSession([])->withHeaders(['Origin' => 'http://localhost:4321', 'Accept' => 'application/json']);
        $key = $this->fixtureId();
        $body = ['locale' => 'fr', 'details' => ['name' => 'Private expired response']];
        $this->requestApi('POST', 'product-request-drafts', $body, $key)->assertCreated();
        $this->travel(8)->days();
        $this->requestApi('POST', 'product-request-drafts', $body, $key)
            ->assertUnprocessable()
            ->assertJsonMissingPath('data');
        $this->artisan('product-requests:cleanup-drafts')->assertSuccessful();
        $this->requestApi('POST', 'product-request-drafts', $body, $key)->assertUnprocessable();
        $this->assertDatabaseCount('product_requests', 0);
    }

    public function test_failed_storage_deletion_keeps_a_revoked_photo_record_for_retry(): void
    {
        Storage::fake('local');
        $this->client();
        $draft = $this->draft();
        $this->post('/api/v1/product-requests/'.$draft['id'].'/photos', [
            'file' => UploadedFile::fake()->image('retry.png'),
        ])->assertCreated();
        $realDisk = Storage::disk('local');
        $brokenDisk = \Mockery::mock(FilesystemAdapter::class);
        $brokenDisk->shouldReceive('exists')->once()->andThrow(new \RuntimeException('Test-only storage failure'));
        Storage::shouldReceive('disk')->with('local')->andReturn($brokenDisk, $realDisk);
        $this->travel(8)->days();
        $first = app(DraftCleanup::class)->run(10);
        $this->assertSame(1, $first['storage_failures']);
        $this->assertSame(0, $first['drafts_deleted']);
        $this->assertDatabaseHas('product_request_photos', ['request_id' => $draft['id'], 'revoked' => true]);
        $second = app(DraftCleanup::class)->run(10);
        $this->assertSame(0, $second['storage_failures']);
        $this->assertSame(1, $second['drafts_deleted']);
        $this->assertSame([], $realDisk->allFiles('product-requests'));
    }

    public function test_staff_cannot_browse_guest_drafts_and_read_only_staff_cannot_change_requests(): void
    {
        $this->client();
        $draft = $this->draft();
        $this->operationUser();
        $this->op('GET', 'product-requests/'.$draft['id'])->assertNotFound();
        $this->op('GET', 'product-requests')->assertOk()->assertJsonPath('meta.total', 0);
        $s = $this->submitted();
        $this->operationUser('finance');
        $this->op('GET', 'product-requests/'.$s['request']['id'])->assertForbidden();
        $this->op('POST', 'product-requests/'.$s['request']['id'].'/state', [
            'version' => $s['request']['version'],
            'state' => 'reviewing',
        ])->assertForbidden();
    }

    public function test_metrics_count_actual_requests_and_replies_without_inventing_purchases(): void
    {
        $this->freezeTime();
        $s = $this->submitted();
        $this->operationUser('support');
        $this->travel(2)->minutes();
        $internal = $this->op('POST', 'product-requests/'.$s['request']['id'].'/messages', [
            'version' => $s['request']['version'],
            'body' => 'Note interne de test',
            'visibility' => 'internal',
            'locale' => 'fr',
        ])
            ->assertCreated()
            ->json('data');
        $this->travel(3)->minutes();
        $this->op('POST', 'product-requests/'.$s['request']['id'].'/messages', [
            'version' => $internal['request_version'],
            'body' => 'Réponse de test',
            'visibility' => 'customer',
            'locale' => 'fr',
        ])->assertCreated();
        $this->op('GET', 'product-request-metrics')
            ->assertOk()
            ->assertJsonPath('data.submitted_count', 1)
            ->assertJsonPath('data.first_staff_reply.answered_count', 1)
            ->assertJsonPath('data.first_staff_reply.mean_seconds', 300)
            ->assertJsonPath('data.published_request_count', 0)
            ->assertJsonPath('data.attributed_order_count', null)
            ->assertJsonPath('data.purchase_attribution_available', false);
        $this->op('GET', 'product-request-metrics?from=2020-01-01&to=2026-01-01')->assertUnprocessable();
        $this->client($s['user']);
        $this->op('GET', 'product-request-metrics')->assertForbidden();
    }

    public function test_withdrawal_before_worker_suppresses_availability_and_closed_requests_are_immutable(): void
    {
        $s = $this->submitted();
        $id = $s['request']['id'];
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $linked = $this->op('POST', 'product-requests/'.$id.'/offer', [
            'version' => $s['request']['version'],
            'offer_id' => $ctx['offer'],
            'is_alternative' => false,
            'identity_verified' => true,
        ])
            ->assertOk()
            ->json('data');
        $this->client($s['user']);
        $this->requestApi('POST', 'product-requests/'.$id.'/withdraw', [
            'version' => $linked['version'],
        ])->assertOk();
        $this->drain();
        $this->assertDatabaseCount('account_notifications', 0);
        $this->requestApi('POST', 'product-requests/'.$id.'/messages', [
            'version' => $linked['version'] + 1,
            'body' => 'A later message',
        ])->assertUnprocessable();
        $this->operationUser();
        $this->op('POST', 'product-requests/'.$id.'/state', [
            'version' => $linked['version'] + 1,
            'state' => 'reviewing',
        ])->assertUnprocessable();
    }
}
