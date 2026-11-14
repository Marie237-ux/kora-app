<?php

namespace App\Domain\Search;

use App\Domain\Catalogue\CategoryAttributes;
use App\Domain\Catalogue\PublicCatalogue;
use Illuminate\Support\Facades\DB;

final class TextIndexer
{
    public function changed(string $entity, string $id): void
    {
        $q = DB::table('offers')
            ->join('variants', 'variants.id', '=', 'offers.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id');
        match ($entity) {
            'offers' => $q->where('offers.id', $id),
            'products' => $q->where('products.id', $id),
            'shops' => $q->where('offers.shop_id', $id),
            'suppliers' => $q->join('shops', 'shops.id', '=', 'offers.shop_id')->where('shops.supplier_id', $id),
            // Category names/attributes are inherited; refresh all affected live and archived rows safely.
            'categories' => null,
        };
        foreach ($q->orderBy('offers.id')->pluck('offers.id') as $offerId) {
            $this->offer($offerId);
        }
    }

    public function offer(string $id): void
    {
        DB::transaction(function () use ($id) {
            // Same first lock as normal offer publication. A reindex cannot overwrite a newer publication.
            DB::table('offers')->where('id', $id)->lockForUpdate()->first();
            $row = app(PublicCatalogue::class)
                ->query()
                ->where('offers.id', $id)
                ->addSelect('products.category_id')
                ->first();
            DB::table('catalogue_search_documents')->where('offer_id', $id)->delete();
            if (! $row) {
                return;
            }
            $weights = [];
            $normalizer = app(SearchText::class);
            $add = function (mixed $value, int $weight) use (&$weights, $normalizer, &$add) {
                if (is_array($value) || is_object($value)) {
                    foreach ((array) $value as $item) {
                        $add($item, $weight);
                    }

                    return;
                }
                if (! is_string($value) && ! is_numeric($value)) {
                    return;
                }
                foreach ($normalizer->tokens((string) $value) as $word) {
                    $weights[$word] = max($weight, $weights[$word] ?? 0);
                }
            };
            $add(json_decode($row->name, true), 8);
            $add($row->sku, 10);
            $add($row->shop_name, 4);
            $add(json_decode($row->description, true), 1);
            $attributes = app(CategoryAttributes::class);
            foreach ($attributes->ancestry($row->category_id) as $category) {
                $add(json_decode($category->name, true), 4);
            }
            $values = json_decode($row->attributes, true);
            foreach ($attributes->definitions($row->category_id) as $definition) {
                if (! array_key_exists($definition['key'], $values)) {
                    continue;
                }
                $add($definition['label'], 3);
                $add($definition['unit'] ?? '', 3);
                if (is_bool($values[$definition['key']])) {
                    continue;
                }
                $add($values[$definition['key']], 3);
                foreach ($definition['options'] ?? [] as $option) {
                    if ($option['value'] === $values[$definition['key']]) {
                        $add($option['label'], 3);
                    }
                }
            }
            arsort($weights);
            $weights = array_slice($weights, 0, 400, true);
            DB::table('catalogue_search_documents')->insert(['offer_id' => $id, 'indexed_at' => now()]);
            if ($weights) {
                DB::table('catalogue_search_tokens')->insert(
                    array_map(
                        fn ($word, $weight) => ['offer_id' => $id, 'token' => $word, 'weight' => $weight],
                        array_keys($weights),
                        array_values($weights),
                    ),
                );
            }
        }, 3);
    }

    public function rebuild(): int
    {
        $count = 0;
        DB::table('offers')
            ->orderBy('id')
            ->chunkById(100, function ($rows) use (&$count) {
                foreach ($rows as $row) {
                    $this->offer($row->id);
                    $count++;
                }
            });

        return $count;
    }
}
