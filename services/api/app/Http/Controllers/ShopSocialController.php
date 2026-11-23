<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Procurement\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shops as accounts: stories (a photo, a line and an optional product, seen for 24 hours once the KORA team
 * publishes them, and only by the members who follow the shop), a member's reaction to a story, reporting a shop or one of its products to the team, and blocking a shop (its stories and
 * products stop being shown to that member). The KORA team writes, publishes and archives stories and reviews the
 * reports from the operations routes.
 */
final class ShopSocialController extends Controller
{
    public const REASONS = ['counterfeit', 'misleading', 'behaviour', 'prohibited', 'other'];

    /** Quick reactions under a story, in display order. */
    public const REACTIONS = ['love', 'adore', 'fire', 'clap', 'wow', 'haha'];

    private function blocked(): array
    {
        $user = Auth::guard('sanctum')->user();

        return $user ? DB::table('shop_blocks')->where('user_id', $user->id)->pluck('shop_id')->all() : [];
    }

    /** The signed-in member's reaction to each of these stories, by story id. */
    private function reactions(array $ids): array
    {
        $user = Auth::guard('sanctum')->user();

        return $user && $ids ? DB::table('story_reactions')->where('user_id', $user->id)->whereIn('story_id', $ids)->pluck('reaction', 'story_id')->all() : [];
    }

    private function storyDto(object $s, array $mine = []): array
    {
        $locale = app()->getLocale();
        $caption = $s->caption ? json_decode($s->caption, true) : null;

        return [
            'id' => $s->id,
            'image' => '/api/v1/media/'.$s->media_id.'/public',
            'caption' => $caption ? ($caption[$locale] ?? $caption['fr'] ?? null) : null,
            'offer' => $s->offer_id ? app(PublicCatalogue::class)->offer($s->offer_id, $locale) : null,
            'published_at' => Records::iso($s->published_at),
            'expires_at' => Records::iso($s->expires_at),
            'my_reaction' => $mine[$s->id] ?? null,
        ];
    }

    private function live()
    {
        return DB::table('shop_stories')
            ->join('shops', 'shops.id', '=', 'shop_stories.shop_id')
            ->join('media_assets', 'media_assets.id', '=', 'shop_stories.media_id')
            ->where('shop_stories.status', 'published')
            ->where('shop_stories.expires_at', '>', now())
            ->where('shops.status', 'published')
            ->where('media_assets.is_public', true)
            ->where('media_assets.revoked', false)
            ->select('shop_stories.*', 'shops.slug as shop_slug', 'shops.name as shop_name', 'shops.avatar_path as shop_avatar');
    }

    /** The shops the signed-in member follows (none for a visitor), minus the ones they blocked. */
    private function followed(): array
    {
        $user = Auth::guard('sanctum')->user();

        return $user ? DB::table('shop_likes')->where('user_id', $user->id)->whereNotIn('shop_id', $this->blocked())->pluck('shop_id')->all() : [];
    }

    /**
     * The stories of the shops the member follows, newest shop first. A visitor or a member who follows nobody gets
     * an empty list (meta.following = 0): stories are only seen by followers.
     */
    public function index()
    {
        $followed = $this->followed();
        $rows = $this->live()->whereIn('shop_stories.shop_id', $followed)->orderBy('shop_stories.published_at')->get();
        $mine = $this->reactions($rows->pluck('id')->all());
        $groups = $rows->groupBy('shop_id')->map(fn ($list) => [
            'shop' => ['id' => $list[0]->shop_id, 'slug' => $list[0]->shop_slug, 'name' => $list[0]->shop_name, 'avatar' => $list[0]->shop_avatar],
            'followed' => true,
            'latest_at' => Records::iso($list->max('published_at')),
            'stories' => $list->map(fn ($s) => $this->storyDto($s, $mine))->values(),
        ])->sortByDesc(fn ($g) => $g['latest_at'])->values();

        return ['data' => $groups, 'meta' => ['following' => count($followed)]];
    }

