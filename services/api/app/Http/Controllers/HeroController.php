<?php

namespace App\Http\Controllers;

use App\Domain\Content\SceneContent;
use App\Domain\Content\ScenePublisher;
use App\Models\HeroScene;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class HeroController extends Controller
{
    public function published(SceneContent $content)
    {
        $now = CarbonImmutable::now('UTC');
        $scenes = HeroScene::whereNotNull('publication')
            ->where('paused', false)
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->filter(function ($scene) use ($now) {
                $data = $scene->publication;

                return (empty($data['starts_at']) || CarbonImmutable::parse($data['starts_at'])->lte($now)) &&
                    (empty($data['ends_at']) || CarbonImmutable::parse($data['ends_at'])->gt($now));
            })
            ->sortByDesc(fn ($scene) => $scene->publication['priority'] ?? 0)
            ->map(fn ($scene) => $content->publicScene($scene->id, $scene->publication, app()->getLocale()))
            ->filter()
            ->values();

        return ['data' => $scenes, 'meta' => ['demo' => config('kora.demo')]];
    }

    public function index()
    {
        return ['data' => HeroScene::orderBy('position')->orderBy('id')->paginate(50)];
    }

    public function store(Request $request, ScenePublisher $publisher)
    {
        $data = $this->data($request);

        return response()->json(['data' => $publisher->create($data, $request->user())], 201);
    }

    public function update(Request $request, string $id, ScenePublisher $publisher)
    {
        $data = $this->data($request, true);

        return ['data' => $publisher->change($id, $data['version'], 'update', $data, $request->user())];
    }

    public function publish(Request $request, string $id, ScenePublisher $publisher)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ['data' => $publisher->change($id, $data['version'], 'publish', [], $request->user())];
    }

    public function pause(Request $request, string $id, ScenePublisher $publisher)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'paused' => ['required', 'boolean'],
        ]);

        return ['data' => $publisher->change($id, $data['version'], 'pause', $data, $request->user())];
    }

    public function restore(Request $request, string $id, ScenePublisher $publisher)
    {
        $data = $request->validate([
            'version' => ['required', 'integer', 'min:1'],
            'revision' => ['required', 'integer', 'min:1'],
        ]);

        return ['data' => $publisher->change($id, $data['version'], 'restore', $data, $request->user())];
    }

    public function reorder(Request $request, ScenePublisher $publisher)
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*' => ['array:id,version'],
            'items.*.id' => ['required', 'uuid', 'distinct'],
            'items.*.version' => ['required', 'integer', 'min:1'],
        ]);
        $publisher->reorder($data['items'], $request->user());

        return response()->noContent();
    }

    public function preview(string $id, SceneContent $content)
    {
        $scene = HeroScene::findOrFail($id);

        return response()
            ->json(['data' => $content->publicScene($id, $scene->draft, app()->getLocale())])
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function revisions(string $id)
    {
        HeroScene::findOrFail($id);

        return [
            'data' => DB::table('hero_revisions')
                ->where('hero_scene_id', $id)
                ->orderByDesc('version')
                ->get(['version', 'created_at'])
                ->map(
                    fn ($revision) => [
                        'version' => $revision->version,
                        'created_at' => CarbonImmutable::parse($revision->created_at, 'UTC')->toIso8601String(),
                    ],
                ),
        ];
    }

    private function data(Request $request, bool $version = false): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'draft' => [
                'required',
                'array:kind,target_id,category,title,description,label,cta,alt,media,mobile_media,decor,preset,hold_ms,autoplay,starts_at,ends_at,priority',
            ],
        ];
        if ($version) {
            $rules['version'] = ['required', 'integer', 'min:1'];
        }
        foreach (SceneContent::rules() as $key => $rule) {
            $rules['draft.'.$key] = $rule;
        }
        $data = $request->validate($rules);
        foreach (['title', 'description', 'label', 'cta', 'alt'] as $field) {
            foreach (['fr', 'en'] as $locale) {
                $data['draft'][$field][$locale] = $data['draft'][$field][$locale] ?? '';
            }
        }

        return $data;
    }
}
