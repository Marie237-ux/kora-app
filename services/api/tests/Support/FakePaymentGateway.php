<?php

namespace Tests\Support;

use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\PaymentProviderException;

/** Test double: answers exactly what the test scripts, records every call and never reaches a network. */
final class FakePaymentGateway implements PaymentGateway
{
    public bool $configured = true;

    public bool $refuse = false;

    /** @var array<string, array<string, mixed>> scripted check results by transaction id */
    public array $checks = [];

    public array $started = [];

    public array $checked = [];

    public function name(): string
    {
        return 'monetbil';
    }

    public function configured(): bool
    {
        return $this->configured;
    }

    public function start(array $payment): array
    {
        if ($this->refuse) {
            throw new PaymentProviderException('provider_refused');
        }
        $this->started[] = $payment;

        return ['payment_url' => 'https://payment.test/pay/'.$payment['reference']];
    }

    public function check(string $transactionId): array
    {
        $this->checked[] = $transactionId;

        return $this->checks[$transactionId] ?? [
            'state' => 'unknown',
            'provider_status' => null,
            'amount' => null,
            'currency' => null,
            'reference' => null,
            'testmode' => null,
        ];
    }

    public function validNotification(array $fields): bool
    {
        return ($fields['sign'] ?? null) === 'valid-test-signature';
    }

    public function paid(
        string $transactionId,
        string $reference,
        int $amount,
        string $currency = 'XAF',
        bool $testmode = false,
    ): void {
        $this->checks[$transactionId] = [
            'state' => 'confirmed',
            'provider_status' => $testmode ? '7' : '1',
            'amount' => $amount,
            'currency' => $currency,
            'reference' => $reference,
            'testmode' => $testmode,
        ];
    }
}
