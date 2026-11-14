<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse;

final class VerificationNotificationResponse implements EmailVerificationNotificationSentResponse
{
    public function toResponse($request)
    {
        if (! config('kora.verification_mail_enabled')) {
            return response()->json(
                [
                    'error' => [
                        'code' => 'EMAIL_DELIVERY_UNAVAILABLE',
                        'message' => __('api.EMAIL_DELIVERY_UNAVAILABLE'),
                        'fields' => (object) [],
                        'request_id' => $request->attributes->get('request_id'),
                    ],
                ],
                503,
            );
        }

        return response()->json(['data' => ['verification_requested' => true]], 202);
    }
}
