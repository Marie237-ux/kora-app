<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Carts;
use App\Domain\Delivery\CustomerAddresses;
use App\Domain\Delivery\DeliveryQuotes;
use App\Domain\Delivery\DeliveryZones;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class DeliveryController extends OperationalController
{
    /** Public list so that a visitor can check a district before creating an account. */
    public function zones(DeliveryZones $zones)
    {
        return [
            'data' => DB::table('delivery_zones')
                ->where('active', true)
                ->orderBy('code')
                ->get()
                ->map(fn ($zone) => $zones->dto($zone, true)),
        ];
    }

    public function addresses(Request $http, CustomerAddresses $addresses)
    {
        return [
            'data' => DB::table('customer_addresses')
                ->where('user_id', $http->user()->id)
                ->whereNull('archived_at')
                ->orderByDesc('updated_at')
                ->orderBy('id')
                ->get()
                ->map(fn ($address) => $addresses->dto($address)),
        ];
    }

    private function addressData(Request $http, bool $update): array
    {
        return $http->validate([
            ...$update ? ['version' => ['required', 'integer', 'min:1']] : [],
            'label' => ['required', 'string', 'max:80'],
            'district' => ['required', 'string', 'max:120'],
            'landmark' => ['required', 'string', 'max:300'],
            'instructions' => ['nullable', 'string', 'max:1000'],
            'latitude' => ['nullable', 'required_with:longitude', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'required_with:latitude', 'numeric', 'between:-180,180'],
            'contact_name' => ['required', 'string', 'max:150'],
            'contact_phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{6,20}$/'],
            'alternate_name' => ['nullable', 'required_with:alternate_phone', 'string', 'max:150'],
            'alternate_phone' => ['nullable', 'required_with:alternate_name', 'string', 'regex:/^\+?[0-9 ]{6,20}$/'],
        ]);
    }

    public function createAddress(Request $http, CustomerAddresses $addresses)
    {
        $d = $this->addressData($http, false);

        return $this->command($http, 'address.create', $d, fn ($data) => $addresses->create($data, $http->user()), 201);
    }

    public function updateAddress(Request $http, string $id, CustomerAddresses $addresses)
    {
        $d = $this->addressData($http, true);

        return $this->command(
            $http,
            'address.update:'.$id,
            $d,
            fn ($data) => $addresses->update($addresses->owned($id, $http->user(), true), $data),
        );
    }

    public function archiveAddress(Request $http, string $id, CustomerAddresses $addresses)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command(
            $http,
            'address.archive:'.$id,
            $d,
            fn ($data) => $addresses->archive($addresses->owned($id, $http->user(), true), $data['version']),
        );
    }

    public function quote(Request $http, Carts $carts, CustomerAddresses $addresses, DeliveryQuotes $quotes)
    {
        $d = $http->validate([
            'cart_version' => ['required', 'integer', 'min:1'],
            'address_id' => ['required', 'uuid'],
        ]);

        return $this->command(
            $http,
            'delivery.quote',
            $d,
            function ($data) use ($http, $carts, $addresses, $quotes) {
                $cart = $carts->current($http, true);
                $carts->version($cart, $data['cart_version']);

                return $quotes->create($cart, $addresses->owned($data['address_id'], $http->user()), $http->user());
            },
            201,
        );
    }

    public function showQuote(Request $http, string $id, Carts $carts, DeliveryQuotes $quotes)
    {
        $cart = $carts->current($http);

        return [
            'data' => app(ReadSnapshot::class)->run(
                fn () => $quotes->dto($quotes->owned($id, $http->user()), Records::row('carts', $cart->id, false)),
            ),
        ];
    }

    public function slot(Request $http, string $id, Carts $carts, DeliveryQuotes $quotes)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'rule_id' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return $this->command($http, 'delivery.slot:'.$id, $d, function ($data) use ($http, $id, $carts, $quotes) {
            $quote = $quotes->owned($id, $http->user(), true);
            abort_unless((int) $quote->version === $data['version'], 409);

            return $quotes->holdSlot($quote, $carts->current($http), $data['rule_id'], $data['date']);
        });
    }
}
