<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Search\TextSearch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogueController extends Controller
{
    /** Effective price (shop price, or the promotion price while the promotion runs). */
    private const PRICE = 'CASE WHEN offers.promotion_price IS NOT NULL AND offers.promotion_starts_at <= ? AND offers.promotion_ends_at > ? THEN offers.promotion_price ELSE offers.selling_price END';

    private function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:150'],
            'category' => ['nullable', 'string', 'max:120'],
            // Lists are comma-separated: shop=a,b  district=Akwa,Bonapriso  filters[couleur]=noir,blanc
            'shop' => ['nullable', 'string', 'max:500'],
            'city' => ['nullable', 'string', 'max:200'],
            'district' => ['nullable', 'string', 'max:500'],
            'price_min' => ['nullable', 'integer', 'min:0'],
            'price_max' => ['nullable', 'integer', 'min:0'],
            'on_sale' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'in:12,24,48'],
            'sort' => ['nullable', 'in:name,price_asc,price_desc,best_selling'],
            'filters' => ['nullable', 'array', 'max:20'],
            'filters.*' => ['nullable', 'string', 'max:500'],
        ];
    }

    private static function list(?string $value): array
    {
        return array_values(array_unique(array_filter(array_map('trim', explode(',', (string) $value)), fn ($v) => $v !== '')));
    }

    /** Filterable definitions: the category's own, or (without a category) the union of every published category's. */
    private function definitionsFor(?object $category, CategoryAttributes $attributes): \Illuminate\Support\Collection
    {
        if ($category) {
            return collect($attributes->definitions($category->id))->keyBy('key');
        }
        $tree = DB::table('categories')->get()->keyBy('id');
        $all = [];
        foreach ($tree->filter(fn ($c) => $c->published) as $c) {
            foreach ($attributes->definitions($c->id, $tree) as $d) {
                if (! isset($all[$d['key']])) {
                    $all[$d['key']] = $d;
                } else {
                    $known = array_column($all[$d['key']]['options'] ?? [], 'value');
                    foreach ($d['options'] ?? [] as $o) {
                        if (! in_array($o['value'], $known, true)) {
                            $all[$d['key']]['options'][] = $o;
                        }
                    }
                }
            }
        }

        return collect($all);
    }

    /** Applies every filter of the request; $only limits which groups are applied (facets use the base only). */
    /**
     * A product sold in several variants (shades, sizes, materials…) is one card, not one per variant: among the
     * offers that match the filters, keep one per product and shop — the first variant by SKU, so a colour filter
     * shows the matching shade. The other variants are picked on the product page.
     */
    private function oneCardPerProduct($query): void
    {
        $ranked = (clone $query)
            ->select('offers.id as card_id')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY products.id, offers.shop_id ORDER BY variants.sku, offers.id) AS card_rank');
        $query->whereIn('offers.id', DB::query()->fromSub($ranked, 'cards')->where('card_rank', 1)->select('card_id'));
    }

    private function filtered(array $input, CategoryAttributes $attributes, PublicCatalogue $catalogue, bool $base = false): array
    {
        $query = $catalogue->query();
        $searchTokens = ! empty($input['q']) ? app(TextSearch::class)->apply($query, $input['q']) : [];
        $category = ! empty($input['category']) ? $this->publishedRow('categories', $input['category']) : null;
        if (! empty($input['category'])) {
            $category
                ? $query->whereIn('products.category_id', $attributes->descendantIds($category->id))
                : $query->whereRaw('1 = 0');
        }
        if ($base) {
            return [$query, $category, $searchTokens];
        }
        if ($shops = self::list($input['shop'] ?? null)) {
            $query->whereIn('shops.slug', $shops);
        }
        if ($cities = self::list($input['city'] ?? null)) {
            $query->whereIn('shops.city', $cities);
        }
        if ($districts = self::list($input['district'] ?? null)) {
            $query->whereIn('shops.district', $districts);
        }
        if (isset($input['price_min'])) {
            $query->whereRaw(self::PRICE.' >= ?', [now(), now(), (int) $input['price_min']]);
        }
        if (isset($input['price_max'])) {
            $query->whereRaw(self::PRICE.' <= ?', [now(), now(), (int) $input['price_max']]);
        }
        if (! empty($input['on_sale'])) {
            $query->whereNotNull('offers.promotion_price')
                ->where('offers.promotion_starts_at', '<=', now())
                ->where('offers.promotion_ends_at', '>', now());
        }
        if (! empty($input['in_stock'])) {
            $query->where('offers.availability', 'available');
        }
        if (! empty($input['filters'])) {
            $definitions = $this->definitionsFor($category, $attributes);
            foreach ($input['filters'] as $key => $raw) {
                $values = self::list($raw);
                if (! $values) {
                    continue;
                }
                $definition = $definitions->get($key);
                if (! $definition || ! $definition['filterable']) {
                    throw ValidationException::withMessages(['filters' => __('catalogue.ATTRIBUTE_UNKNOWN')]);
                }
                $values = array_map(function ($v) use ($definition, $attributes) {
                    if ($definition['type'] === 'boolean') {
                        $v = match ($v) {
                            'true' => true,
                            'false' => false,
                            default => null,
                        };
                    }

                    return $attributes->value($definition, $v);
                }, $values);
                $query->where(function ($q) use ($key, $values) {
                    foreach ($values as $value) {
                        is_bool($value)
                            ? $q->orWhereJsonContains('variants.attributes->'.$key, $value)
                            : $q->orWhere('variants.attributes->'.$key, $value);
                    }
                });
            }
        }

        return [$query, $category, $searchTokens];
    }

    public function index(Request $request, PublicCatalogue $catalogue, CategoryAttributes $attributes)
    {
        $input = $request->validate($this->rules());
        $locale = app()->getLocale();
        [$query, , $searchTokens] = $this->filtered($input, $attributes, $catalogue);
        $this->oneCardPerProduct($query);
        $sort = $input['sort'] ?? 'name';
        if ($sort === 'best_selling') {
            // Real sales only: quantities on paid orders over the last 90 days, net of cancellations.
            $sold = DB::table('order_lines')
                ->join('orders', 'orders.id', '=', 'order_lines.order_id')
                ->whereNotNull('orders.paid_at')
                ->where('orders.paid_at', '>=', now()->subDays(90))
                ->groupBy('order_lines.offer_id')
                ->selectRaw('order_lines.offer_id, SUM(order_lines.quantity - COALESCE(order_lines.cancelled_quantity, 0)) AS sold');
            $query
                ->leftJoinSub($sold, 'sales', 'sales.offer_id', '=', 'offers.id')
                ->orderByRaw('COALESCE(sales.sold, 0) DESC')
                ->orderBy('products.slug');
        } else {
            $query->orderBy($sort === 'name' ? 'products.slug' : 'selling_price', $sort === 'price_desc' ? 'desc' : 'asc');
        }
        $query->orderBy('offers.id');
        $page = $query->paginate((int) ($input['per_page'] ?? 24));
        if ($page->total() === 0 && $searchTokens) {
            app(TextSearch::class)->miss($searchTokens, $locale);
        }

        return [
            'data' => collect($page->items())->map(fn ($row) => $catalogue->dto($row, $locale)),
            'meta' => [
                'page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'demo' => config('kora.demo'),
            ],
        ];
    }

    /**
     * Values customers can filter on, with real product counts, for the search/aisle in view: price range, deals,
     * stock, shops, cities, districts and every filterable characteristic (colour, size, material…).
     */
    public function facets(Request $request, PublicCatalogue $catalogue, CategoryAttributes $attributes)
    {
        $input = $request->validate(['q' => ['nullable', 'string', 'max:150'], 'category' => ['nullable', 'string', 'max:120']]);
        $locale = app()->getLocale();
        [$query, $category] = $this->filtered($input, $attributes, $catalogue, true);
        $rows = $query->limit(5000)->get();
        // Counts are products (a lipstick in five shades counts once), not variants.
        $products = fn ($group) => collect($group)->unique(fn ($r) => $r->product_id.'|'.$r->shop_id)->count();
        $count = fn (callable $key) => $rows->groupBy($key)->map($products);
        $definitions = $this->definitionsFor($category, $attributes);
        $characteristics = [];
        foreach ($definitions as $key => $d) {
            if (! ($d['filterable'] ?? false) || ! in_array($d['type'], ['choice', 'boolean'], true)) {
                continue;
            }
            $counts = [];
            foreach ($rows as $r) {
                $v = (json_decode($r->attributes, true) ?? [])[$key] ?? null;
                if ($v === null || $v === '') {
                    continue;
                }
                $v = is_bool($v) ? ($v ? 'true' : 'false') : (string) $v;
                $counts[$v][$r->product_id.'|'.$r->shop_id] = true;
            }
            $counts = array_map('count', $counts);
            $options = $d['type'] === 'boolean'
                ? [['value' => 'true', 'label' => $locale === 'fr' ? 'Oui' : 'Yes'], ['value' => 'false', 'label' => $locale === 'fr' ? 'Non' : 'No']]
                : array_map(fn ($o) => ['value' => $o['value'], 'label' => $o['label'][$locale] ?? $o['value']], $d['options'] ?? []);
            $options = array_values(array_filter(
                array_map(fn ($o) => [...$o, 'count' => $counts[$o['value']] ?? 0], $options),
                fn ($o) => $o['count'] > 0,
            ));
            if ($options) {
                $characteristics[] = ['key' => $key, 'type' => $d['type'], 'label' => $d['label'][$locale] ?? $key, 'unit' => $d['unit'] ?? null, 'options' => $options];
            }
        }
        $prices = $rows->pluck('selling_price')->map(fn ($p) => (int) $p);
        // Aisles that contain results for the search (counts ignore the aisle currently selected).
        $aisleRows = ! empty($input['category']) ? $this->filtered(['q' => $input['q'] ?? null], $attributes, $catalogue, true)[0]->limit(5000)->get() : $rows;
        $aisleNames = DB::table('categories')->pluck('name', 'slug');
        $aisles = $aisleRows->groupBy('category_slug')->map(fn ($g, $slug) => [
            'value' => (string) $slug,
            'label' => json_decode((string) ($aisleNames[$slug] ?? '{}'), true)[$locale] ?? (string) $slug,
            'count' => $products($g),
        ])->sortBy('label')->values();
        $toList = fn ($counts, $label = null) => $counts->filter(fn ($n, $k) => $k !== '' && $k !== null)
            ->map(fn ($n, $k) => ['value' => (string) $k, 'label' => $label ? $label($k) : (string) $k, 'count' => $n])->sortBy('label')->values();
        $shopNames = $rows->pluck('shop_name', 'shop_slug');

        return ['data' => [
            'total' => $products($rows),
            'price' => ['min' => $prices->min() ?? 0, 'max' => $prices->max() ?? 0],
            'on_sale' => $products($rows->filter(fn ($r) => isset($r->regular_price) && (int) $r->regular_price > (int) $r->selling_price)),
            'in_stock' => $products($rows->where('availability', 'available')),
            'shops' => $toList($count(fn ($r) => $r->shop_slug), fn ($k) => $shopNames[$k] ?? $k),
            'cities' => $toList($count(fn ($r) => $r->shop_city ?? '')),
            'districts' => $toList($count(fn ($r) => $r->shop_district ?? '')),
            'characteristics' => $characteristics,
            'categories' => $aisles,
        ]];
    }

    public function show(string $id, PublicCatalogue $catalogue)
    {
        $offer = $catalogue->offer($id, app()->getLocale());
        abort_unless($offer, 404);
        // Variants of the same product in the same shop (shades, sizes…): only ones that exist and are published.
        $offer['variants'] = $catalogue->query()
            ->where('products.id', $offer['product_id'])
            ->where('shops.id', $offer['shop']['id'])
            ->orderBy('variants.sku')
            ->get()
            ->map(fn ($row) => $catalogue->dto($row, app()->getLocale()))
            ->map(fn ($v) => ['id' => $v['id'], 'sku' => $v['sku'], 'attributes' => $v['attributes'], 'image' => $v['image'], 'price' => $v['price'], 'compare_at_price' => $v['compare_at_price'], 'availability' => $v['availability']])
            ->values();

        $offer['service'] = app(\App\Domain\Catalogue\ProductService::class)->for($offer);

        return ['data' => $offer];
    }

    public function suggestions(Request $request, PublicCatalogue $catalogue)
    {
        $input = $request->validate(['q' => ['required', 'string', 'min:2', 'max:150']]);
        $q = $catalogue->query();
        app(TextSearch::class)->apply($q, $input['q']);

        return [
            'data' => $q
                ->orderBy('products.slug')
                ->orderBy('offers.id')
                ->limit(8)
                ->get()
                ->map(fn ($r) => $catalogue->dto($r, app()->getLocale())),
        ];
    }

    public function shops()
    {
        return [
            'data' => DB::table('shops')
                ->where('published', true)
                ->orderBy('name')
                ->get(['id', 'slug', 'name', 'description', 'city', 'district', 'avatar_path'])
                ->map(fn ($s) => $this->shopDto($s)),
        ];
    }

    public function shop(string $slug)
    {
        $shop = $this->publishedRow('shops', $slug);
        abort_unless($shop, 404);

        return ['data' => $this->shopDto($shop)];
    }

    public function categories(CategoryAttributes $attributes)
    {
        $tree = DB::table('categories')->orderBy('slug')->get()->keyBy('id');

        return [
            'data' => $tree->filter(fn ($category) => $category->published)->values()->map(
                fn ($category) => [
                    'id' => $category->id,
                    'slug' => $category->slug,
                    'parent_id' => $category->parent_id,
                    'name' => json_decode($category->name, true)[app()->getLocale()] ?? '',
                    'attributes' => array_map(
                        fn ($a) => [
                            ...$a,
                            'unit' => $a['unit'] ?? null,
                            'label' => $a['label'][app()->getLocale()],
                            'options' => array_map(
                                fn ($o) => ['value' => $o['value'], 'label' => $o['label'][app()->getLocale()]],
                                $a['options'],
                            ),
                        ],
                        $attributes->definitions($category->id, $tree),
                    ),
                ],
            ),
        ];
    }

    public function resolve(string $entity, string $slug)
    {
        $row = $this->publishedRow($entity, $slug);
        abort_unless($row, 404);

        return ['data' => ['id' => $row->id, 'slug' => $row->slug]];
    }

    private function publishedRow(string $entity, string $slug): ?object
    {
        $query = fn () => in_array($entity, ['categories', 'shops'], true)
            ? DB::table($entity)->where('published', true)
            : DB::table('products')->where('status', 'published');
        $row = $query()->where('slug', $slug)->first();
        if ($row) {
            return $row;
        }
        $alias = DB::table('slug_redirects')->where('entity', $entity)->where('slug', $slug)->first();

        return $alias ? $query()->where('id', $alias->resource_id)->first() : null;
    }

    private function shopDto(object $shop): array
    {
        return [
            'id' => $shop->id,
            'slug' => $shop->slug,
            'name' => $shop->name,
            'description' => json_decode($shop->description, true)[app()->getLocale()] ?? '',
            'city' => $shop->city ?? 'Douala',
            'district' => $shop->district ?? null,
            'avatar' => $shop->avatar_path ?? null,
        ];
    }
}
