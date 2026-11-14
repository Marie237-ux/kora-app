<?php

namespace Tests\Feature;

use App\Domain\Payments\MonetbilGateway;
use App\Domain\Payments\PaymentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Exercises the real adapter against faked HTTP answers; no request leaves the machine. */
final class MonetbilGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        config([
            'services.monetbil.service_key' => 'test-key',
            'services.monetbil.service_secret' => 'test-secret',
            'services.monetbil.widget_url' => 'https://widget.test/v2.1/',
            'services.monetbil.check_url' => 'https://check.test/checkPayment',
        ]);
    }

    private function payment(): array
    {
        return [
            'reference' => '0b8e2f8e-0000-4000-8000-000000000001',
            'amount' => 2500,
            'currency' => 'XAF',
            'locale' => 'fr',
            'notify_url' => 'https://api.test/api/v1/payments/monetbil/notify',
            'return_url' => 'https://shop.test/fr/orders/1',
            'user' => '7',
        ];
    }

    public function test_start_posts_a_signed_form_and_only_accepts_an_https_payment_page(): void
    {
        Http::fakeSequence('widget.test/*')
            ->push(['success' => true, 'payment_url' => 'https://pay.test/abc'])
            ->push(['payment_url' => 'http://insecure.test/abc']);
        $this->assertSame(
            ['payment_url' => 'https://pay.test/abc'],
            app(MonetbilGateway::class)->start($this->payment()),
        );
        Http::assertSent(function (Request $request) {
            $data = $request->data();
            $sign = $data['sign'];
            unset($data['sign']);
            ksort($data);

            return $request->url() === 'https://widget.test/v2.1/test-key' &&
                $request->isForm() &&
                $data['amount'] === '2500' &&
                $data['payment_ref'] === $this->payment()['reference'] &&
                $sign === md5('test-secret'.implode('', $data));
        });

        $this->expectException(PaymentProviderException::class);
        app(MonetbilGateway::class)->start($this->payment());
    }

    public function test_check_maps_documented_statuses_and_treats_outages_as_unknown(): void
    {
        $answers = [
            ['transaction' => ['status' => 1, 'amount' => '2500', 'currency' => 'XAF', 'payment_ref' => 'ref']],
            ['transaction' => ['status' => 0]],
            ['transaction' => ['status' => -1]],
            [
                'transaction' => [
                    'status' => 7,
                    'testmode' => 1,
                    'amount' => 2500,
                    'currency' => 'XAF',
                    'payment_ref' => 'ref',
                ],
            ],
            ['unexpected' => true],
        ];
        Http::fakeSequence('check.test/*')
            ->push($answers[0])
            ->push($answers[1])
            ->push($answers[2])
            ->push($answers[3])
            ->push($answers[4])
            ->push('Server error', 500)
            ->pushFailedConnection();
        $gateway = app(MonetbilGateway::class);
        $confirmed = $gateway->check('TX');
        $this->assertSame(
            ['confirmed', 2500, 'XAF', 'ref', false],
            [
                $confirmed['state'],
                $confirmed['amount'],
                $confirmed['currency'],
                $confirmed['reference'],
                $confirmed['testmode'],
            ],
        );
        $this->assertSame('failed', $gateway->check('TX')['state']);
        $this->assertSame('cancelled', $gateway->check('TX')['state']);
        $this->assertTrue($gateway->check('TX')['testmode']);
        // An unreadable answer, a server error or a network failure never counts as a failed payment.
        $this->assertSame('unknown', $gateway->check('TX')['state']);
        $this->assertSame('unknown', $gateway->check('TX')['state']);
        $this->assertSame('unknown', $gateway->check('TX')['state']);
        Http::assertSent(fn (Request $r) => $r->url() === 'https://check.test/checkPayment' && $r['paymentId'] === 'TX');
    }

    public function test_notification_signature_follows_the_sdk_and_fails_without_credentials(): void
    {
        $fields = ['transaction_id' => 'TX', 'payment_ref' => 'ref', 'status' => '1', 'amount' => '2500'];
        $sorted = $fields;
        ksort($sorted);
        $signed = [...$fields, 'sign' => md5('test-secret'.implode('', $sorted))];
        $gateway = app(MonetbilGateway::class);
        $this->assertTrue($gateway->validNotification($signed));
        $this->assertFalse($gateway->validNotification([...$signed, 'amount' => '1']));
        $this->assertFalse($gateway->validNotification($fields));

        config(['services.monetbil.service_secret' => null]);
        $this->assertFalse($gateway->configured());
        $this->assertFalse($gateway->validNotification($signed));
    }

    public function test_an_unreachable_widget_raises_a_provider_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('offline'));
        $this->expectException(PaymentProviderException::class);
        app(MonetbilGateway::class)->start($this->payment());
    }
}
