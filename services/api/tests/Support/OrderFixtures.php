<?php

namespace Tests\Support;

use App\Domain\Payments\PaymentGateway;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;

/** Checkout, payment and refund steps shared by order and document tests; needs DeliveryFixtures. */
trait OrderFixtures
{
    protected FakePaymentGateway $gateway;

    /** Call from setUp(): every payment goes to a scripted provider, never to a network. */
    protected function useFakeGateway(): void
    {
        $this->gateway = new FakePaymentGateway;
        $this->app->instance(PaymentGateway::class, $this->gateway);
    }

    protected function slotRule(): array
    {
        return $this->op('POST', 'delivery/slot-rules', [
            'zone_id' => null,
            'weekday' => CarbonImmutable::now('Africa/Douala')->addDay()->dayOfWeekIso,
            'starts_at' => '10:00',
            'ends_at' => '12:00',
            'capacity' => 3,
            'cutoff_minutes' => 0,
            'active' => true,
        ])
            ->assertCreated()
            ->json('data');
    }

    /**
     * One supplier-collected offer at 1 000 XAF and a 1 500 XAF delivery to Akwa: accepted total 2 500.
     * A second, unselected line stays in the cart to prove that only purchased lines leave it.
     */
    protected function checkout(bool $holdSlot = true): array
    {
        $n = $this->network();
        $this->inventoryPolicy();
        $rule = $this->slotRule();
        $client = $this->client();
        $this->fill([[$n['second']['offer'], 1]]);
        $this->api('POST', 'cart/lines', [
            'version' => $this->getJson('/api/v1/cart')->json('data.version'),
            'offer_id' => $n['first']['offer'],
            'quantity' => 1,
            'selected' => false,
        ])->assertCreated();
        $quote = $this->quote($this->getJson('/api/v1/cart')->json('data'), $this->address('Akwa'))
            ->assertJsonPath('data.total', '1500')
            ->json('data');
        if ($holdSlot) {
            $quote = $this->api('POST', 'cart/delivery-quotes/'.$quote['id'].'/slot', [
                'version' => $quote['version'],
                'rule_id' => $rule['id'],
                'date' => CarbonImmutable::now('Africa/Douala')->addDay()->toDateString(),
            ])
                ->assertOk()
                ->json('data');
        }

        return compact('n', 'rule', 'client', 'quote');
    }

    protected function order(array $quote, string $total = '2500')
    {
        return $this->api('POST', 'orders', [
            'quote_id' => $quote['id'],
            'quote_version' => $quote['version'],
            'accepted_total' => $total,
        ]);
    }

    protected function pay(array $order): array
    {
        return $this->api('POST', 'orders/'.$order['id'].'/payments')
            ->assertCreated()
            ->json('data');
    }

    protected function notify(array $fields)
    {
        $this->app['auth']->forgetGuards();

        return $this->post('/api/v1/payments/monetbil/notify', $fields);
    }

    protected function refundEvidence(): string
    {
        return $this->post('/api/v1/operations/documents', [
            'file' => UploadedFile::fake()->createWithContent(
                'proof.pdf',
                "%PDF-1.4\n% Test-only refund evidence, no real transfer.\n%%EOF",
            ),
            'purpose' => 'customer_payment_evidence',
        ])
            ->assertCreated()
            ->json('data.id');
    }

    protected function refund(array $order, string $amount, ?string $incidentId = null)
    {
        return $this->op('POST', 'orders/'.$order['id'].'/refunds', [
            'amount' => $amount,
            'reason' => 'Test refund only',
            'incident_id' => $incidentId,
        ]);
    }

    protected function transfer(array $refund, string $amount, string $reference)
    {
        return $this->op('POST', 'refunds/'.$refund['id'].'/verify', [
            'amount' => $amount,
            'channel' => 'mobile_money',
            'reference' => $reference,
            'evidence_id' => $this->refundEvidence(),
            'paid_at' => now()->subMinute()->toIso8601String(),
        ]);
    }
}
