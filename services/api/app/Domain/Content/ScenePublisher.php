<?php

namespace App\Domain\Content;

use App\Models\HeroScene;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ScenePublisher
{
    public function create(array $data, User $actor): HeroScene
    {
        return DB::transaction(function () use ($data, $actor) {
            $scene = HeroScene::create([
                'name' => $data['name'],
                'draft' => $data['draft'],
                'position' => 0,
            ])->refresh();
            $this->audit($scene, $actor, 'scene.created');

            return $scene;
        });
    }

    public function change(string $id, int $version, string $action, array $data, User $actor): HeroScene
    {
        return DB::transaction(function () use ($id, $version, $action, $data, $actor) {
            $scene = HeroScene::query()->lockForUpdate()->findOrFail($id);
            abort_unless($scene->version === $version, 409);
            if ($action === 'update') {
                $scene->name = $data['name'];
                $scene->draft = $data['draft'];
            }
            if ($action === 'publish') {
                $scene->publication = app(SceneContent::class)->validatePublication($scene->draft);
                $scene->published_at = now();
                $scene->paused = false;
            }
            if ($action === 'pause') {
                $scene->paused = $data['paused'];
            }
            if ($action === 'restore') {
                $revision = DB::table('hero_revisions')
                    ->where('hero_scene_id', $id)
                    ->where('version', $data['revision'])
                    ->first();
                abort_unless($revision, 404);
                $scene->draft = json_decode($revision->snapshot, true);
            }
            $scene->version++;
            $scene->save();
            $this->audit($scene, $actor, 'scene.'.$action);
            if ($action === 'publish') {
                DB::table('hero_revisions')->insert([
                    'hero_scene_id' => $scene->id,
                    'version' => $scene->version,
                    'snapshot' => json_encode($scene->publication, JSON_THROW_ON_ERROR),
                    'actor_id' => $actor->id,
                    'created_at' => now(),
                ]);
            }
            if (in_array($action, ['publish', 'pause'], true)) {
                DB::table('outbox_events')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => 'ContentPublished',
                    'deduplication_key' => 'scene:'.$scene->id.':'.$scene->version,
                    'payload' => json_encode(
                        ['scene_id' => $scene->id, 'version' => $scene->version],
                        JSON_THROW_ON_ERROR,
                    ),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return $scene;
        }, 3);
    }

    public function reorder(array $items, User $actor): void
    {
        DB::transaction(function () use ($items, $actor) {
            $scenes = HeroScene::whereIn('id', array_column($items, 'id'))
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            foreach ($items as $position => $item) {
                $scene = $scenes->get($item['id']);
                abort_unless($scene && $scene->version === $item['version'], 409);
                $scene->position = $position;
                $scene->version++;
                $scene->save();
                $this->audit($scene, $actor, 'scene.reordered');
            }
        }, 3);
    }

    private function audit(HeroScene $scene, User $actor, string $action): void
    {
        DB::table('audit_events')->insert([
            'actor_id' => $actor->id,
            'action' => $action,
            'resource_id' => $scene->id,
            'details' => json_encode(['version' => $scene->version], JSON_THROW_ON_ERROR),
            'request_id' => request()->attributes->get('request_id'),
            'created_at' => now(),
        ]);
    }
}