    /** A shop's current stories, for its followers only (meta.followed says why the list may be empty). */
    public function shop(string $slug)
    {
        $shop = DB::table('shops')->where('slug', $slug)->where('status', 'published')->first();
        abort_unless($shop, 404);
        if (in_array($shop->id, $this->blocked(), true)) {
            return ['data' => [], 'meta' => ['blocked' => true, 'followed' => false]];
        }
        if (! in_array($shop->id, $this->followed(), true)) {
            return ['data' => [], 'meta' => ['blocked' => false, 'followed' => false]];
        }

        $rows = $this->live()->where('shop_stories.shop_id', $shop->id)->orderBy('shop_stories.published_at')->get();
        $mine = $this->reactions($rows->pluck('id')->all());

        return ['data' => $rows->map(fn ($s) => $this->storyDto($s, $mine))->values(), 'meta' => ['blocked' => false, 'followed' => true]];
    }
    /** React to a live story of a followed shop, change the reaction, or remove it (reaction null). */
    public function react(Request $http, string $id)
    {
        $d = $http->validate(['reaction' => ['present', 'nullable', 'in:'.implode(',', self::REACTIONS)]]);
        $story = $this->live()->where('shop_stories.id', $id)->whereIn('shop_stories.shop_id', $this->followed())->first();
        abort_unless($story, 404);
        $key = ['story_id' => $story->id, 'user_id' => $http->user()->id];
        if ($d['reaction'] === null) {
            DB::table('story_reactions')->where($key)->delete();
        } else {
            DB::table('story_reactions')->upsert([[...$key, 'reaction' => $d['reaction'], 'created_at' => now(), 'updated_at' => now()]], ['story_id', 'user_id'], ['reaction', 'updated_at']);
        }

        return ['data' => ['story_id' => $story->id, 'reaction' => $d['reaction']]];
    }

