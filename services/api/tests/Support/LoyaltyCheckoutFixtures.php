<?php

namespace Tests\Support;

trait LoyaltyCheckoutFixtures
{
    protected function redemptionRules(array $changes = []): array
    {
        return [
            ...[
                'points_per_unit' => 100,
                'value_per_unit_xaf' => '500',
                'max_discount_basis_points' => 9000,
                'minimum_payable_xaf' => '1',
                'excluded_category_ids' => [],
                'budget_xaf' => '10000',
                'restore_points_on_refund' => true,
                'restore_valid_days' => 30,
                'combine_delivery_promotions' => false,
                'allow_negative_margin' => true,
                'loss_reason' => 'Fictitious owner-funded test campaign.',
                'conditions' => [
                    'fr' => 'Conditions d’échange fictives de test.',
                    'en' => 'Fictitious test redemption terms.',
                ],
            ],
            ...$changes,
        ];
    }

    protected function saveRedemption(array $changes = [], int $version = 1)
    {
        return $this->op('PUT', 'loyalty/redemption-policy', [
            'version' => $version,
            'enabled' => true,
            'rules' => $this->redemptionRules($changes),
        ]);
    }

    protected function loyaltyCheckout(
        int $quantity = 3,
        int $credit = 100,
        array $policy = [],
        array $gain = [],
    ): array {
        $this->operationUser();
        $this->op('PUT', 'loyalty/program', [
            'version' => 1,
            'enabled' => true,
            'rules' => [
                ...[
                    'earn_step_xaf' => '1000',
                    'earn_points' => 10,
                    'rounding' => 'floor',
                    'max_points_per_order' => 100,
                    'excluded_category_ids' => [],
                    'validation_delay_days' => 0,
                    'points_valid_days' => null,
                    'budget_points' => 1000,
                    'tier_window_days' => 365,
                    'include_professional' => false,
                    'tiers' => [['key' => 'test', 'label' => ['fr' => 'Test', 'en' => 'Test'], 'threshold_xaf' => '0']],
                    'conditions' => ['fr' => 'Gain de test.', 'en' => 'Test earnings.'],
                ],
                ...$gain,
            ],
        ])->assertOk();
        $this->saveRedemption($policy)->assertOk();
        $c = $this->checkout(false);
        $card = $this->api('POST', 'account/loyalty/card')->assertCreated()->json('data');
        if ($credit > 0) {
            $this->operationUser();
            $this->op('POST', 'loyalty/accounts/'.$card['id'].'/adjustments', [
                'points' => $credit,
                'explanation' => ['fr' => 'Crédit fictif de test.', 'en' => 'Test-only credit.'],
            ])->assertOk();
        }
        $this->client($c['client']);

        return $this->loyaltyCart([...$c, 'card' => $card], $quantity);
    }

    protected function loyaltyCart(array $c, int $quantity): array
    {
        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $line = collect($cart['lines'])->firstWhere('offer_id', $c['n']['second']['offer']);
        if ($line) {
            $this->api('PUT', 'cart/lines/'.$line['id'], [
                'version' => $cart['version'],
                'quantity' => $quantity,
                'selected' => true,
            ])->assertOk();
        } else {
            $this->api('POST', 'cart/lines', [
                'version' => $cart['version'],
                'offer_id' => $c['n']['second']['offer'],
                'quantity' => $quantity,
                'selected' => true,
            ])->assertCreated();
        }
        $cart = $this->getJson('/api/v1/cart')->assertOk()->json('data');
        $quote = $this->quote($cart, $this->address('Akwa'))->assertCreated()->json('data');
        // Time-travel may cross midnight: choose a real occurrence, not a different weekday's "tomorrow".
        $slot = collect($quote['slots'])->firstWhere('rule_id', $c['rule']['id']);
        $this->assertNotNull($slot, 'The test quote must expose a bookable occurrence of its configured rule.');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $c['rule']['id'],
            'date' => $slot['date'],
        ])
            ->assertOk()
            ->json('data');

        return [...$c, 'cart' => $cart, 'quote' => $quote];
    }

    protected function pointsQuote(array $c, int $points = 100)
    {
        return $this->api('POST', 'account/loyalty/checkout-quotes', [
            'cart_version' => $c['cart']['version'],
            'delivery_quote_id' => $c['quote']['id'],
            'points' => $points,
        ]);
    }

    protected function pointsOrder(array $c, array $q, array $changes = [])
    {
        return $this->api('POST', 'orders', [
            'quote_id' => $c['quote']['id'],
            'quote_version' => $c['quote']['version'],
            'accepted_total' => $q['total'],
            'loyalty_quote_id' => $q['id'],
            ...$changes,
        ]);
    }

    protected function payPointsOrder(array $order): array
    {
        $attempt = $this->pay($order);
        $tx = 'TEST-REDEEM-'.$order['id'];
        $this->gateway->paid($tx, $attempt['id'], (int) $order['total']);
        $this->notify([
            'transaction_id' => $tx,
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();

        return $attempt;
    }
}
