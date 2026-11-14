<?php

namespace App\Domain\Professional;

use App\Domain\Cart\CartConditions;
use App\Domain\Cart\Carts;
use App\Domain\Delivery\CustomerAddresses;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Private checkout carts isolate a company's quote from the customer's mutable personal shopping cart. */
final class ProfessionalCart
{
    public function create(array $lines, array $address, User $actor): array
    {
        $cartId = Records::id();
        DB::table('carts')->insert([
            'id' => $cartId,
            'guest_owner_hash' => hash('sha256', random_bytes(32)),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $cart = Records::row('carts', $cartId);
        foreach (collect($lines)->sortBy('offer_id') as $line) {
            app(Carts::class)->add($cart, $line['offer_id'], $line['quantity']);
        }
        $addressId = Records::id();
        // Private quote copy: archived from the personal address book, kept by the delivery quote's FK.
        DB::table('customer_addresses')->insert([
            ...app(CustomerAddresses::class)->values($address),
            'id' => $addressId,
            'user_id' => $actor->id,
            'archived_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$cart, Records::row('customer_addresses', $addressId, false)];
    }

    public function assertConforming(array $agreed, array $current): void
    {
        $keys = array_flip(['offer_id', 'variant_id', 'product_id', 'shop_id', 'attributes', 'warranty']);
        if (
            app(CommandBus::class)->fingerprint(array_intersect_key($agreed, $keys)) !==
            app(CommandBus::class)->fingerprint(array_intersect_key($current, $keys))
        ) {
            BusinessProfiles::fail('lines', 'TERMS_CHANGED');
        }
    }

    public function selected(array $selected, array $agreedLines): array
    {
        $agreed = collect($agreedLines)->keyBy('offer_id');
        if (count($selected) !== $agreed->count()) {
            BusinessProfiles::fail('lines', 'TERMS_CHANGED');
        }
        foreach ($selected as &$line) {
            $terms = $agreed->get($line['offer_id']);
            if (! $terms || $line['quantity'] !== $terms['quantity']) {
                BusinessProfiles::fail('lines', 'TERMS_CHANGED');
            }
            $current = app(CartConditions::class)->current($line['offer_id'], true);
            if (
                ! $current ||
                $current['availability'] === 'unavailable' ||
                $line['quantity'] < $current['minimum_quantity']
            ) {
                BusinessProfiles::fail('lines', 'TERMS_CHANGED');
            }
            $this->assertConforming($terms['conditions'], $current);
            $line['current_conditions'] = [...$current, 'unit_price' => (int) $terms['unit_price']];
            $line['professional_snapshot'] = $terms;
        }
        unset($line);

        return $selected;
    }
}
