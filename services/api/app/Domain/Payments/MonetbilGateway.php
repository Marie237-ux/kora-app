<?php

namespace App\Domain\Payments;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Monetbil adapter, written from the official PHP SDK (github.com/Monetbil/monetbil-php, last updated 2021):
 * widget v2.1 for the payment page, checkPayment for server-side verification, MD5 signature on notifications.
 * TLS verification stays enabled, unlike the SDK. The exact response fields of checkPayment must be confirmed
 * with Monetbil on the activated account (see docs/LANCEMENT.md); a missing field prevents confirmation.
 */
final class MonetbilGateway implements PaymentGateway
{
    private const STATES = [
        '1' => 'confirmed',
        '7' => 'confirmed',
        '0' => 'failed',
        '8' => 'failed',
        '-1' => 'cancelled',
        '9' => 'cancelled',
    ];

    public function name(): string
    {
        return 'monetbil';
    }

    public function configured(): bool
    {
        return filled(config('services.monetbil.service_key')) && filled(config('services.monetbil.service_secret'));
    }

    public function start(array $payment): array
    {
        $params = [
            'amount' => (string) $payment['amount'],
            'country' => 'CM',
            'currency' => $payment['currency'],
            'item_ref' => $payment['reference'],
            'locale' => $payment['locale'],
            'notify_url' => $payment['notify_url'],
            'payment_ref' => $payment['reference'],
            'return_url' => $payment['return_url'],
            'user' => $payment['user'],
        ];
        $params['sign'] = $this->sign($params);
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post(
                    rtrim((string) config('services.monetbil.widget_url'), '/').
                        '/'.
                        config('services.monetbil.service_key'),
                    $params,
                );
        } catch (ConnectionException $error) {
            throw new PaymentProviderException('provider_unreachable', 0, $error);
        }
        $url = $response->successful() ? $response->json('payment_url') : null;
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new PaymentProviderException('provider_refused');
        }

        return ['payment_url' => $url];
    }

    public function check(string $transactionId): array
    {
        $unknown = [
            'state' => 'unknown',
            'provider_status' => null,
            'amount' => null,
            'currency' => null,
            'reference' => null,
            'testmode' => null,
        ];
        try {
            $response = Http::asForm()
                ->timeout(15)
                ->post((string) config('services.monetbil.check_url'), ['paymentId' => $transactionId]);
        } catch (ConnectionException) {
            return $unknown;
        }
        $transaction = $response->successful() ? $response->json('transaction') : null;
        if (! is_array($transaction) || ! array_key_exists('status', $transaction)) {
            return $unknown;
        }
        $status = (string) $transaction['status'];
        $amount = $transaction['amount'] ?? null;

        return [
            'state' => self::STATES[$status] ?? 'unknown',
            'provider_status' => $status,
            'amount' => is_numeric($amount) && (string) (int) $amount === (string) $amount ? (int) $amount : null,
            'currency' => isset($transaction['currency']) ? (string) $transaction['currency'] : null,
            'reference' => isset($transaction['payment_ref']) ? (string) $transaction['payment_ref'] : null,
            'testmode' => isset($transaction['testmode'])
                ? (bool) $transaction['testmode']
                : in_array($status, ['7', '8', '9'], true),
        ];
    }

    public function validNotification(array $fields): bool
    {
        $sign = $fields['sign'] ?? null;
        unset($fields['sign']);

        return is_string($sign) && $this->configured() && hash_equals($this->sign($fields), strtolower($sign));
    }

    /** SDK algorithm: md5(service secret followed by the values sorted by key). */
    private function sign(array $params): string
    {
        ksort($params);

        return md5(config('services.monetbil.service_secret').implode('', array_map('strval', $params)));
    }
}
