<?php

namespace App\Domain\Catalogue;

use App\Domain\Media\MediaLibrary;
use App\Domain\Pricing\AgreementResolver;
use App\Domain\Search\PhotoIndexer;
use App\Domain\Search\TextIndexer;
use App\Domain\Shared\BusinessEvents;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CatalogueManager
{
    public const ENTITIES = ['suppliers', 'shops', 'categories', 'products', 'offers'];

    public function row(string $entity, string $id, bool $lock = false): object
    {
        abort_unless(in_array($entity, self::ENTITIES, true), 404);
        $query = DB::table($entity)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }
        $row = $query->first();
        abort_unless($row, 404);

        return $row;
    }

    public function draft(string $entity, object $row): array
    {
        if (isset($row->draft)) {
            return json_decode($row->draft, true);
        }

        return match ($entity) {
            'suppliers' => [
                'name' => $row->name,
                'private_details' => json_decode($row->private_details ?? '{}', true),
            ],
            'shops' => [
                'supplier_id' => $row->supplier_id,
                'slug' => $row->slug,
                'name' => $row->name,
                'description' => json_decode($row->description, true),
                'city' => $row->city ?? 'Douala',
                'district' => $row->district ?? null,
                'avatar_path' => $row->avatar_path ?? null,
            ],
            'categories' => [
                'parent_id' => $row->parent_id,
                'slug' => $row->slug,
                'name' => json_decode($row->name, true),
                'attributes' => json_decode($row->attribute_definitions ?? '[]', true),
            ],
            'products' => [
                'category_id' => $row->category_id,
                'slug' => $row->slug,
                'name' => json_decode($row->name, true),
                'description' => json_decode($row->description, true),
                'variants' => DB::table('variants')
                    ->where('product_id', $row->id)
                    ->where('active', true)
                    ->get()
                    ->map(
                        fn ($v) => ['id' => $v->id, 'sku' => $v->sku, 'attributes' => json_decode($v->attributes, true)],
                    )
                    ->all(),
            ],
            'offers' => [
                'shop_id' => $row->shop_id,
                'variant_id' => $row->variant_id,
                'reference_price' => (int) $row->reference_price,
                'minimum_quantity' => (int) $row->minimum_quantity,
                'availability' => $row->availability,
                'warranty' => json_decode($row->warranty, true),
                'image_path' => $row->image_path,
                'bulk_unit' => $row->bulk_unit ?? null,
                'bulk_unit_size' => isset($row->bulk_unit_size) ? (int) $row->bulk_unit_size : null,
                'bulk_min_units' => isset($row->bulk_min_units) ? (int) $row->bulk_min_units : null,
                'promotion' => $row->promotion_funding ? json_decode($row->promotion_funding, true) : null,
            ],
        };
    }

    public function dto(string $entity, object $row): array
    {
        $draft = $this->draft($entity, $row);
        if ($entity === 'products') {
            foreach ($draft['variants'] as &$variant) {
                $variant['attributes'] = (object) $variant['attributes'];
            }
            unset($variant);
        }
        $published =
            $entity === 'suppliers'
                ? false
                : (in_array($entity, ['shops', 'categories'], true)
                    ? (bool) $row->published
                    : $row->status === 'published');
        $publishedVersion = $row->published_version ?? ($published ? (int) $row->version : null);

        return [
            'id' => $row->id,
            'entity' => $entity,
            'version' => (int) $row->version,
            'published_version' => $publishedVersion,
            'status' => $entity === 'suppliers'
                    ? $row->status
                    : ($row->status === 'archived'
                        ? 'archived'
                        : ($published
                            ? 'published'
                            : 'draft')),
            'has_unpublished_changes' => $entity !== 'suppliers' && $publishedVersion !== (int) $row->version,
            'draft' => $draft,
            'updated_at' => $row->updated_at
                ? CarbonImmutable::parse($row->updated_at, 'UTC')->toIso8601String()
                : null,
        ];
    }

    public function save(string $entity, ?string $id, ?int $version, array $input, User $actor): array
    {
        abort_unless(in_array($entity, self::ENTITIES, true), 404);

        return DB::transaction(function () use ($entity, $id, $version, $input, $actor) {
            $row = $id ? $this->row($entity, $id, true) : null;
            if ($row) {
                abort_unless((int) $row->version === $version, 409);
            }
            $data = app(CatalogueRules::class)->validate($entity, $input);
            $id ??= (string) Str::uuid();
            $this->checkSlug($entity, $data, $id);
            if ($entity === 'products') {
                $data = $this->prepareVariants($data, $id, $row ? $this->draft($entity, $row) : []);
            }
            $nextVersion = $row ? (int) $row->version + 1 : 1;
            $values = ['version' => $nextVersion, 'updated_at' => now()];
            if ($entity === 'suppliers') {
                $values += ['name' => $data['name'], 'private_details' => $this->json($data['private_details'])];
            } else {
                $values['draft'] = $this->json($data);
            }
            if (! $row) {
                $values += ['id' => $id, 'created_at' => now()];
                $values += match ($entity) {
                    'suppliers' => ['status' => 'active'],
                    'shops' => [
                        'supplier_id' => $data['supplier_id'],
                        'slug' => $data['slug'],
                        'name' => $data['name'],
                        'description' => $this->json($data['description']),
                        'city' => $data['city'] ?? 'Douala',
                        'district' => $data['district'] ?? null,
                        'avatar_path' => $data['avatar_path'] ?? null,
                    ],
                    'categories' => [
                        'parent_id' => null,
                        'slug' => $data['slug'],
                        'name' => $this->json($data['name']),
                    ],
                    'products' => [
                        'category_id' => $data['category_id'],
                        'slug' => $data['slug'],
                        'name' => $this->json($data['name']),
                        'description' => $this->json($data['description']),
                    ],
                    'offers' => [
                        'shop_id' => $data['shop_id'],
                        'variant_id' => $data['variant_id'],
                        'reference_price' => $data['reference_price'],
                        'selling_price' => $data['reference_price'],
                        'minimum_quantity' => $data['minimum_quantity'],
                        'warranty' => $this->json($data['warranty']),
                        ...$this->bulk($data),
                    ],
                };
                DB::table($entity)->insert($values);
            } else {
                DB::table($entity)->where('id', $id)->update($values);
            }
            app(BusinessEvents::class)->catalogueRevision(
                $actor,
                $entity,
                $id,
                $nextVersion,
                $row ? 'draft_saved' : 'created',
                $data,
            );

            return $this->dto($entity, $this->row($entity, $id));
        }, 3);
    }

    public function change(string $entity, string $id, int $version, string $action, User $actor): array
    {
        return DB::transaction(function () use ($entity, $id, $version, $action, $actor) {
            // Une publication de catégorie et une publication de produit ne doivent pas valider deux grilles d'attributs différentes.
            if (in_array($entity, ['categories', 'products', 'offers'], true)) {
                DB::table('categories')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get(['id']);
            }
            $row = $this->row($entity, $id, true);
            abort_unless((int) $row->version === $version, 409);
            $data = $this->draft($entity, $row);
            $commercial = null;
            if ($action === 'publish') {
                $data = app(CatalogueRules::class)->validate($entity, $data, true);
                $this->checkSlug($entity, $data, $id);
                $values = match ($entity) {
                    'suppliers' => ['status' => 'active'],
                    'shops' => $this->publishShop($row, $data),
                    'categories' => $this->publishCategory($row, $data),
                    'products' => $this->publishProduct($row, $data),
                    'offers' => $this->publishOffer($row, $data, $actor, $commercial),
                    default => abort(404),
                };
                if (
                    isset($data['slug']) &&
                    $row->slug !== $data['slug'] &&
                    ($row->published_version || $row->status === 'published')
                ) {
                    DB::table('slug_redirects')->updateOrInsert(
                        ['entity' => $entity, 'slug' => $row->slug],
                        ['resource_id' => $id, 'created_at' => now()],
                    );
                }
            } else {
                $values = $this->archive($entity, $row);
            }
            $values += ['version' => $version + 1, 'updated_at' => now()];
            if ($entity !== 'suppliers' && $action === 'publish') {
                $values['published_version'] = $version + 1;
            }
            DB::table($entity)->where('id', $id)->update($values);
            if ($entity === 'categories' && $action === 'publish') {
                $this->verifyCategoryProducts($id);
            }
            app(TextIndexer::class)->changed($entity, $id);
            app(PhotoIndexer::class)->changed($entity, $id);
            app(BusinessEvents::class)->catalogueRevision($actor, $entity, $id, $version + 1, $action, [
                'draft' => $data,
                'commercial' => $commercial,
            ]);
            app(BusinessEvents::class)->outbox('ContentPublished', $entity.':'.$id.':'.($version + 1), [
                'entity' => $entity,
                'id' => $id,
                'version' => $version + 1,
                'action' => $action,
            ]);

            return $this->dto($entity, $this->row($entity, $id));
        }, 3);
    }

    public function previewOffer(array $data, int $quantity): array
    {
        if ($quantity < (int) $data['minimum_quantity']) {
            $this->fail('quantity', 'MINIMUM_QUANTITY');
        }
        $shop = $this->row('shops', $data['shop_id']);
        $variant = DB::table('variants')->find($data['variant_id']);
        abort_unless($variant, 422);
        $product = $this->row('products', $variant->product_id);
        $at = CarbonImmutable::now('UTC');
        $promotion = $data['promotion'] ?? null;
        $start = $promotion ? CarbonImmutable::parse($promotion['starts_at']) : null;
        $end = $promotion ? CarbonImmutable::parse($promotion['ends_at']) : null;
        $active = $promotion && $at->greaterThanOrEqualTo($start) && $at->lessThan($end);
        $resolver = app(AgreementResolver::class);
        $quote = $resolver->quote(
            $shop->supplier_id,
            $product->id,
            $product->category_id,
            (int) $data['reference_price'],
            (int) ($active ? $promotion['price'] : $data['reference_price']),
            $quantity,
            $at,
        );
        $quote['promotion'] = $promotion;
        $quote['scheduled_promotion'] =
            $promotion && $at->lessThan($start)
                ? $resolver->quote(
                    $shop->supplier_id,
                    $product->id,
                    $product->category_id,
                    (int) $data['reference_price'],
                    (int) $promotion['price'],
                    $quantity,
                    $start,
                )
                : null;

        return $quote;
    }

    /** Wholesale packaging: all three values, or none (not sold in bulk). */
    private function bulk(array $data): array
    {
        $unit = $data['bulk_unit'] ?? null;

        return [
            'bulk_unit' => $unit ?: null,
            'bulk_unit_size' => $unit ? (int) $data['bulk_unit_size'] : null,
            'bulk_min_units' => $unit ? (int) $data['bulk_min_units'] : null,
        ];
    }

    private function publishShop(object $row, array $data): array
    {
        $supplier = $this->row('suppliers', $data['supplier_id'], true);
        if ($supplier->status !== 'active') {
            $this->fail('supplier_id', 'SUPPLIER_INACTIVE');
        }
        if ($row->published_version !== null && $row->supplier_id !== $data['supplier_id']) {
            $this->fail('supplier_id', 'ASSOCIATION_LOCKED');
        }
        // Profile photo: optional, but only an approved public image from the media library.
        $avatar = trim((string) ($data['avatar_path'] ?? '')) ?: null;
        if ($avatar !== null && ! app(MediaLibrary::class)->approvedPath($avatar)) {
            $this->fail('avatar_path', 'IMAGE_REQUIRED');
        }

        return [
            'supplier_id' => $data['supplier_id'],
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $this->json($data['description']),
            'city' => trim((string) ($data['city'] ?? '')) ?: 'Douala',
            'district' => trim((string) ($data['district'] ?? '')) ?: null,
            'avatar_path' => $avatar,
            'published' => true,
            'status' => 'published',
        ];
    }

    private function publishCategory(object $row, array $data): array
    {
        if ($data['parent_id']) {
            $parent = $this->row('categories', $data['parent_id']);
            if (
                ! $parent->published ||
                in_array($row->id, array_column(app(CategoryAttributes::class)->ancestry($parent->id), 'id'), true)
            ) {
                $this->fail('parent_id', 'CATEGORY_TREE');
            }
        }

        return [
            'parent_id' => $data['parent_id'],
            'slug' => $data['slug'],
            'name' => $this->json($data['name']),
            'attribute_definitions' => $this->json($data['attributes']),
            'published' => true,
            'status' => 'published',
        ];
    }

    private function publishProduct(object $row, array $data): array
    {
        $category = $this->row('categories', $data['category_id']);
        if (! $category->published) {
            $this->fail('category_id', 'TARGET_UNPUBLISHED');
        }
        $ids = [];
        foreach ($data['variants'] as $variant) {
            $existing = DB::table('variants')->find($variant['id']);
            if ($existing && $existing->product_id !== $row->id) {
                $this->fail('variants', 'VARIANT_OWNERSHIP');
            }
            if (DB::table('variants')->where('sku', $variant['sku'])->where('id', '!=', $variant['id'])->exists()) {
                $this->fail('variants', 'SKU_DUPLICATE');
            }
            $attributes = app(CategoryAttributes::class)->values($data['category_id'], $variant['attributes']);
            DB::table('variants')->updateOrInsert(
                ['id' => $variant['id']],
                [
                    'product_id' => $row->id,
                    'sku' => $variant['sku'],
                    'attributes' => $this->json((object) $attributes),
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => $existing?->created_at ?? now(),
                ],
            );
            $ids[] = $variant['id'];
        }
        DB::table('variants')
            ->where('product_id', $row->id)
            ->whereNotIn('id', $ids)
            ->update(['active' => false, 'updated_at' => now()]);

        return [
            'category_id' => $data['category_id'],
            'slug' => $data['slug'],
            'name' => $this->json($data['name']),
            'description' => $this->json($data['description']),
            'status' => 'published',
        ];
    }

    private function publishOffer(object $row, array $data, User $actor, ?array &$commercial): array
    {
        $shop = $this->row('shops', $data['shop_id']);
        $supplier = $this->row('suppliers', $shop->supplier_id, true);
        $variant = DB::table('variants')->find($data['variant_id']);
        $product = $variant ? $this->row('products', $variant->product_id) : null;
        if (
            ! $shop->published ||
            $supplier->status !== 'active' ||
            ! $variant?->active ||
            $product?->status !== 'published'
        ) {
            $this->fail('variant_id', 'TARGET_UNPUBLISHED');
        }
        if (
            ! app(MediaLibrary::class)->approvedPath($data['image_path'] ?? null) ||
            $data['image_path'] === '/brand/logo.png'
        ) {
            $this->fail('image_path', 'IMAGE_REQUIRED');
        }
        $commercial = $this->previewOffer($data, (int) $data['minimum_quantity']);
        if (
            ($commercial['negative_margin'] || ($commercial['scheduled_promotion']['negative_margin'] ?? false)) &&
            (empty($data['promotion']['negative_margin_reason']) ||
                ! in_array('pricing.loss_override', $actor->permissions(), true))
        ) {
            $this->fail('promotion.negative_margin_reason', 'NEGATIVE_MARGIN');
        }
        $promotion = $data['promotion'] ?? null;

        return [
            'shop_id' => $data['shop_id'],
            'variant_id' => $data['variant_id'],
            'reference_price' => $data['reference_price'],
            'selling_price' => $data['reference_price'],
            'minimum_quantity' => $data['minimum_quantity'],
            'availability' => $data['availability'],
            'warranty' => $this->json($data['warranty']),
            'image_path' => $data['image_path'],
            ...$this->bulk($data),
            'status' => 'published',
            'promotion_price' => $promotion['price'] ?? null,
            'promotion_starts_at' => $promotion
                ? CarbonImmutable::parse($promotion['starts_at'])->utc()->format('Y-m-d H:i:s')
                : null,
            'promotion_ends_at' => $promotion
                ? CarbonImmutable::parse($promotion['ends_at'])->utc()->format('Y-m-d H:i:s')
                : null,
            'promotion_funding' => $promotion ? $this->json($promotion) : null,
        ];
    }

    private function archive(string $entity, object $row): array
    {
        if (
            $entity === 'suppliers' &&
            DB::table('shops')->where('supplier_id', $row->id)->where('published', true)->exists()
        ) {
            $this->fail('status', 'DEPENDENCIES_PUBLISHED');
        }
        if (
            $entity === 'categories' &&
            (DB::table('categories')->where('parent_id', $row->id)->where('published', true)->exists() ||
                DB::table('products')->where('category_id', $row->id)->where('status', 'published')->exists())
        ) {
            $this->fail('status', 'DEPENDENCIES_PUBLISHED');
        }

        return in_array($entity, ['shops', 'categories'], true)
            ? ['status' => 'archived', 'published' => false]
            : ['status' => 'archived'];
    }

    private function verifyCategoryProducts(string $id): void
    {
        // Valider également les descendants : un attribut obligatoire ne rend pas les offres déjà publiées incohérentes.
        foreach (
            DB::table('categories')
                ->where('published', true)
                ->get(['id']) as $category
        ) {
            if (! in_array($id, array_column(app(CategoryAttributes::class)->ancestry($category->id), 'id'), true)) {
                continue;
            }
            app(CategoryAttributes::class)->definitions($category->id);
            $variants = DB::table('variants')
                ->join('products', 'products.id', '=', 'variants.product_id')
                ->where('products.category_id', $category->id)
                ->where('products.status', 'published')
                ->where('variants.active', true)
                ->get(['variants.attributes']);
            foreach ($variants as $variant) {
                app(CategoryAttributes::class)->values($category->id, json_decode($variant->attributes, true));
            }
        }
    }

    private function prepareVariants(array $data, string $id, array $previous): array
    {
        $draftIds = array_column($previous['variants'] ?? [], 'id');
        foreach ($data['variants'] as &$variant) {
            $existing = DB::table('variants')->find($variant['id']);
            if ($existing && $existing->product_id !== $id) {
                $this->fail('variants', 'VARIANT_OWNERSHIP');
            }
            if (! $existing && ! in_array($variant['id'], $draftIds, true)) {
                $variant['id'] = (string) Str::uuid();
            }
            $variant['attributes'] = app(CategoryAttributes::class)->values(
                $data['category_id'],
                $variant['attributes'],
                false,
            );
        }
        unset($variant);

        return $data;
    }

    private function checkSlug(string $entity, array $data, string $id): void
    {
        if (! isset($data['slug'])) {
            return;
        }
        if (
            DB::table($entity)->where('slug', $data['slug'])->where('id', '!=', $id)->exists() ||
            DB::table('slug_redirects')
                ->where('entity', $entity)
                ->where('slug', $data['slug'])
                ->where('resource_id', '!=', $id)
                ->exists()
        ) {
            $this->fail('slug', 'SLUG_TAKEN');
        }
    }

    private function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => __('catalogue.'.$message)]);
    }
}
