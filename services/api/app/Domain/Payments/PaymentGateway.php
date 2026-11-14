<?php

namespace App\Domain\Payments;

/**
 * A payment provider seen from KORA. Implementations make network calls and must never be invoked while a
 * database transaction is open. They report facts; KORA decides what a fact means for the order.
 */
interface PaymentGateway
{
    public function name(): string;

    /** False when the credentials are not configured: no attempt is then sent anywhere. */
    public function configured(): bool;

    /**
     * Asks the provider for a payment page.
     *
     * @param  array{reference: string, amount: int, currency: string, locale: string, notify_url: string, return_url: string, user: string}  $payment
     * @return array{payment_url: string}
     *
     * @throws PaymentProviderException when the provider cannot be reached or refuses the request
     */
    public function start(array $payment): array;

    /**
     * Server-side verification of one provider transaction.
     *
     * state: confirmed | failed | cancelled | unknown. "unknown" covers outages and unreadable answers: it is
     * never treated as a failure. Missing fields are returned as null so that KORA can refuse to confirm.
     *
     * @return array{state: string, provider_status: ?string, amount: ?int, currency: ?string, reference: ?string, testmode: ?bool}
     */
    public function check(string $transactionId): array;

    /** Authenticity of a notification body, with the mechanism actually offered by the provider. */
    public function validNotification(array $fields): bool;
}
