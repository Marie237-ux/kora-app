<?php

namespace App\Http\Controllers;

use App\Domain\Boards\BoardPins;
use App\Domain\Boards\Boards;
use App\Domain\Boards\BoardSharing;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BoardsController extends OperationalController
{
    public function index(Request $http, Boards $boards)
    {
        $q = DB::table('boards')
            ->whereNull('archived_at')
            ->where(
                fn ($q) => $q
                    ->where('owner_id', $http->user()->id)
                    ->orWhereExists(
                        fn ($s) => $s
                            ->selectRaw('1')
                            ->from('board_members')
                            ->whereColumn('board_id', 'boards.id')
                            ->where('user_id', $http->user()->id)
                            ->whereNull('revoked_at'),
                    ),
            )
            ->select('boards.*')
            ->selectSub(
                DB::table('board_pins')->selectRaw('COUNT(*)')->whereColumn('board_id', 'boards.id'),
                'pin_count',
            )
            ->orderByDesc('updated_at')
            ->orderBy('id');

        return $this->page(
            $q,
            $http,
            fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'description' => $b->description,
                'kind' => $b->kind,
                'is_favorites' => (bool) $b->is_favorites,
                'visibility' => $b->visibility,
                'version' => (int) $b->version,
                'role' => $boards->role($b, $http->user()),
                'pin_count' => (int) $b->pin_count,
                'updated_at' => Records::iso($b->updated_at),
            ],
        );
    }

    public function create(Request $http, Boards $boards)
    {
        $d = $http->validate([
            'title' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:2000'],
            'kind' => ['required', 'in:collection,gift'],
            'gift_date' => ['nullable', 'date_format:Y-m-d'],
            'hide_gift_purchases' => ['required', 'boolean'],
        ]);

        return $this->command($http, 'board.create', $d, fn ($data) => $boards->create($data, $http->user()), 201);
    }

    public function favorites(Request $http, Boards $boards)
    {
        return ['data' => ['offer_ids' => $boards->favoriteOffers($http->user())]];
    }

    public function toggleFavorite(Request $http, Boards $boards)
    {
        $d = $http->validate(['offer_id' => ['required', 'uuid']]);

        return ['data' => DB::transaction(fn () => $boards->toggleFavorite($d['offer_id'], $http->user()))];
    }

    public function show(Request $http, string $id, Boards $boards)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $boards->dto($boards->access($id, $http->user()), $http->user()),
            ),
        ];
    }

    private function version(Request $http): array
    {
        return $http->validate(['version' => ['required', 'integer', 'min:1']]);
    }

    private function write(
        Request $http,
        string $id,
        array $d,
        string $action,
        callable $work,
        bool $owner = false,
        int $status = 200,
    ) {
        app(Boards::class)->access($id, $http->user(), true, $owner, false, $action === 'archive');

        return $this->command(
            $http,
            'board.'.$action.':'.$id,
            $d,
            function ($data) use ($http, $id, $work, $owner) {
                $boards = app(Boards::class);
                $b = $boards->access($id, $http->user(), true, $owner, true);
                $boards->version($b, $data['version']);

                return $work($b, $data);
            },
            $status,
        );
    }

    public function update(Request $http, string $id, Boards $boards)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate([
                'title' => ['required', 'string', 'max:150'],
                'description' => ['present', 'nullable', 'string', 'max:2000'],
                'cover_pin_id' => ['present', 'nullable', 'uuid'],
            ]),
        ];
        $d['description'] ??= '';

        return $this->write($http, $id, $d, 'update', fn ($b, $data) => $boards->update($b, $data, $http->user()));
    }

    public function giftSettings(Request $http, string $id, Boards $boards)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate([
                'kind' => ['required', 'in:collection,gift'],
                'gift_date' => ['present', 'nullable', 'date_format:Y-m-d'],
                'hide_gift_purchases' => ['required', 'boolean'],
            ]),
        ];

        return $this->write(
            $http,
            $id,
            $d,
            'gift_settings',
            fn ($b, $data) => $boards->update($b, $data, $http->user()),
            true,
        );
    }

    public function archive(Request $http, string $id, BoardSharing $sharing)
    {
        return $this->write(
            $http,
            $id,
            $this->version($http),
            'archive',
            fn ($b) => $sharing->archive($b, $http->user()),
            true,
        );
    }

    public function visibility(Request $http, string $id, BoardSharing $sharing)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate([
                'visibility' => ['required', 'in:private,public'],
                'reviewed' => ['required', 'accepted'],
            ]),
        ];

        return $this->write(
            $http,
            $id,
            $d,
            'visibility',
            fn ($b, $data) => $sharing->visibility($b, $data['visibility'], $http->user()),
            true,
        );
    }

    public function share(Request $http, string $id, BoardSharing $sharing)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate([
                'expires_in_days' => ['required', 'integer', 'min:1', 'max:90'],
                'reviewed' => ['required', 'accepted'],
            ]),
        ];

        return $this->write(
            $http,
            $id,
            $d,
            'share',
            fn ($b, $data) => $sharing->share($b, $data['expires_in_days'], $http->user()),
            true,
            201,
        );
    }

    public function publicIndex(Request $http)
    {
        $q = DB::table('boards')
            ->where('visibility', 'public')
            ->whereNull('archived_at')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->select(['id', 'title', 'description', 'kind', 'updated_at'])
            ->selectSub(
                DB::table('board_pins')->selectRaw('COUNT(*)')->whereColumn('board_id', 'boards.id'),
                'pin_count',
            );

        return $this->page(
            $q,
            $http,
            fn ($b) => [
                'id' => $b->id,
                'title' => $b->title,
                'description' => $b->description,
                'kind' => $b->kind,
                'pin_count' => (int) $b->pin_count,
                'updated_at' => Records::iso($b->updated_at),
            ],
        );
    }

    public function publicShow(string $id, Boards $boards)
    {
        return ['data' => app(ReadSnapshot::class)->run(fn () => $boards->dto($boards->publicAccess($id), null, true))];
    }

    public function shared(string $id, string $token, Boards $boards)
    {
        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $boards->dto($boards->publicAccess($id, $token), null, true),
            ),
        ];
    }

    public function addSection(Request $http, string $id, Boards $boards)
    {
        $d = [...$this->version($http), ...$http->validate(['title' => ['required', 'string', 'max:100']])];

        return $this->write(
            $http,
            $id,
            $d,
            'section_add',
            fn ($b, $data) => $boards->addSection($b, $data['title'], $http->user()),
            false,
            201,
        );
    }

    public function renameSection(Request $http, string $id, string $sectionId, Boards $boards)
    {
        $d = [...$this->version($http), ...$http->validate(['title' => ['required', 'string', 'max:100']])];

        return $this->write($http, $id, $d, 'section_rename:'.$sectionId, function ($b, $data) use (
            $boards,
            $sectionId,
            $http,
        ) {
            $boards->section($b, $sectionId);
            DB::table('board_sections')
                ->where('id', $sectionId)
                ->update(['title' => $data['title'], 'updated_at' => now()]);

            return $boards->dto(
                $boards->change($b, $http->user(), 'section_renamed', [], ['section_id' => $sectionId]),
                $http->user(),
            );
        });
    }

    public function removeSection(Request $http, string $id, string $sectionId, Boards $boards)
    {
        return $this->write(
            $http,
            $id,
            $this->version($http),
            'section_remove:'.$sectionId,
            fn ($b) => $boards->removeSection($b, $sectionId, $http->user()),
        );
    }

    public function reorderSections(Request $http, string $id, Boards $boards)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate(['ids' => ['present', 'array', 'max:30'], 'ids.*' => ['required', 'uuid', 'distinct']]),
        ];

        return $this->write(
            $http,
            $id,
            $d,
            'section_reorder',
            fn ($b, $data) => $boards->reorder($b, 'sections', $data['ids'], null, $http->user()),
        );
    }

    private function pinData(Request $http, bool $create): array
    {
        return $http->validate([
            'section_id' => [$create ? 'nullable' : 'present', 'nullable', 'uuid'],
            'caption' => ['nullable', 'string', 'max:1000'],
            'wished_quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'personal_note' => ['nullable', 'string', 'max:2000'],
            ...$create ? ['offer_id' => ['required', 'uuid']] : [],
        ]);
    }

    public function addPin(Request $http, string $id, Boards $boards)
    {
        $d = [...$this->version($http), ...$this->pinData($http, true)];

        return $this->write(
            $http,
            $id,
            $d,
            'pin_add',
            fn ($b, $data) => $boards->addPin($b, $data, $http->user()),
            false,
            201,
        );
    }

    public function editPin(Request $http, string $id, string $pinId, Boards $boards)
    {
        $d = [...$this->version($http), ...$this->pinData($http, false)];

        return $this->write(
            $http,
            $id,
            $d,
            'pin_edit:'.$pinId,
            fn ($b, $data) => $boards->editPin($b, $pinId, $data, $http->user()),
        );
    }

    public function removePin(Request $http, string $id, string $pinId, Boards $boards)
    {
        return $this->write(
            $http,
            $id,
            $this->version($http),
            'pin_remove:'.$pinId,
            fn ($b) => $boards->removePin($b, $pinId, $http->user()),
        );
    }

    public function reorderPins(Request $http, string $id, Boards $boards)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate([
                'section_id' => ['present', 'nullable', 'uuid'],
                'ids' => ['present', 'array', 'max:500'],
                'ids.*' => ['required', 'uuid', 'distinct'],
            ]),
        ];

        return $this->write(
            $http,
            $id,
            $d,
            'pin_reorder',
            fn ($b, $data) => $boards->reorder($b, 'pins', $data['ids'], $data['section_id'], $http->user()),
        );
    }

    public function transfer(Request $http, string $id, string $pinId, BoardPins $pins)
    {
        $d = $http->validate([
            'source_version' => ['required', 'integer', 'min:1'],
            'target_version' => ['required', 'integer', 'min:1'],
            'target_board_id' => ['required', 'uuid'],
            'section_id' => ['present', 'nullable', 'uuid'],
            'copy' => ['required', 'boolean'],
            'copy_personal_note' => ['required', 'boolean'],
        ]);
        app(Boards::class)->access($id, $http->user(), ! $d['copy']);
        app(Boards::class)->access($d['target_board_id'], $http->user(), true);

        return $this->command(
            $http,
            'board.transfer:'.$pinId,
            $d,
            fn ($data) => $pins->transfer($id, $pinId, $data, $http->user()),
        );
    }

    public function toCart(Request $http, string $id, BoardPins $pins)
    {
        $d = $http->validate([
            'cart_version' => ['required', 'integer', 'min:1'],
            'pin_ids' => ['required', 'array', 'min:1', 'max:200'],
            'pin_ids.*' => ['required', 'uuid', 'distinct'],
        ]);
        app(Boards::class)->access($id, $http->user());

        return $this->command(
            $http,
            'board.to_cart:'.$id,
            $d,
            fn ($data) => $pins->toCart(
                $http,
                app(Boards::class)->access($id, $http->user()),
                $data['pin_ids'],
                $data['cart_version'],
            ),
        );
    }

    public function fromCart(Request $http, string $id, BoardPins $pins)
    {
        $d = [
            ...$this->version($http),
            ...$http->validate([
                'cart_version' => ['required', 'integer', 'min:1'],
                'line_id' => ['required', 'uuid'],
                'section_id' => ['present', 'nullable', 'uuid'],
                'copy' => ['required', 'boolean'],
                'merge_quantity' => ['required', 'boolean'],
                'personal_note' => ['nullable', 'string', 'max:2000'],
            ]),
        ];

        return $this->write($http, $id, $d, 'from_cart', fn ($b, $data) => $pins->fromCart($http, $b, $data));
    }

    public function events(Request $http, string $id, Boards $boards)
    {
        $boards->access($id, $http->user());

        return $this->page(
            DB::table('board_events')->where('board_id', $id)->orderBy('version'),
            $http,
            fn ($r) => [
                'version' => (int) $r->version,
                'actor_id' => (int) $r->actor_id,
                'action' => $r->action,
                'data' => json_decode($r->data, true),
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }
}
