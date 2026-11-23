<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\DeliveryFixtures;
use Tests\Support\ProcurementFixtures;
use Tests\TestCase;

final class StoryReactionsTest extends TestCase
{
    use DeliveryFixtures, ProcurementFixtures, RefreshDatabase;

    /** A published shop with one live (or expired) story; returns [shop id, shop slug, story id]. */
    private function liveStory(bool $expired = false): array
    {
        $supplier = $this->fixtureId();
        $shop = $this->fixtureId();
        $media = $this->fixtureId();
        $story = $this->fixtureId();
        DB::table('suppliers')->insert(['id' => $supplier, 'name' => 'Test only', 'private_details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('shops')->insert([
            'id' => $shop, 'supplier_id' => $supplier, 'slug' => $shop, 'name' => 'Test shop',
            'description' => json_encode(['fr' => 'Test', 'en' => 'Test']), 'published' => true, 'status' => 'published',
            'published_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $owner = User::factory()->create();
        DB::table('media_assets')->insert([
            'id' => $media, 'uploaded_by' => $owner->id, 'disk' => 'local', 'object_key' => 'test/'.$media.'.webp',
            'mime' => 'image/webp', 'width' => 1080, 'height' => 1920, 'bytes' => 1000, 'sha256' => str_repeat('a', 64),
            'alt' => json_encode(['fr' => 'Image de test', 'en' => 'Test image']), 'is_public' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('shop_stories')->insert([
            'id' => $story, 'shop_id' => $shop, 'media_id' => $media, 'status' => 'published', 'created_by' => $owner->id,
            'published_at' => now()->subHour(), 'expires_at' => $expired ? now()->subMinute() : now()->addHours(23),
            'version' => 2, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$shop, $shop, $story];
    }

    public function test_stories_are_only_seen_by_the_members_who_follow_the_shop(): void
    {
        [, $slug, $story] = $this->liveStory();
        $this->getJson('/api/v1/stories')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.following', 0);

        $this->client();
        $this->getJson('/api/v1/stories')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/shops/'.$slug.'/stories')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.followed', false);
        $this->api('POST', 'stories/'.$story.'/react', ['reaction' => 'love'])->assertNotFound();

        $this->api('POST', 'shops/'.$slug.'/like')->assertOk();
        $this->getJson('/api/v1/stories')->assertOk()->assertJsonPath('data.0.stories.0.id', $story)->assertJsonPath('meta.following', 1);
        $this->getJson('/api/v1/shops/'.$slug.'/stories')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_member_reacts_changes_and_removes_a_reaction_and_sees_only_their_own(): void
    {
        [, $slug, $story] = $this->liveStory();
        $this->json('POST', '/api/v1/stories/'.$story.'/react', ['reaction' => 'love'])->assertUnauthorized();

        $first = $this->client();
        $this->api('POST', 'shops/'.$slug.'/like')->assertOk();
        $this->api('POST', 'stories/'.$story.'/react', ['reaction' => 'love'])->assertOk()->assertJsonPath('data.reaction', 'love');
        $this->api('POST', 'stories/'.$story.'/react', ['reaction' => 'fire'])->assertOk();
        $this->getJson('/api/v1/stories')->assertOk()->assertJsonPath('data.0.stories.0.my_reaction', 'fire');
        $this->assertSame(1, DB::table('story_reactions')->where('story_id', $story)->count());
        $this->api('POST', 'stories/'.$story.'/react', ['reaction' => 'thumbs'])->assertUnprocessable();
        $this->api('POST', 'stories/'.$story.'/react', [])->assertUnprocessable();

        $this->client();
        $this->api('POST', 'shops/'.$slug.'/like')->assertOk();
        $this->getJson('/api/v1/stories')->assertOk()->assertJsonPath('data.0.stories.0.my_reaction', null);

        $this->client($first);
        $this->api('POST', 'stories/'.$story.'/react', ['reaction' => null])->assertOk()->assertJsonPath('data.reaction', null);
        $this->assertSame(0, DB::table('story_reactions')->where('story_id', $story)->count());
    }

    public function test_no_reaction_on_an_expired_story_or_a_blocked_shops_story(): void
    {
        [, $old, $expired] = $this->liveStory(true);
        [, $slug, $story] = $this->liveStory();
        $this->client();
        $this->api('POST', 'shops/'.$old.'/like')->assertOk();
        $this->api('POST', 'shops/'.$slug.'/like')->assertOk();
        $this->api('POST', 'stories/'.$expired.'/react', ['reaction' => 'love'])->assertNotFound();
        $this->api('POST', 'shops/'.$slug.'/block')->assertOk()->assertJsonPath('data.blocked', true);
        $this->api('POST', 'stories/'.$story.'/react', ['reaction' => 'love'])->assertNotFound();
        $this->assertSame(0, DB::table('story_reactions')->count());
    }
}
