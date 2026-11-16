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
                'cover_path' => ['sometimes', 'nullable', 'string', 'max:200'],
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

    /**
     * Inspirations: public boards (published explicitly by their owner). Each card carries its cover photo, author,
     * product images, what is still sold and its total at today's prices, the aisles and shops it draws from.
     * Filters: text, aisles, shops, districts, authors, budget (total), size, deals, all in stock; sort and pages.
     * Each filter's counts ignore that filter's own selection, as in the catalogue.
     */
    public function publicIndex(Request $http, Boards $boards)
    {
        $in = $http->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'string', 'max:500'],
            'shop' => ['nullable', 'string', 'max:500'],
            'district' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:500'],
            // Only boards whose products can all be delivered in this city (no shipping needed).
            'local_city' => ['nullable', 'string', 'max:80'],
            'author' => ['nullable', 'string', 'max:500'],
            'size' => ['nullable', 'string', 'max:40'],
            'on_sale' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', 'in:newest,price_asc,price_desc,products'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'in:12,24,48'],
        ]);
        $list = fn (?string $v) => array_values(array_filter(array_map('trim', explode(',', (string) $v)), fn ($x) => $x !== ''));
        $rows = DB::table('boards')
            ->where('visibility', 'public')
            ->whereNull('archived_at')
            ->orderByDesc('updated_at')
            ->orderBy('id')
            ->limit(2000)
            ->get(['id', 'owner_id', 'title', 'description', 'kind', 'cover_path', 'updated_at']);
        $norm = fn (?string $v) => \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii((string) $v));
        $q = $norm(trim((string) ($in['q'] ?? '')));
        $cards = $rows->map(fn ($b) => ['id' => $b->id, 'title' => $b->title, 'description' => $b->description, 'kind' => $b->kind, 'updated_at' => Records::iso($b->updated_at), ...$boards->showcase($b, true)])
            ->filter(fn ($c) => $q === '' || str_contains($norm($c['title'].' '.$c['description'].' '.implode(' ', array_column($c['aisles'], 'name')).' '.$c['author']['name']), $q))
            ->values();
        $sizeOf = fn ($c) => $c['available_count'] <= 3 ? 'small' : ($c['available_count'] <= 6 ? 'medium' : 'large');
        $sel = ['category' => $list($in['category'] ?? null), 'shop' => $list($in['shop'] ?? null), 'district' => $list($in['district'] ?? null), 'city' => $list($in['city'] ?? null), 'author' => $list($in['author'] ?? null), 'size' => $list($in['size'] ?? null)];
        $passes = function ($c, ?string $skip = null) use ($sel, $in, $sizeOf) {
            $hit = fn (string $key, array $values) => $skip === $key || ! $sel[$key] || array_intersect($values, $sel[$key]);

            return $hit('category', array_column($c['aisles'], 'slug'))
                && $hit('shop', array_column($c['shops'], 'slug'))
                && $hit('district', array_filter(array_column($c['shops'], 'district')))
                && $hit('city', array_column($c['shops'], 'city'))
                && ($skip === 'local' || empty($in['local_city']) || ($c['shops'] && collect($c['shops'])->every(fn ($s) => $s['city'] === $in['local_city'])))
                && $hit('author', [$c['author']['key']])
                && $hit('size', [$sizeOf($c)])
                && ($skip === 'on_sale' || empty($in['on_sale']) || $c['on_sale_count'] > 0)
                && ($skip === 'in_stock' || empty($in['in_stock']) || $c['all_in_stock'])
                && ($skip === 'price' || ! isset($in['price_min']) || $c['total']['amount'] >= (int) $in['price_min'])
                && ($skip === 'price' || ! isset($in['price_max']) || $c['total']['amount'] <= (int) $in['price_max']);
        };
        $facet = function (string $skip, callable $values, ?callable $label = null) use ($cards, $passes) {
            $counts = [];
            $labels = [];
            foreach ($cards->filter(fn ($c) => $passes($c, $skip)) as $c) {
                foreach ($values($c) as $value => $name) {
                    $counts[$value] = ($counts[$value] ?? 0) + 1;
                    $labels[$value] = $name;
                }
            }

            return collect($counts)->map(fn ($n, $v) => ['value' => (string) $v, 'label' => (string) $labels[$v], 'count' => $n])->sortBy('label')->values();
        };
        $result = $cards->filter(fn ($c) => $passes($c))->values();
        $result = match ($in['sort'] ?? 'newest') {
            'price_asc' => $result->sortBy(fn ($c) => $c['total']['amount'])->values(),
            'price_desc' => $result->sortByDesc(fn ($c) => $c['total']['amount'])->values(),
            'products' => $result->sortByDesc(fn ($c) => $c['available_count'])->values(),
            default => $result,
        };
        $priced = $cards->filter(fn ($c) => $passes($c, 'price'))->pluck('total.amount');
        $per = (int) ($in['per_page'] ?? 12);
        $total = $result->count();
        $last = max(1, (int) ceil($total / $per));
        $page = min($last, (int) ($in['page'] ?? 1));
        $sizes = ['small' => app()->getLocale() === 'en' ? '1 to 3 products' : '1 à 3 produits', 'medium' => app()->getLocale() === 'en' ? '4 to 6 products' : '4 à 6 produits', 'large' => app()->getLocale() === 'en' ? '7 products or more' : '7 produits et plus'];

        return [
            'data' => $result->slice(($page - 1) * $per, $per)->values(),
            'meta' => [
                'page' => $page,
                'last_page' => $last,
                'total' => $total,
                'facets' => [
                    'categories' => $facet('category', fn ($c) => array_column($c['aisles'], 'name', 'slug')),
                    'shops' => $facet('shop', fn ($c) => array_column($c['shops'], 'name', 'slug')),
                    'districts' => $facet('district', fn ($c) => collect($c['shops'])->pluck('district')->filter()->unique()->mapWithKeys(fn ($d) => [$d => $d])->all()),
                    'cities' => $facet('city', fn ($c) => collect($c['shops'])->pluck('city')->filter()->unique()->mapWithKeys(fn ($d) => [$d => $d])->all()),
                    'authors' => $facet('author', fn ($c) => [$c['author']['key'] => $c['author']['name']]),
                    'sizes' => $facet('size', fn ($c) => [$sizeOf($c) => $sizes[$sizeOf($c)]])->sortBy(fn ($o) => array_search($o['value'], array_keys($sizes)))->values(),
                    'on_sale' => $cards->filter(fn ($c) => $passes($c, 'on_sale') && $c['on_sale_count'] > 0)->count(),
                    'in_stock' => $cards->filter(fn ($c) => $passes($c, 'in_stock') && $c['all_in_stock'])->count(),
                    'price' => ['min' => (int) ($priced->min() ?? 0), 'max' => (int) ($priced->max() ?? 0)],
                ],
            ],
        ];
    }

    public function publicShow(string $id, Boards $boards)
    {
        return ['data' => app(ReadSnapshot::class)->run(function () use ($id, $boards) {
            $board = $boards->publicAccess($id);

            return [...$boards->dto($board, null, true), ...$boards->showcase($board)];
        })];
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
