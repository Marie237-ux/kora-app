<?php

namespace App\Http\Controllers;

use App\Domain\Catalogue\CatalogueManager;
use App\Domain\Catalogue\CategoryAttributes;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ManageCatalogueController extends Controller
{
    public function index(Request $request, string $entity, CatalogueManager $manager)
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:active,draft,published,archived'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = DB::table($entity)->orderByDesc('updated_at')->orderBy('id');
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }
        if (! empty($data['q'])) {
            $term = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $data['q']).'%';
            if ($entity === 'offers') {
                $query->where('id', $data['q']);
            } elseif (in_array($entity, ['suppliers', 'shops'], true)) {
                $query->where('name', 'like', $term);
            } else {
                $query->where(fn ($q) => $q->where('name->fr', 'like', $term)->orWhere('name->en', 'like', $term));
            }
        }
        $page = $query->paginate(24);

        return [
            'data' => collect($page->items())->map(fn ($row) => $manager->dto($entity, $row)),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    public function show(string $entity, string $id, CatalogueManager $manager)
    {
        return ['data' => $manager->dto($entity, $manager->row($entity, $id))];
    }

    public function store(Request $request, string $entity, CatalogueManager $manager)
    {
        $data = $request->validate(['draft' => ['required', 'array']]);

        return response()->json(['data' => $manager->save($entity, null, null, $data['draft'], $request->user())], 201);
    }

    public function update(Request $request, string $entity, string $id, CatalogueManager $manager)
    {
        $data = $request->validate(['draft' => ['required', 'array'], 'version' => ['required', 'integer', 'min:1']]);

        return ['data' => $manager->save($entity, $id, $data['version'], $data['draft'], $request->user())];
    }

    public function publish(Request $request, string $entity, string $id, CatalogueManager $manager)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ['data' => $manager->change($entity, $id, $data['version'], 'publish', $request->user())];
    }

    public function archive(Request $request, string $entity, string $id, CatalogueManager $manager)
    {
        $data = $request->validate(['version' => ['required', 'integer', 'min:1']]);

        return ['data' => $manager->change($entity, $id, $data['version'], 'archive', $request->user())];
    }

    public function revisions(string $entity, string $id, CatalogueManager $manager)
    {
        $manager->row($entity, $id);
        $page = DB::table('catalogue_revisions')
            ->where('entity', $entity)
            ->where('resource_id', $id)
            ->orderByDesc('version')
            ->paginate(24);

        return [
            'data' => collect($page->items())->map(
                fn ($r) => [
                    'version' => (int) $r->version,
                    'action' => $r->action,
                    'snapshot' => json_decode($r->snapshot, true),
                    'created_at' => CarbonImmutable::parse($r->created_at, 'UTC')->toIso8601String(),
                ],
            ),
            'meta' => ['page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    public function lookups()
    {
        $tree = DB::table('categories')->orderBy('slug')->get()->keyBy('id');

        return [
            'data' => [
                'suppliers' => DB::table('suppliers')
                    ->where('status', 'active')
                    ->orderBy('name')
                    ->get(['id', 'name']),
                'shops' => DB::table('shops')
                    ->where('published', true)
                    ->orderBy('name')
                    ->get(['id', 'name', 'supplier_id']),
                'categories' => $tree->filter(fn ($c) => $c->published)->values()->map(
                    fn ($c) => [
                        'id' => $c->id,
                        'name' => json_decode($c->name, true),
                        'slug' => $c->slug,
                        'parent_id' => $c->parent_id,
                        'attributes' => app(CategoryAttributes::class)->definitions($c->id, $tree),
                    ],
                ),
                'variants' => DB::table('variants')
                    ->join('products', 'products.id', '=', 'variants.product_id')
                    ->where('variants.active', true)
                    ->where('products.status', 'published')
                    ->orderBy('variants.sku')
                    ->get(['variants.id', 'variants.sku', 'products.id as product_id', 'products.name as product_name'])
                    ->map(
                        fn ($v) => [
                            'id' => $v->id,
                            'sku' => $v->sku,
                            'product_id' => $v->product_id,
                            'product_name' => json_decode($v->product_name, true),
                        ],
                    ),
            ],
        ];
    }

    public function pricePreview(Request $request, string $id, CatalogueManager $manager)
    {
        $data = $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:1000000']]);
        $offer = $manager->row('offers', $id);

        return ['data' => $manager->previewOffer($manager->draft('offers', $offer), $data['quantity'])];
    }
}
