<?php

namespace App\Domain\Content;

use App\Domain\Media\MediaLibrary;
use App\Domain\Procurement\Records;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * CMS (§27): site settings and block pages as versioned documents, an event calendar of campaigns resolved by
 * explicit priority, legal conditions with versions and recorded acceptances, and a revision number the site
 * uses to refresh its caches. What is live at a moment is computed, so a campaign's end or a scheduled version
 * takes effect by itself and nothing is deleted.
 */
final class Cms
{
    public const LEGAL = ['terms_of_use', 'terms_of_sale', 'privacy', 'returns'];

    public static function fail(string $field, string $code): never
    {
        throw ValidationException::withMessages([$field => __('cms.'.$code)]);
    }

    private function validate(string $kind, array $content): array
    {
        $validator = app(CmsValidator::class);

        return $kind === 'settings' ? $validator->settings($content) : $validator->page($content);
    }

    public function saveDraft(string $kind, string $slug, array $data, User $actor): array
    {
        $content = $this->validate($kind, $data['content']);
        $document = DB::table('cms_documents')->where('kind', $kind)->where('slug', $slug)->lockForUpdate()->first();
        if (! $document) {
            $id = Records::id();
            DB::table('cms_documents')->insert([
                'id' => $id,
                'kind' => $kind,
                'slug' => $slug,
                'title' => Records::json($data['title'] ?? ['fr' => $slug, 'en' => $slug]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $document = Records::row('cms_documents', $id);
        } elseif (isset($data['title'])) {
            DB::table('cms_documents')
                ->where('id', $document->id)
                ->update(['title' => Records::json($data['title']), 'updated_at' => now()]);
        }
        $versionId = Records::id();
        DB::table('cms_versions')->insert([
            'id' => $versionId,
            'document_id' => $document->id,
            'number' => (int) DB::table('cms_versions')->where('document_id', $document->id)->max('number') + 1,
            'content' => Records::json($content),
            'author_id' => $actor->id,
            'preview_token' => Str::random(48),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Records::audit($actor, 'cms.draft_saved', $versionId, ['kind' => $kind, 'slug' => $slug]);

        return $this->versionDto(Records::row('cms_versions', $versionId, false));
    }

    /** Publish now, or schedule for a later time (Africa/Douala given with its offset). */
    public function publish(string $versionId, array $data, User $actor): array
    {
        $version = Records::row('cms_versions', $versionId);
        if ($version->status !== 'draft') {
            self::fail('version', 'VERSION_STATE');
        }
        $document = Records::row('cms_documents', $version->document_id, false);
        $content = $this->validate($document->kind, json_decode($version->content, true));
        app(CmsValidator::class)->publicMedia($content);
        $at = isset($data['publish_at']) ? CarbonImmutable::parse($data['publish_at'])->utc() : null;
        $scheduled = $at && $at->isFuture();
        DB::table('cms_versions')
            ->where('id', $versionId)
            ->update([
                'status' => $scheduled ? 'scheduled' : 'published',
                'publish_at' => $scheduled ? $at : now(),
                'publisher_id' => $actor->id,
                'published_at' => $scheduled ? null : now(),
                'updated_at' => now(),
            ]);
        $this->bump();
        Records::audit($actor, $scheduled ? 'cms.scheduled' : 'cms.published', $versionId, [
            'publish_at' => $at?->toIso8601String(),
        ]);

        return $this->versionDto(Records::row('cms_versions', $versionId, false));
    }

    /** Back to an earlier version: its content becomes a new version published now; history stays intact. */
    public function rollback(string $versionId, User $actor): array
    {
        $old = Records::row('cms_versions', $versionId, false);
        $document = Records::row('cms_documents', $old->document_id, false);
        $draft = $this->saveDraft(
            $document->kind,
            $document->slug,
            ['content' => json_decode($old->content, true)],
            $actor,
        );

        return $this->publish($draft['id'], [], $actor);
    }

    /** The version in force at a moment: the latest published or scheduled one whose time has come. */
    public function effective(string $kind, string $slug, ?CarbonImmutable $at = null): ?object
    {
        $at ??= CarbonImmutable::now();

        return DB::table('cms_versions AS v')
            ->join('cms_documents AS d', 'd.id', '=', 'v.document_id')
            ->where('d.kind', $kind)
            ->where('d.slug', $slug)
            ->whereIn('v.status', ['published', 'scheduled'])
            ->where('v.publish_at', '<=', $at)
            ->orderByDesc('v.publish_at')
            ->orderByDesc('v.number')
            ->first(['v.*', 'd.slug', 'd.title']);
    }

    public function preview(string $token): ?array
    {
        $version = DB::table('cms_versions')->where('preview_token', $token)->first();

        return $version
            ? [
                'content' => json_decode($version->content, true),
                'status' => $version->status,
                'number' => (int) $version->number,
            ]
            : null;
    }

    public function saveCampaign(?string $id, array $data, User $actor): array
    {
        $settings = $this->effective('settings', 'site');
        $base = $settings ? json_decode($settings->content, true)['theme'] ?? [] : [];
        app(CmsValidator::class)->campaignTheme($data['theme'], $base);
        if (CarbonImmutable::parse($data['ends_at'])->lessThanOrEqualTo(CarbonImmutable::parse($data['starts_at']))) {
            self::fail('ends_at', 'PERIOD_INVALID');
        }
        foreach ($data['locales'] as $locale) {
            if (blank($data['banner'][$locale] ?? null) || preg_match('/[<>]/', (string) $data['banner'][$locale])) {
                self::fail('banner.'.$locale, 'TEXT_REQUIRED');
            }
        }
        $values = [
            'name' => $data['name'],
            'starts_at' => CarbonImmutable::parse($data['starts_at'])->utc(),
            'ends_at' => CarbonImmutable::parse($data['ends_at'])->utc(),
            'priority' => $data['priority'],
            'locales' => Records::json($data['locales']),
            'pages' => Records::json($data['pages']),
            'theme' => Records::json($data['theme']),
            'banner' => Records::json($data['banner']),
            'product_ids' => Records::json($data['product_ids'] ?? []),
            'delivery_offer_id' => $data['delivery_offer_id'] ?? null,
            'updated_at' => now(),
        ];
        if ($id) {
            $campaign = Records::row('cms_campaigns', $id);
            abort_unless((int) $campaign->version === $data['version'], 409);
            DB::table('cms_campaigns')
                ->where('id', $id)
                ->update([...$values, 'version' => $campaign->version + 1]);
        } else {
            $id = Records::id();
            DB::table('cms_campaigns')->insert([
                ...$values,
                'id' => $id,
                'created_by' => $actor->id,
                'created_at' => now(),
            ]);
        }
        $this->bump();
        Records::audit($actor, 'cms.campaign_saved', $id, ['priority' => $data['priority']]);

        return $this->campaignDto(Records::row('cms_campaigns', $id, false));
    }

    public function campaignStatus(string $id, array $data, User $actor): array
    {
        $campaign = Records::row('cms_campaigns', $id);
        abort_unless((int) $campaign->version === $data['version'], 409);
        DB::table('cms_campaigns')
            ->where('id', $id)
            ->update(['status' => $data['status'], 'version' => $campaign->version + 1, 'updated_at' => now()]);
        $this->bump();
        Records::audit($actor, 'cms.campaign_'.$data['status'], $id, []);

        return $this->campaignDto(Records::row('cms_campaigns', $id, false));
    }

    /**
     * Campaign in force for a page and language: active, within its Douala period, targeting the page (or all),
     * highest priority first, then the most recent start, then id. Its end restores the base configuration.
     */
    public function activeCampaign(string $page, string $locale, ?CarbonImmutable $at = null): ?object
    {
        $at ??= CarbonImmutable::now();

        return DB::table('cms_campaigns')
            ->where('status', 'active')
            ->where('starts_at', '<=', $at)
            ->where('ends_at', '>', $at)
            ->orderByDesc('priority')
            ->orderByDesc('starts_at')
            ->orderBy('id')
            ->get()
            ->first(
                fn ($c) => (in_array($page, json_decode($c->pages, true), true) ||
                    in_array('*', json_decode($c->pages, true), true)) &&
                    in_array($locale, json_decode($c->locales, true), true),
            );
    }

    /** Public: settings and page in force, campaign overrides applied (theme tokens merged, banner, selection). */
    public function render(string $page, string $locale): ?array
    {
        $settings = $this->effective('settings', 'site');
        $document = $this->effective('page', $page);
        if (! $document && $page !== 'home') {
            return null;
        }
        $site = $settings ? json_decode($settings->content, true) : null;
        $campaign = $this->activeCampaign($page, $locale);
        $theme = $site['theme'] ?? null;
        if ($campaign && $theme) {
            foreach (json_decode($campaign->theme, true) as $mode => $tokens) {
                $theme[$mode] = [...$theme[$mode] ?? [], ...$tokens];
            }
        }

        return [
            'revision' => $this->revision(),
            'settings' => $site ? [...$site, 'theme' => $theme] : null,
            'page' => $document
                ? [
                    'slug' => $page,
                    'version' => (int) $document->number,
                    'title' => json_decode($document->title, true),
                    'blocks' => $this->visibleBlocks(json_decode($document->content, true)['blocks'] ?? []),
                ]
                : null,
            'media' => $this->mediaPaths([$site, $document ? json_decode($document->content, true) : null]),
            'campaign' => $campaign
                ? [
                    'id' => $campaign->id,
                    'name' => $campaign->name,
                    'banner' => json_decode($campaign->banner, true)[$locale] ?? null,
                    'product_ids' => json_decode($campaign->product_ids, true),
                    'delivery_offer_id' => $campaign->delivery_offer_id,
                    'ends_at' => Records::iso($campaign->ends_at),
                ]
                : null,
        ];
    }

    /**
     * Selections keep only what is still published (a withdrawn product disappears without republishing the page);
     * a block left empty is not shown.
     */
    private function visibleBlocks(array $blocks): array
    {
        foreach ($blocks as $i => $block) {
            foreach (['product_ids' => 'products', 'shop_ids' => 'shops'] as $key => $table) {
                if (isset($block[$key])) {
                    $live = DB::table($table)
                        ->whereIn('id', $block[$key])
                        ->where('status', 'published')
                        ->pluck('id')
                        ->all();
                    $blocks[$i][$key] = array_values(array_intersect($block[$key], $live));
                    if (! $blocks[$i][$key]) {
                        unset($blocks[$i]);

                        continue 2;
                    }
                }
            }
        }

        return array_values($blocks);
    }

    /** Public address of each media still public; a media revoked after publication is simply absent. */
    private function mediaPaths(array $contents): array
    {
        $ids = [];
        array_walk_recursive($contents, function ($value, $key) use (&$ids) {
            if (is_string($key) && str_ends_with($key, 'media_id') && is_string($value)) {
                $ids[] = $value;
            }
        });

        return DB::table('media_assets')
            ->whereIn('id', array_unique($ids))
            ->where('is_public', true)
            ->where('revoked', false)
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => app(MediaLibrary::class)->publicPath($id)])
            ->all();
    }

    /**
     * Public contact shortcuts for the storefront's floating buttons: the WhatsApp link set in the site settings
     * (social network "whatsapp", edited in the back office), otherwise the configured business number; null when
     * neither exists, so no button is shown.
     */
    public function contact(): array
    {
        $settings = $this->effective('settings', 'site');
        $site = $settings ? json_decode($settings->content, true) : [];
        $whatsapp = null;
        foreach ($site['social'] ?? [] as $item) {
            if (strtolower((string) ($item['network'] ?? '')) === 'whatsapp' && str_starts_with((string) ($item['url'] ?? ''), 'https://')) {
                $whatsapp = $item['url'];
                break;
            }
        }
        $number = preg_replace('/\D+/', '', (string) config('kora.whatsapp_number'));
        if ($whatsapp === null && $number !== '') {
            $whatsapp = 'https://wa.me/'.$number;
        }

        return [
            'whatsapp' => $whatsapp,
            'phone' => ($site['company']['phone'] ?? '') ?: null,
            'email' => ($site['company']['email'] ?? '') ?: null,
        ];
    }

    public function revision(): array
    {
        $now = now();
        $next = collect([
            DB::table('cms_versions')->where('status', 'scheduled')->where('publish_at', '>', $now)->min('publish_at'),
            DB::table('cms_campaigns')->where('status', 'active')->where('starts_at', '>', $now)->min('starts_at'),
            DB::table('cms_campaigns')->where('status', 'active')->where('ends_at', '>', $now)->min('ends_at'),
            DB::table('legal_versions')
                ->where('status', 'published')
                ->where('effective_at', '>', $now)
                ->min('effective_at'),
        ])
            ->filter()
            ->sort()
            ->first();

        return [
            'revision' => (int) DB::table('cms_revisions')->where('id', 1)->value('revision'),
            // Caches refresh at this time even without a new publication (scheduled version, campaign start or end).
            'next_change_at' => $next ? Records::iso($next) : null,
        ];
    }

    private function bump(): void
    {
        DB::table('cms_revisions')
            ->where('id', 1)
            ->increment('revision', 1, ['updated_at' => now()]);
    }

    public function draftLegal(string $kind, array $data, User $actor): array
    {
        $id = Records::id();
        DB::table('legal_versions')->insert([
            'id' => $id,
            'kind' => $kind,
            'number' => (int) DB::table('legal_versions')->where('kind', $kind)->lockForUpdate()->pluck('number')->max() + 1,
            'body' => Records::json($data['body']),
            'effective_at' => CarbonImmutable::parse($data['effective_at'])->utc(),
            'requires_acceptance' => $data['requires_acceptance'],
            'author_id' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->legalDto(Records::row('legal_versions', $id, false), true);
    }

    /**
     * Sensitive: both languages approved; when the team enables it, the publisher must not be the author.
     * A new version never rewrites acceptances already given.
     */
    public function publishLegal(string $id, User $actor): array
    {
        $version = Records::row('legal_versions', $id);
        if ($version->status !== 'draft') {
            self::fail('version', 'VERSION_STATE');
        }
        $body = json_decode($version->body, true);
        foreach (['fr', 'en'] as $locale) {
            if (blank($body[$locale] ?? null)) {
                self::fail('body.'.$locale, 'TEXT_REQUIRED');
            }
        }
        if (config('kora.cms_four_eyes') && (int) $version->author_id === $actor->id) {
            self::fail('version', 'SECOND_PERSON_REQUIRED');
        }
        DB::table('legal_versions')
            ->where('id', $id)
            ->update([
                'status' => 'published',
                'publisher_id' => $actor->id,
                'published_at' => now(),
                'updated_at' => now(),
            ]);
        $this->bump();
        Records::audit($actor, 'legal.published', $id, ['kind' => $version->kind, 'number' => (int) $version->number]);

        return $this->legalDto(Records::row('legal_versions', $id, false), true);
    }

    public function currentLegal(string $kind, ?CarbonImmutable $at = null): ?object
    {
        return DB::table('legal_versions')
            ->where('kind', $kind)
            ->where('status', 'published')
            ->where('effective_at', '<=', $at ?? CarbonImmutable::now())
            ->orderByDesc('effective_at')
            ->orderByDesc('number')
            ->first();
    }

    /** Explicit acceptance, kept with its context; an order acceptance must concern the customer's own order. */
    public function accept(User $user, array $data): array
    {
        $version = Records::row('legal_versions', $data['version_id'], false);
        if ($version->status !== 'published') {
            self::fail('version_id', 'VERSION_STATE');
        }
        if (isset($data['order_id'])) {
            abort_unless(
                DB::table('orders')->where('id', $data['order_id'])->where('user_id', $user->id)->exists(),
                404,
            );
        }
        // Accepting the same version twice in the same context records nothing new.
        $given = DB::table('legal_acceptances')
            ->where('user_id', $user->id)
            ->where('version_id', $version->id)
            ->where('context', $data['context']);
        if (
            isset($data['order_id'])
                ? $given->where('order_id', $data['order_id'])->exists()
                : $given->whereNull('order_id')->exists()
        ) {
            return ['accepted' => true, 'kind' => $version->kind, 'number' => (int) $version->number];
        }
        DB::table('legal_acceptances')->insertOrIgnore([
            'id' => Records::id(),
            'user_id' => $user->id,
            'version_id' => $version->id,
            'context' => $data['context'],
            'order_id' => $data['order_id'] ?? null,
            'accepted_at' => now(),
        ]);

        return ['accepted' => true, 'kind' => $version->kind, 'number' => (int) $version->number];
    }

    /** Current versions that ask for a new explicit acceptance this customer has not given yet. */
    public function pending(User $user): array
    {
        return collect(self::LEGAL)
            ->map(fn ($kind) => $this->currentLegal($kind))
            ->filter(
                fn ($v) => $v &&
                    $v->requires_acceptance &&
                    ! DB::table('legal_acceptances')->where('user_id', $user->id)->where('version_id', $v->id)->exists(),
            )
            ->map(fn ($v) => $this->legalDto($v, false))
            ->values()
            ->all();
    }

    public function versionDto(object $v): array
    {
        return [
            'id' => $v->id,
            'number' => (int) $v->number,
            'status' => $v->status,
            'content' => json_decode($v->content, true),
            'publish_at' => Records::iso($v->publish_at),
            'published_at' => Records::iso($v->published_at),
            'author_id' => (int) $v->author_id,
            'preview_token' => $v->preview_token,
            'created_at' => Records::iso($v->created_at),
        ];
    }

    public function campaignDto(object $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'starts_at' => Records::iso($c->starts_at),
            'ends_at' => Records::iso($c->ends_at),
            'priority' => (int) $c->priority,
            'locales' => json_decode($c->locales, true),
            'pages' => json_decode($c->pages, true),
            'theme' => json_decode($c->theme, true),
            'banner' => json_decode($c->banner, true),
            'product_ids' => json_decode($c->product_ids, true),
            'delivery_offer_id' => $c->delivery_offer_id,
            'status' => $c->status,
            'version' => (int) $c->version,
        ];
    }

    public function legalDto(object $v, bool $staff): array
    {
        return [
            'id' => $v->id,
            'kind' => $v->kind,
            'number' => (int) $v->number,
            'body' => json_decode($v->body, true),
            'effective_at' => Records::iso($v->effective_at),
            'requires_acceptance' => (bool) $v->requires_acceptance,
            ...$staff
                ? [
                    'status' => $v->status,
                    'author_id' => (int) $v->author_id,
                    'published_at' => Records::iso($v->published_at),
                ]
                : [],
        ];
    }
}
