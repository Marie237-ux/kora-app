<?php

namespace App\Domain\Catalogue;

use App\Domain\Inventory\Inventory;
use Illuminate\Support\Facades\DB;

/**
 * The service attached to a product on its page, built for this offer: whether it ships from a KORA warehouse
 * (stock on hand) or from the partner shop, how delivery works, the warranty and its steps, the bulk purchase
 * steps when the offer is sold in bulk, and the delivery zones served. Texts come from lang/{fr,en}/product_page.php.
 */
final class ProductService
{
    public function __construct(private Inventory $inventory) {}

    public function for(array $offer): array
    {
        $fromWarehouse = $this->inventory->warehouseAvailable($offer['id']) > 0;
        $city = $offer['shop']['city'] ?? 'Douala';
        $place = ['shop' => $offer['shop']['name'], 'district' => $offer['shop']['district'] ?? $city, 'city' => $city];
        $rows = fn (string $key) => array_map(
            fn ($row) => ['label' => $row[0], 'text' => $row[1]],
            __('product_page.'.$key, $place),
        );
        preg_match('/(\d+)\s*(mois|month)/i', (string) ($offer['warranty'] ?? ''), $m);

        return [
            'ships_from' => $fromWarehouse ? 'warehouse' : 'shop',
            'delivery' => [
                'intro' => __('product_page.delivery_intro', $place),
                'route' => $rows($fromWarehouse ? 'route_warehouse' : 'route_shop'),
                // Local delivery in the shop's city, or shipping to another city: chosen at payment.
                'modes' => array_map(fn ($m, $key) => ['key' => $key, ...$m], $rows('modes'), ['local', 'shipping']),
                'terms' => $rows('terms'),
            ],
            'warranty' => [
                'months' => isset($m[1]) ? (int) $m[1] : null,
                'title' => __('product_page.warranty_title'),
                'intro' => __('product_page.warranty_intro', $place),
                'steps' => $rows('warranty_steps'),
            ],
            'bulk' => $offer['bulk'] ? [
                'intro' => __('product_page.bulk_intro', $place),
                'steps' => $rows('bulk_steps'),
            ] : null,
            // Districts served by KORA couriers in the shop's city (zones are set per city in the back office).
            'zones_city' => $city,
            'zones' => DB::table('delivery_zones')->where('active', true)->where('city', $city)->orderBy('code')->get()
                ->map(fn ($z) => [
                    'name' => json_decode($z->name, true)[app()->getLocale()] ?? json_decode($z->name, true)['fr'] ?? $z->code,
                    'districts' => json_decode($z->districts, true) ?? [],
                ])->values()->all(),
        ];
    }
}
