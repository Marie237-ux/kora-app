<?php

namespace Tests\Feature;

use App\Models\HeroScene;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $user = User::factory()->create(['roles' => ['catalogue'], 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user)
            ->withSession(['mfa_verified' => true])
            ->withHeader('Origin', 'http://localhost:3000');

        return $user;
    }

    private function scene(array $replace = []): array
    {
        return array_replace(
            [
                'kind' => 'campaign',
                'target_id' => null,
                'category' => null,
                'title' => ['fr' => 'Tout Douala', 'en' => 'All of Douala'],
                'description' => ['fr' => 'Une sélection locale.', 'en' => 'A local collection.'],
                'label' => ['fr' => 'Notre sélection', 'en' => 'Our selection'],
                'cta' => ['fr' => 'Découvrir', 'en' => 'Discover'],
                'alt' => ['fr' => 'Logo KORA', 'en' => 'KORA logo'],
                'media' => '/brand/logo.png',
                'mobile_media' => null,
                'decor' => 'circle',
                'preset' => 'signature',
                'hold_ms' => 8000,
                'autoplay' => true,
                'starts_at' => null,
                'ends_at' => null,
                'priority' => 0,
            ],
            $replace,
        );
    }

    public function test_guest_and_customer_cannot_read_operations(): void
    {
        $this->getJson('/api/v1/operations/content/scenes')->assertUnauthorized();
        $this->actingAs(User::factory()->create(['roles' => ['client']]))
            ->withHeader('Origin', 'http://localhost:3000')
            ->getJson('/api/v1/operations/content/scenes')
            ->assertForbidden();
    }

    public function test_staff_password_session_alone_cannot_access_management(): void
    {
        $user = User::factory()->create(['roles' => ['owner'], 'two_factor_confirmed_at' => now()]);
        $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost:3000')
            ->getJson('/api/v1/operations/content/scenes')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'MFA_REQUIRED');
    }

    public function test_registration_cannot_grant_roles_or_mfa_state(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Test client',
            'email' => 'client@example.test',
            'password' => 'LongPassword1234',
            'password_confirmation' => 'LongPassword1234',
            'roles' => ['owner'],
            'two_factor_confirmed_at' => now(),
        ])->assertCreated();
        $user = User::where('email', 'client@example.test')->firstOrFail();
        $this->assertSame(['client'], $user->roles);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_preferences_only_change_the_authenticated_user_and_ignore_sensitive_fields(): void
    {
        $first = User::factory()->create(['roles' => ['client']]);
        $second = User::factory()->create(['locale' => 'fr', 'roles' => ['client']]);
        $this->actingAs($first)
            ->withHeader('Origin', 'http://localhost:4321')
            ->patchJson('/api/v1/me/preferences', [
                'locale' => 'en',
                'theme' => 'dark',
                'id' => $second->id,
                'roles' => ['owner'],
            ])
            ->assertOk();
        $this->assertSame('en', $first->fresh()->locale);
        $this->assertSame('fr', $second->fresh()->locale);
        $this->assertSame(['client'], $first->fresh()->roles);
    }

    public function test_draft_is_private_until_publication_and_edits_do_not_change_the_publication(): void
    {
        $this->staff();
        $created = $this->postJson('/api/v1/operations/content/scenes', [
            'name' => 'Accueil',
            'draft' => $this->scene(),
        ])
            ->assertCreated()
            ->assertJsonPath('data.version', 1)
            ->assertJsonPath('data.paused', false)
            ->json('data');
        $this->getJson('/api/v1/content/hero')->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/operations/content/scenes/'.$created['id'].'/publish', [
            'version' => 1,
        ])->assertOk();
        $this->getJson('/api/v1/content/hero')
            ->assertJsonPath('data.0.title', 'Tout Douala')
            ->assertJsonMissingPath('data.0.draft');
        $draft = $this->scene(['title' => ['fr' => 'Nouveau brouillon', 'en' => 'New draft']]);
        $this->putJson('/api/v1/operations/content/scenes/'.$created['id'], [
            'version' => 2,
            'name' => 'Accueil',
            'draft' => $draft,
        ])->assertOk();
        $this->getJson('/api/v1/content/hero')->assertJsonPath('data.0.title', 'Tout Douala');
        $this->getJson('/api/v1/operations/content/scenes/'.$created['id'].'/preview')
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertJsonPath('data.title', 'Nouveau brouillon');
        $this->assertDatabaseCount('outbox_events', 1);
        $this->assertDatabaseCount('hero_revisions', 1);
    }

    public function test_stale_edit_or_duplicate_publication_is_rejected_without_duplicate_effects(): void
    {
        $this->staff();
        $scene = HeroScene::create(['name' => 'Test', 'draft' => $this->scene()]);
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/publish', ['version' => 1])->assertOk();
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/publish', [
            'version' => 1,
        ])->assertConflict();
        $this->putJson('/api/v1/operations/content/scenes/'.$scene->id, [
            'name' => 'Stale',
            'draft' => $this->scene(),
            'version' => 1,
        ])->assertConflict();
        $this->assertDatabaseCount('hero_revisions', 1);
        $this->assertDatabaseCount('outbox_events', 1);
    }

    public function test_missing_english_content_blocks_publication_and_does_not_emit_event(): void
    {
        $this->staff();
        $scene = HeroScene::create([
            'name' => 'Test',
            'draft' => $this->scene(['title' => ['fr' => 'Test', 'en' => '']]),
        ]);
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/publish', [
            'version' => 1,
        ])->assertUnprocessable();
        $this->assertDatabaseCount('outbox_events', 0);
        $this->assertNull($scene->fresh()->publication);
    }

    public function test_unavailable_media_blocks_publication_without_a_false_success(): void
    {
        $this->staff();
        $scene = HeroScene::create([
            'name' => 'Missing media',
            'draft' => $this->scene(['media' => '/media/missing-test.webp']),
        ]);
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/publish', [
            'version' => 1,
        ])->assertUnprocessable();
        $this->assertNull($scene->fresh()->publication);
        $this->assertDatabaseCount('outbox_events', 0);
    }

    public function test_reorder_checks_all_versions_and_rolls_back_the_whole_operation(): void
    {
        $this->staff();
        $first = HeroScene::create(['name' => 'First', 'draft' => $this->scene(), 'position' => 0]);
        $second = HeroScene::create(['name' => 'Second', 'draft' => $this->scene(), 'position' => 1]);
        $this->postJson('/api/v1/operations/content/reorder', [
            'items' => [['id' => $second->id, 'version' => 1], ['id' => $first->id, 'version' => 9]],
        ])->assertConflict();
        $this->assertSame(1, $second->fresh()->version);
        $this->postJson('/api/v1/operations/content/reorder', [
            'items' => [['id' => $second->id, 'version' => 1], ['id' => $first->id, 'version' => 1]],
        ])->assertNoContent();
        $this->assertSame(0, $second->fresh()->position);
        $this->assertSame(1, $first->fresh()->position);
    }

    public function test_arbitrary_script_media_and_settings_are_rejected(): void
    {
        $this->staff();
        $this->postJson('/api/v1/operations/content/scenes', [
            'name' => 'Test',
            'draft' => $this->scene(['media' => 'javascript:alert(1)']),
        ])->assertUnprocessable();
        $this->postJson('/api/v1/operations/content/scenes', [
            'name' => 'Test',
            'draft' => [...$this->scene(), 'javascript' => 'alert(1)'],
        ])->assertUnprocessable();
    }

    public function test_scheduled_and_paused_scenes_are_not_served_and_priority_is_resolved(): void
    {
        $this->travelTo(now()->setDate(2026, 12, 25)->setTime(12, 0));
        foreach (
            [
                ['priority' => 1],
                ['priority' => 10],
                ['ends_at' => now()->subHour()->toIso8601String(), 'starts_at' => now()->subDays(2)->toIso8601String()],
                ['starts_at' => now()->addHour()->toIso8601String()],
            ] as $i => $override
        ) {
            $content = $this->scene($override);
            HeroScene::create([
                'name' => 'Scene '.$i,
                'draft' => $content,
                'publication' => $content,
                'position' => $i,
            ]);
        }
        $response = $this->getJson('/api/v1/content/hero')->assertOk()->assertJsonCount(2, 'data');
        $this->assertSame(HeroScene::where('name', 'Scene 1')->first()->id, $response->json('data.0.id'));
        HeroScene::where('name', 'Scene 1')->update(['paused' => true]);
        $this->getJson('/api/v1/content/hero')->assertJsonCount(1, 'data');
    }

    public function test_revisions_can_be_restored_to_draft_without_republishing(): void
    {
        $this->staff();
        $scene = HeroScene::create(['name' => 'Test', 'draft' => $this->scene()]);
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/publish', ['version' => 1])->assertOk();
        $this->putJson('/api/v1/operations/content/scenes/'.$scene->id, [
            'name' => 'Test',
            'version' => 2,
            'draft' => $this->scene(['title' => ['fr' => 'Deuxième', 'en' => 'Second']]),
        ])->assertOk();
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/publish', ['version' => 3])->assertOk();
        $this->postJson('/api/v1/operations/content/scenes/'.$scene->id.'/restore', [
            'version' => 4,
            'revision' => 2,
        ])
            ->assertOk()
            ->assertJsonPath('data.draft.title.fr', 'Tout Douala');
        $this->getJson('/api/v1/content/hero')->assertJsonPath('data.0.title', 'Deuxième');
        $createdAt = $this->getJson('/api/v1/operations/content/scenes/'.$scene->id.'/revisions')
            ->assertOk()
            ->json('data.0.created_at');
        $this->assertStringEndsWith('+00:00', $createdAt);
    }

    public function test_offer_dto_does_not_expose_supplier_cost_or_contract_and_live_price_is_used(): void
    {
        $id = $this->fixtureOffer();
        $this->getJson('/api/v1/catalogue/'.$id)
            ->assertOk()
            ->assertJsonPath('data.price.amount', 5000)
            ->assertJsonMissingPath('data.reference_price')
            ->assertJsonMissingPath('data.supplier_id');
        $content = $this->scene(['kind' => 'product', 'target_id' => $id]);
        HeroScene::create(['name' => 'Product', 'draft' => $content, 'publication' => $content]);
        DB::table('offers')
            ->where('id', $id)
            ->update(['selling_price' => 4900]);
        $this->getJson('/api/v1/content/hero')->assertJsonPath('data.0.offer.price.amount', 4900);
        DB::table('products')->update(['status' => 'archived']);
        $this->getJson('/api/v1/content/hero')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/catalogue/'.$id)->assertNotFound();
    }

    public function test_publication_is_localized_without_creating_a_second_scene(): void
    {
        $data = $this->scene();
        HeroScene::create(['name' => 'Locale', 'draft' => $data, 'publication' => $data]);
        $this->getJson('/api/v1/content/hero?locale=en')
            ->assertJsonPath('data.0.title', 'All of Douala')
            ->assertJsonPath('data.0.href', '/en/catalogue');
        $this->assertDatabaseCount('hero_scenes', 1);
    }

    private function fixtureOffer(): string
    {
        $supplier = (string) Str::uuid();
        $shop = (string) Str::uuid();
        $category = (string) Str::uuid();
        $product = (string) Str::uuid();
        $variant = (string) Str::uuid();
        $offer = (string) Str::uuid();
        DB::table('suppliers')->insert(['id' => $supplier, 'name' => 'Supplier fixture']);
        DB::table('shops')->insert([
            'id' => $shop,
            'supplier_id' => $supplier,
            'slug' => 'shop-test',
            'name' => 'Shop fixture',
            'description' => json_encode(['fr' => 'Test', 'en' => 'Test']),
            'published' => true,
        ]);
        DB::table('categories')->insert([
            'id' => $category,
            'slug' => 'test',
            'name' => json_encode(['fr' => 'Test', 'en' => 'Test']),
            'published' => true,
        ]);
        DB::table('products')->insert([
            'id' => $product,
            'category_id' => $category,
            'slug' => 'test',
            'name' => json_encode(['fr' => 'Produit test', 'en' => 'Test product']),
            'description' => json_encode(['fr' => 'Fixture', 'en' => 'Fixture']),
            'status' => 'published',
        ]);
        DB::table('variants')->insert([
            'id' => $variant,
            'product_id' => $product,
            'sku' => 'TEST-1',
            'attributes' => '{}',
        ]);
        DB::table('offers')->insert([
            'id' => $offer,
            'variant_id' => $variant,
            'shop_id' => $shop,
            'reference_price' => 5000,
            'selling_price' => 5000,
            'availability' => 'available',
            'status' => 'published',
            'warranty' => json_encode(['fr' => 'Aucune garantie de test promise', 'en' => 'No test warranty promised']),
        ]);

        return $offer;
    }
}
