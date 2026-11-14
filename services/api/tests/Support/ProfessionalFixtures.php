<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;

/** Test-only company data; requires DeliveryFixtures, OrderFixtures and ProcurementFixtures. */
trait ProfessionalFixtures
{
    protected function professionalRequest(int $quantity = 2): array
    {
        $n = $this->network();
        $this->inventoryPolicy();
        $rule = $this->slotRule();
        $client = $this->client();
        $profile = $this->api('POST', 'professional/profiles', [
            'legal_name' => 'Entreprise fictive de test',
            'billing_address' => 'Adresse de facturation fictive',
            'niu' => 'TEST-NIU-BUYER',
            'rccm' => 'TEST-RCCM-BUYER',
            'email' => 'billing@example.test',
            'phone' => null,
        ])
            ->assertCreated()
            ->json('data');
        $address = $this->api('POST', 'professional/profiles/'.$profile['id'].'/addresses', [
            'label' => 'Entreprise (test)',
            'district' => 'Akwa',
            'landmark' => 'Repère initial fictif',
            'contact_name' => 'Contact de test',
            'contact_phone' => '+237 600 000 000',
        ])
            ->assertCreated()
            ->json('data');
        $cart = $this->fill([[$n['second']['offer'], $quantity]]);
        $quote = $this->api('POST', 'professional/profiles/'.$profile['id'].'/quotes', [
            'cart_version' => $cart['version'],
            'address_id' => $address['id'],
            'notes' => 'Demande de test uniquement',
        ])
            ->assertCreated()
            ->json('data');

        return compact('n', 'rule', 'client', 'profile', 'address', 'cart', 'quote');
    }

    protected function professionalPath(array $c, ?string $quoteId = null): string
    {
        return 'professional/profiles/'.$c['profile']['id'].'/quotes/'.($quoteId ?? $c['quote']['id']);
    }

    protected function issueProfessional(array $c, string $unit = '1000', array $changes = [])
    {
        return $this->op('POST', 'professional-quotes/'.$c['quote']['id'].'/issue', [
            'version' => $c['quote']['version'],
            'valid_until' => now()->addDay()->setTimezone('Africa/Douala')->toIso8601String(),
            'conditions' => [
                'fr' => 'Conditions de test : paiement avant livraison.',
                'en' => 'Test terms: prepaid delivery.',
            ],
            'lines' => [['offer_id' => $c['n']['second']['offer'], 'unit_price' => $unit]],
            ...$changes,
        ]);
    }

    protected function latestProfessional(array $quote): array
    {
        return $quote['revisions'][array_key_last($quote['revisions'])];
    }

    protected function acceptProfessional(array $c, array $quote)
    {
        $revision = $this->latestProfessional($quote);

        return $this->api('POST', $this->professionalPath($c).'/accept', [
            'version' => $quote['version'],
            'revision_id' => $revision['id'],
            'accepted_total' => $revision['snapshot']['totals']['total'],
            'supplier_unreserved_acknowledged' => true,
        ]);
    }

    protected function convertProfessional(array $c, array $quote)
    {
        return $this->api('POST', $this->professionalPath($c).'/convert', [
            'version' => $quote['version'],
            'accepted_total' => $this->latestProfessional($quote)['snapshot']['totals']['total'],
            'rule_id' => $c['rule']['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ]);
    }
}
