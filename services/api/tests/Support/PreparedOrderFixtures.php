<?php

namespace Tests\Support;

use Carbon\CarbonImmutable;

/** A paid order served from KORA warehouse stock, and the preparation calls that make it ready. */
trait PreparedOrderFixtures
{
    /** KORA warehouse stock at 1 000 XAF a unit, 1 000 XAF delivery inside the zone; the order is paid. */
    protected function warehouseOrder(int $quantity = 2, string $tracking = 'none', array $identifiers = []): array
    {
        $this->operationUser();
        $ctx = $this->fixtureCatalogue();
        $this->op('PUT', 'inventory/profiles/'.$ctx['variant'], [
            'version' => 1,
            'tracking_mode' => $tracking,
            'requires_expiry' => false,
            'logistics' => [
                'mass_grams' => 1000,
                'length_mm' => null,
                'width_mm' => null,
                'height_mm' => null,
                'transport_class' => 'standard',
                'handling' => ['fr' => '', 'en' => ''],
            ],
        ])->assertOk();
        $purchase = $this->purchase($ctx, $this->contract($ctx));
        $location = $this->location();
        $stock = $identifiers ? count($identifiers) : 5;
        $this->receipt($ctx, $purchase, $location, $stock, $identifiers ? ['identifiers' => $identifiers] : []);
        $zone = $this->zone('SOUTH', ['Akwa']);
        $this->point($zone, ['warehouse_id' => $location['warehouse_id']]);
        $this->grid([[$zone, 1000]]);
        $this->inventoryPolicy();
        $rule = $this->slotRule();
        $client = $this->client();
        $quote = $this->quote($this->fill([[$ctx['offer'], $quantity]]), $this->address('Akwa'))
            ->assertJsonPath('data.lines.0.from_warehouse', $quantity)
            ->json('data');
        $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
            'version' => $quote['version'],
            'rule_id' => $rule['id'],
            'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
        ])->json('data');
        $total = (string) ($quantity * 1000 + 1000);
        $order = $this->order($quote, $total)->assertCreated()->json('data');
        $attempt = $this->pay($order);
        $tx = 'TEST-'.$this->fixtureId();
        $this->gateway->paid($tx, $attempt['id'], (int) $total);
        $this->notify([
            'transaction_id' => $tx,
            'payment_ref' => $attempt['id'],
            'sign' => 'valid-test-signature',
        ])->assertOk();
        $stockUser = $this->operationUser('stock');

        return compact('ctx', 'client', 'order', 'location', 'stockUser');
    }

    protected function preparation(array $order): array
    {
        return $this->op('GET', 'orders/'.$order['id'].'/preparation')
            ->assertOk()
            ->json('data');
    }

    protected function pick(array $order, string $reservation, array $identifiers = [], ?int $version = null)
    {
        return $this->op('POST', 'orders/'.$order['id'].'/preparation/picks', [
            'version' => $version ?? $this->preparation($order)['version'],
            'reservation_id' => $reservation,
            'identifiers' => $identifiers,
        ]);
    }

    protected function ready(array $order, int $packages = 1)
    {
        return $this->op('POST', 'orders/'.$order['id'].'/preparation/ready', [
            'version' => $this->preparation($order)['version'],
            'packages' => $packages,
        ]);
    }

    /** Picks every retained reservation as the stock team, then declares the order ready. */
    protected function readyOrder(array $order, int $packages = 1): array
    {
        $this->operationUser('stock');
        foreach ($this->preparation($order)['lines'] as $line) {
            foreach ($line['sources'] as $source) {
                $this->pick($order, $source['reservation_id'], $source['identifiers'])->assertCreated();
            }
        }

        return $this->ready($order, $packages)->assertOk()->json('data');
    }
}
