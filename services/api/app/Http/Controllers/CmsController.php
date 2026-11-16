<?php

namespace App\Http\Controllers;

use App\Domain\Content\Cms;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CmsController extends OperationalController
{
    /** Public: settings, page in force and active campaign; cached by the site until the revision changes. */
    public function showPage(Request $http, string $slug, Cms $cms)
    {
        $locale = $http->validate(['locale' => ['nullable', 'in:fr,en']])['locale'] ?? app()->getLocale();
        $data = app(ReadSnapshot::class)->run(fn () => $cms->render($slug, $locale));
        abort_if($data === null, 404);

        return response()
            ->json(['data' => $data])
            ->header('Cache-Control', 'public, max-age=60')
            ->header('Vary', 'X-Kora-Locale');
    }

    public function contact(Cms $cms)
    {
        return response()
            ->json(['data' => app(ReadSnapshot::class)->run(fn () => $cms->contact())])
            ->header('Cache-Control', 'public, max-age=60');
    }

    public function revision(Cms $cms)
    {
        return ['data' => $cms->revision()];
    }

    /** Draft preview for the team only; never indexed. */
    public function preview(string $token, Cms $cms)
    {
        $data = $cms->preview($token);
        abort_if($data === null, 404);

        return response()
            ->json(['data' => $data])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store');
    }

    public function documents(Request $http, Cms $cms)
    {
        $d = $http->validate(['kind' => ['nullable', 'in:settings,page']]);

        return app(ReadSnapshot::class)->run(function () use ($http, $d, $cms) {
            $q = DB::table('cms_documents')->orderBy('kind')->orderBy('slug')->orderBy('id');
            if (isset($d['kind'])) {
                $q->where('kind', $d['kind']);
            }

            return $this->page($q, $http, function ($doc) use ($cms) {
                $live = $cms->effective($doc->kind, $doc->slug);
                $latest = DB::table('cms_versions')->where('document_id', $doc->id)->max('number');

                return [
                    'id' => $doc->id,
                    'kind' => $doc->kind,
                    'slug' => $doc->slug,
                    'title' => json_decode($doc->title, true),
                    'live_number' => $live ? (int) $live->number : null,
                    'latest_number' => $latest ? (int) $latest : null,
                ];
            });
        });
    }

    public function versions(Request $http, string $id, Cms $cms)
    {
        Records::row('cms_documents', $id, false);

        return $this->page(
            DB::table('cms_versions')->where('document_id', $id)->orderByDesc('number'),
            $http,
            fn ($v) => $cms->versionDto($v),
        );
    }

    public function draft(Request $http, string $kind, string $slug, Cms $cms)
    {
        abort_if($kind === 'settings' && $slug !== 'site', 404);
        $data = $http->validate([
            'title' => ['nullable', 'array:fr,en'],
            'title.fr' => ['required_with:title', 'string', 'max:120'],
            'title.en' => ['required_with:title', 'string', 'max:120'],
            'content' => ['required', 'array'],
        ]);

        return $this->command(
            $http,
            "cms.draft:$kind:$slug",
            $data,
            fn ($d) => $cms->saveDraft($kind, $slug, $d, $http->user()),
            201,
        );
    }

    public function publish(Request $http, string $id, Cms $cms)
    {
        $data = $http->validate(['publish_at' => ['nullable', 'date']]);

        return $this->command($http, 'cms.publish:'.$id, $data, fn ($d) => $cms->publish($id, $d, $http->user()));
    }

    public function rollback(Request $http, string $id, Cms $cms)
    {
        return $this->command($http, 'cms.rollback:'.$id, [], fn () => $cms->rollback($id, $http->user()), 201);
    }

    public function campaigns(Request $http, Cms $cms)
    {
        return $this->page(
            DB::table('cms_campaigns')->orderByDesc('starts_at')->orderBy('id'),
            $http,
            fn ($c) => $cms->campaignDto($c),
        );
    }

    public function saveCampaign(Request $http, Cms $cms, ?string $id = null)
    {
        $data = $http->validate([
            'name' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date'],
            'priority' => ['required', 'integer', 'min:0', 'max:1000'],
            'locales' => ['required', 'array', 'min:1'],
            'locales.*' => ['in:fr,en', 'distinct'],
            'pages' => ['required', 'array', 'min:1', 'max:20'],
            'pages.*' => ['string', 'max:120', 'regex:/^(\*|[a-z0-9-]+)$/', 'distinct'],
            'theme' => ['present', 'array'],
            'banner' => ['required', 'array:fr,en'],
            'banner.*' => ['nullable', 'string', 'max:200'],
            'product_ids' => ['nullable', 'array', 'max:24'],
            'product_ids.*' => ['uuid', 'distinct', 'exists:products,id'],
            'delivery_offer_id' => ['nullable', 'uuid', 'exists:delivery_offers,id'],
            'version' => [$id ? 'required' : 'nullable', 'integer'],
        ]);

        return $this->command(
            $http,
            'cms.campaign:'.($id ?? 'new'),
            $data,
            fn ($d) => $cms->saveCampaign($id, $d, $http->user()),
            $id ? 200 : 201,
        );
    }

    public function campaignStatus(Request $http, string $id, Cms $cms)
    {
        $data = $http->validate(['status' => ['required', 'in:active,paused'], 'version' => ['required', 'integer']]);

        return $this->command(
            $http,
            'cms.campaign_status:'.$id,
            $data,
            fn ($d) => $cms->campaignStatus($id, $d, $http->user()),
        );
    }

    /** Public: the version in force and the earlier published ones, so a customer can read what they accepted. */
    public function legal(string $kind, Cms $cms)
    {
        abort_unless(in_array($kind, Cms::LEGAL, true), 404);
        $current = $cms->currentLegal($kind);
        abort_if($current === null, 404);
        $history = DB::table('legal_versions')
            ->where('kind', $kind)
            ->where('status', 'published')
            ->where('effective_at', '<=', now())
            ->orderByDesc('number')
            ->limit(20)
            ->get(['id', 'number', 'effective_at']);

        return [
            'data' => $cms->legalDto($current, false),
            'meta' => [
                'history' => $history->map(
                    fn ($v) => [
                        'id' => $v->id,
                        'number' => (int) $v->number,
                        'effective_at' => Records::iso($v->effective_at),
                    ],
                ),
            ],
        ];
    }

    public function legalVersion(string $id, Cms $cms)
    {
        $v = Records::row('legal_versions', $id, false);
        abort_unless($v->status === 'published', 404);

        return ['data' => $cms->legalDto($v, false)];
    }

    public function legalDrafts(Request $http, Cms $cms)
    {
        return $this->page(
            DB::table('legal_versions')->orderBy('kind')->orderByDesc('number'),
            $http,
            fn ($v) => $cms->legalDto($v, true),
        );
    }

    public function draftLegal(Request $http, string $kind, Cms $cms)
    {
        abort_unless(in_array($kind, Cms::LEGAL, true), 404);
        $data = $http->validate([
            'body' => ['required', 'array:fr,en'],
            'body.*' => ['nullable', 'string', 'max:100000'],
            'effective_at' => ['required', 'date'],
            'requires_acceptance' => ['required', 'boolean'],
        ]);

        return $this->command(
            $http,
            'legal.draft:'.$kind,
            $data,
            fn ($d) => $cms->draftLegal($kind, $d, $http->user()),
            201,
        );
    }

    public function publishLegal(Request $http, string $id, Cms $cms)
    {
        return $this->command($http, 'legal.publish:'.$id, [], fn () => $cms->publishLegal($id, $http->user()));
    }

    public function accept(Request $http, Cms $cms)
    {
        $data = $http->validate([
            'version_id' => ['required', 'uuid'],
            'context' => ['required', 'in:signup,order,update'],
            'order_id' => ['nullable', 'uuid', 'required_if:context,order'],
        ]);

        return response()->json(['data' => DB::transaction(fn () => $cms->accept($http->user(), $data), 3)], 201);
    }

    public function pending(Request $http, Cms $cms)
    {
        return ['data' => $cms->pending($http->user())];
    }
}
