<?php

namespace App\Http\Controllers;

use App\Domain\Cart\Carts;
use App\Domain\Delivery\DeliveryQuotes;
use App\Domain\Loyalty\LoyaltyCheckoutQuotes;
use App\Domain\Loyalty\LoyaltyRedemptionPolicy;
use App\Domain\Procurement\Records;
use App\Domain\Shared\ReadSnapshot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class LoyaltyCheckoutController extends OperationalController
{
    public function policy(LoyaltyRedemptionPolicy $policy)
    {
        return ['data' => $policy->current()];
    }

    public function settings(LoyaltyRedemptionPolicy $policy)
    {
        return ['data' => app(ReadSnapshot::class)->run(fn () => $policy->current(true))];
    }

    public function revisions(Request $http)
    {
        return $this->page(
            DB::table('loyalty_redemption_revisions')->orderByDesc('version'),
            $http,
            fn ($r) => [
                'version' => (int) $r->version,
                'enabled' => (bool) $r->enabled,
                'rules' => json_decode($r->rules, true),
                'sha256' => $r->sha256,
                'created_at' => Records::iso($r->created_at),
            ],
        );
    }

    public function save(Request $http, LoyaltyRedemptionPolicy $policy)
    {
        $d = $http->validate([
            'version' => ['required', 'integer', 'min:1'],
            'enabled' => ['required', 'boolean'],
            'rules' => [
                'required',
                'array:points_per_unit,value_per_unit_xaf,max_discount_basis_points,minimum_payable_xaf,excluded_category_ids,budget_xaf,restore_points_on_refund,restore_valid_days,combine_delivery_promotions,allow_negative_margin,loss_reason,conditions',
            ],
            'rules.points_per_unit' => ['required', 'integer', 'between:1,1000000000'],
            'rules.value_per_unit_xaf' => ['required', 'string'],
            'rules.max_discount_basis_points' => ['required', 'integer', 'between:1,10000'],
            'rules.minimum_payable_xaf' => ['required', 'string'],
            'rules.excluded_category_ids' => ['present', 'array', 'max:500'],
            'rules.excluded_category_ids.*' => ['uuid', 'distinct', 'exists:categories,id'],
            'rules.budget_xaf' => ['required', 'string'],
            'rules.restore_points_on_refund' => ['required', 'boolean'],
            'rules.restore_valid_days' => ['present', 'nullable', 'integer', 'between:1,3650'],
            'rules.combine_delivery_promotions' => ['required', 'boolean'],
            'rules.allow_negative_margin' => ['required', 'boolean'],
            'rules.loss_reason' => ['present', 'nullable', 'string', 'max:1000'],
            'rules.conditions' => ['required', 'array:fr,en'],
            'rules.conditions.fr' => ['required', 'string', 'max:5000'],
            'rules.conditions.en' => ['required', 'string', 'max:5000'],
        ]);

        return $this->command($http, 'loyalty.redemption_policy', $d, fn ($data) => $policy->save($data, $http->user()));
    }

    public function quote(Request $http, Carts $carts, DeliveryQuotes $deliveries, LoyaltyCheckoutQuotes $quotes)
    {
        $d = $http->validate([
            'cart_version' => ['required', 'integer', 'min:1'],
            'delivery_quote_id' => ['required', 'uuid'],
            'points' => ['required', 'integer', 'between:1,1000000000'],
        ]);

        return $this->command(
            $http,
            'loyalty.checkout_quote',
            $d,
            function ($data) use ($http, $carts, $deliveries, $quotes) {
                $cart = $carts->current($http, true);
                $carts->version($cart, $data['cart_version']);

                return $quotes->create(
                    $cart,
                    $deliveries->owned($data['delivery_quote_id'], $http->user(), true),
                    (int) $data['points'],
                    $http->user(),
                );
            },
            201,
        );
    }
}
