<?php

namespace App\Http\Controllers;

use App\Domain\Procurement\Records;
use App\Domain\Promotions\DeliveryOffers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ManagePromotionsController extends OperationalController
{
    public function index(Request $http, DeliveryOffers $offers)
    {
        return $this->page(
            DB::table('delivery_offers')->orderByDesc('created_at')->orderBy('id'),
            $http,
            fn ($offer) => $offers->dto($offer),
        );
    }

    public function save(Request $http, DeliveryOffers $offers, ?string $id = null)
    {
        $d = $http->validate([
            ...$id ? ['version' => ['required', 'integer', 'min:1']] : [],
            'name' => ['required', 'array:fr,en'],
            'name.fr' => ['required', 'string', 'max:150'],
            'name.en' => ['required', 'string', 'max:150'],
            'starts_at' => ['required', 'date', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'ends_at' => ['nullable', 'date', 'after:starts_at', 'regex:/T.*(Z|[+-]\d\d:\d\d)$/'],
            'zone_ids' => ['required', 'array', 'min:1', 'max:200'],
            'zone_ids.*' => ['required', 'uuid', 'distinct'],
            'min_unit_price' => ['required', 'string'],
            'uses_per_customer' => ['required', 'integer', 'min:1', 'max:100'],
            'coverage' => ['required', 'in:full,base'],
            'budget' => ['required', 'string'],
            'funded_by' => ['required', 'in:kora'],
        ]);

        return $this->command(
            $http,
            'promotion.delivery_offer:'.($id ?? 'new'),
            $d,
            function ($data) use ($http, $offers, $id) {
                $offer = $id ? Records::row('delivery_offers', $id) : null;
                abort_if($offer && (int) $offer->version !== $data['version'], 409);

                return $offers->save($offer, $data, $http->user());
            },
            $id ? 200 : 201,
        );
    }

    public function transition(Request $http, string $id, string $action, DeliveryOffers $offers)
    {
        $d = $http->validate(['version' => ['required', 'integer', 'min:1']]);

        return $this->command($http, 'promotion.delivery_offer_'.$action.':'.$id, $d, function ($data) use (
            $http,
            $id,
            $action,
            $offers,
        ) {
            $offer = Records::row('delivery_offers', $id);
            abort_if((int) $offer->version !== $data['version'], 409);

            return $offers->transition($offer, $action, $http->user());
        });
    }
}
