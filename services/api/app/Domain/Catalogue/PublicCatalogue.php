<?php

namespace App\Domain\Catalogue;

use App\Domain\Media\MediaLibrary;
use Illuminate\Support\Facades\DB;

final class PublicCatalogue
{
    public function query()
    {
        return DB::table('offers')
            ->join('variants', 'variants.id', '=', 'offers.variant_id')
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->join('shops', 'shops.id', '=', 'offers.shop_id')
            ->join('categories', 'categories.id', '=', 'products.category_id')
            ->where('offers.status', 'published')
            ->where('products.status', 'published')
            ->where('shops.published', true)
            ->where('variants.active', true)
            ->where('categories.published', true)
            ->select([
                'offers.id',
                'offers.availability',
                'offers.warranty',
                'offers.image_path',
                'offers.minimum_quantity',
                'offers.bulk_unit',
                'offers.bulk_unit_size',
                'offers.bulk_min_units',
                'offers.selling_price as regular_price',
                'products.id as product_id',
                'products.slug',
                'products.name',
                'products.description',
                'variants.sku',
                'variants.attributes',
                'shops.id as shop_id',
                'shops.name as shop_name',
                'shops.slug as shop_slug',
                'shops.city as shop_city',
                'shops.district as shop_district',
                'shops.avatar_path as shop_avatar',
                'categories.slug as category_slug',
            ])
            ->selectRaw(
                'CASE WHEN offers.promotion_price IS NOT NULL AND offers.promotion_starts_at <= ? AND offers.promotion_ends_at > ? THEN offers.promotion_price ELSE offers.selling_price END AS selling_price',
                [now(), now()],
            );
    }

    public function offer(string $id, string $locale): ?array
    {
        $row = $this->query()->where('offers.id', $id)->first();

        return $row ? $this->dto($row, $locale) : null;
    }

    public function dto(object $row, string $locale): array
    {
        return [
            'id' => $row->id,
            'product_id' => $row->product_id,
            'slug' => $row->slug,
            'name' => $this->localized($row->name, $locale),
            'description' => $this->localized($row->description, $locale),
            'sku' => $row->sku,
            'attributes' => (object) json_decode($row->attributes, true),
            'category' => $row->category_slug,
            'minimum_quantity' => (int) $row->minimum_quantity,
            'bulk' => $row->bulk_unit
                ? ['unit' => $row->bulk_unit, 'unit_size' => (int) $row->bulk_unit_size, 'min_units' => (int) $row->bulk_min_units]
                : null,
            'price' => ['amount' => (int) $row->selling_price, 'currency' => 'XAF'],
            // Shop price before an active promotion, so a sale can show the struck-through price; null otherwise.
            'compare_at_price' => isset($row->regular_price) && (int) $row->regular_price > (int) $row->selling_price ? (int) $row->regular_price : null,
            'availability' => $row->availability,
            'warranty' => $this->localized($row->warranty, $locale),
            'image' => app(MediaLibrary::class)->approvedPath($row->image_path) ? $row->image_path : null,
            'shop' => ['id' => $row->shop_id, 'name' => $row->shop_name, 'slug' => $row->shop_slug, 'city' => $row->shop_city ?? 'Douala', 'district' => $row->shop_district ?? null, 'avatar' => $row->shop_avatar ?? null],
        ];
    }

    public function localized(string|array $value, string $locale): string
    {
        $content = is_array($value) ? $value : json_decode($value, true);

        return $content[$locale] ?? '';
    }
}
