<?php

namespace App\Domain\Cart;

use App\Domain\Catalogue\PublicCatalogue;
use App\Domain\Shared\CommandBus;
use Illuminate\Support\Facades\DB;

final class CartConditions
{
    public function current(string $offerId, bool $lock = false): ?array
    {
        return $this->view($offerId, $lock)['conditions'] ?? null;
    }

    public function view(string $offerId, bool $lock = false): ?array
    {
        if ($lock) {
            DB::table('offers')->where('id', $offerId)->lockForUpdate()->first();
        }
        $row = app(PublicCatalogue::class)
            ->query()
            ->where('offers.id', $offerId)
            ->addSelect('offers.variant_id', 'variants.product_id')
            ->first();
        if (! $row) {
            return null;
        }
        $conditions = [
            'offer_id' => $row->id,
            'variant_id' => $row->variant_id,
            'product_id' => $row->product_id,
            'shop_id' => $row->shop_id,
            'unit_price' => (int) $row->selling_price,
            'currency' => 'XAF',
            'minimum_quantity' => (int) $row->minimum_quantity,
            'availability' => $row->availability,
            'attributes' => json_decode($row->attributes, true),
            'warranty' => json_decode($row->warranty, true),
        ];

        return ['conditions' => $conditions, 'offer' => app(PublicCatalogue::class)->dto($row, app()->getLocale())];
    }

    public function hash(array $conditions): string
    {
        return app(CommandBus::class)->fingerprint($conditions);
    }

    public function differences(array $saved, ?array $current): array
    {
        if (! $current) {
            return ['unavailable'];
        }
        $changes = [];
        foreach ($current as $key => $value) {
            if (
                app(CommandBus::class)->fingerprint(['value' => $saved[$key] ?? null]) !==
                app(CommandBus::class)->fingerprint(['value' => $value])
            ) {
                $changes[] = $key;
            }
        }

        return $changes;
    }
}