    /** Report a shop (or one of its products): one open report per member, shop and product. */
    public function report(Request $http, string $slug)
    {
        $shop = DB::table('shops')->where('slug', $slug)->where('status', 'published')->first();
        abort_unless($shop, 404);
        $d = $http->validate([
            'reason' => ['required', 'in:'.implode(',', self::REASONS)],
            'details' => ['nullable', 'string', 'max:2000'],
            'offer_id' => ['nullable', 'uuid'],
        ]);
        $open = DB::table('shop_reports')->where('user_id', $http->user()->id)->where('shop_id', $shop->id)
            ->where('offer_id', $d['offer_id'] ?? null)->where('status', '!=', 'closed')->first();
        if ($open) {
            return ['data' => ['id' => $open->id, 'status' => $open->status, 'already' => true]];
        }
        $id = (string) Str::uuid();
        DB::table('shop_reports')->insert([
            'id' => $id, 'shop_id' => $shop->id, 'offer_id' => $d['offer_id'] ?? null, 'user_id' => $http->user()->id,
            'reason' => $d['reason'], 'details' => $d['details'] ?? null, 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id, 'status' => 'open', 'already' => false]], 201);
    }

    public function blockedShops(Request $http)
    {
        return ['data' => ['shops' => DB::table('shop_blocks')->join('shops', 'shops.id', '=', 'shop_blocks.shop_id')
            ->where('shop_blocks.user_id', $http->user()->id)->orderBy('shops.name')->get(['shops.id', 'shops.slug', 'shops.name', 'shops.avatar_path as avatar'])]];
    }

    /** Block a shop, or unblock it when it is already blocked. Blocking also removes the like. */
    public function toggleBlock(Request $http, string $slug)
    {
        $shop = DB::table('shops')->where('slug', $slug)->where('status', 'published')->first();
        abort_unless($shop, 404);
        $user = $http->user()->id;

        return ['data' => DB::transaction(function () use ($shop, $user) {
            $gone = DB::table('shop_blocks')->where('user_id', $user)->where('shop_id', $shop->id)->delete();
            if (! $gone) {
                DB::table('shop_blocks')->insert(['user_id' => $user, 'shop_id' => $shop->id, 'created_at' => now()]);
                DB::table('shop_likes')->where('user_id', $user)->where('shop_id', $shop->id)->delete();
            }

            return ['blocked' => ! $gone, 'shop_id' => $shop->id, 'slug' => $shop->slug];
        })];
    }

    // ---- KORA team (operations) ----

    public function manage(Request $http)
    {
        $d = $http->validate(['status' => ['nullable', 'in:draft,published,archived']]);

        return ['data' => DB::table('shop_stories')->join('shops', 'shops.id', '=', 'shop_stories.shop_id')
            ->when($d['status'] ?? null, fn ($q, $s) => $q->where('shop_stories.status', $s))
            ->orderByDesc('shop_stories.created_at')->limit(200)
            ->get(['shop_stories.*', 'shops.name as shop_name'])
            ->map(fn ($s) => [...$this->storyDto($s), 'shop' => $s->shop_name, 'shop_id' => $s->shop_id, 'status' => $s->status, 'version' => (int) $s->version, 'caption_all' => $s->caption ? json_decode($s->caption, true) : null])];
    }

    public function create(Request $http)
    {
        $d = $http->validate([
            'shop_id' => ['required', 'uuid', 'exists:shops,id'],
            'media_id' => ['required', 'uuid', 'exists:media_assets,id'],
            'caption' => ['nullable', 'array:fr,en'],
            'caption.fr' => ['nullable', 'string', 'max:200'],
            'caption.en' => ['nullable', 'string', 'max:200'],
            'offer_id' => ['nullable', 'uuid', 'exists:offers,id'],
        ]);
        $id = (string) Str::uuid();
        DB::table('shop_stories')->insert([
            'id' => $id, 'shop_id' => $d['shop_id'], 'media_id' => $d['media_id'], 'caption' => isset($d['caption']) ? json_encode($d['caption']) : null,
            'offer_id' => $d['offer_id'] ?? null, 'status' => 'draft', 'created_by' => $http->user()->id, 'version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['id' => $id, 'status' => 'draft']], 201);
    }

    /** Publish now for 24 hours (the photo becomes public), or archive. */
    public function setStatus(Request $http, string $id)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1'], 'status' => ['required', 'in:published,archived']]);
        $story = DB::table('shop_stories')->where('id', $id)->lockForUpdate()->first();
        abort_unless($story, 404);
        abort_unless((int) $story->version === $d['version'], 409);
        DB::transaction(function () use ($story, $d) {
            if ($d['status'] === 'published') {
                DB::table('media_assets')->where('id', $story->media_id)->update(['is_public' => true, 'rights_confirmed_at' => now(), 'updated_at' => now()]);
            }
            DB::table('shop_stories')->where('id', $story->id)->update([
                'status' => $d['status'],
                'published_at' => $d['status'] === 'published' ? now() : $story->published_at,
                'expires_at' => $d['status'] === 'published' ? now()->addDay() : $story->expires_at,
                'version' => $story->version + 1,
                'updated_at' => now(),
            ]);
        });

        return ['data' => ['id' => $id, 'status' => $d['status'], 'version' => $story->version + 1]];
    }

    public function reports(Request $http)
    {
        $d = $http->validate(['status' => ['nullable', 'in:open,reviewing,closed']]);

        return ['data' => DB::table('shop_reports')->join('shops', 'shops.id', '=', 'shop_reports.shop_id')
            ->when($d['status'] ?? null, fn ($q, $s) => $q->where('shop_reports.status', $s))
            ->orderByDesc('shop_reports.created_at')->limit(200)
            ->get(['shop_reports.id', 'shop_reports.reason', 'shop_reports.details', 'shop_reports.offer_id', 'shop_reports.status', 'shop_reports.created_at', 'shops.name as shop', 'shops.slug as shop_slug'])];
    }

    public function reportStatus(Request $http, string $id)
    {
        $d = $http->validate(['status' => ['required', 'in:reviewing,closed']]);
        abort_unless(DB::table('shop_reports')->where('id', $id)->update(['status' => $d['status'], 'updated_at' => now()]), 404);

        return ['data' => ['id' => $id, 'status' => $d['status']]];
    }
}
