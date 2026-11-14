<?php

namespace App\Http\Controllers;

use App\Domain\Payments\PaymentGateway;
use App\Domain\Payments\Payments;
use App\Domain\Procurement\Records;
use App\Domain\Shared\CommandBus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Server-to-server notification. The signature is checked, the receipt is recorded without personal data,
 * then the transaction is verified with the provider's API before anything changes.
 */
final class PaymentNotificationController extends Controller
{
    public function notify(Request $http, PaymentGateway $gateway, Payments $payments)
    {
        $fields = array_map('strval', array_filter($http->post(), 'is_scalar'));
        $valid = $gateway->validNotification($fields);
        $transactionId = $fields['transaction_id'] ?? null;
        $reference = $fields['payment_ref'] ?? '';
        $attempt = preg_match('/^[0-9a-f-]{36}$/', $reference)
            ? DB::table('payment_attempts')->where('id', $reference)->where('provider', $gateway->name())->first()
            : null;
        $id = Records::id();
        DB::table('payment_notifications')->insert([
            'id' => $id,
            'provider' => $gateway->name(),
            'transaction_id' => $transactionId ? mb_substr($transactionId, 0, 120) : null,
            'attempt_id' => $attempt?->id,
            'signature_valid' => $valid,
            'fields_hash' => app(CommandBus::class)->fingerprint($fields),
            'received_at' => now(),
        ]);
        if (! $valid) {
            return response('Error: Invalid signature', 403)->header('Content-Type', 'text/plain');
        }
        $outcome = 'unmatched';
        if ($attempt && $transactionId && preg_match('/^[A-Za-z0-9_.-]{1,120}$/', $transactionId)) {
            $payments->claim($attempt, $transactionId);
            $outcome = $payments->verify($attempt->id, $transactionId);
        }
        DB::table('payment_notifications')
            ->where('id', $id)
            ->update(['outcome' => $outcome, 'processed_at' => now()]);

        return response('received', 200)->header('Content-Type', 'text/plain');
    }
}
