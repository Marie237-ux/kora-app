<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // Secrets stay server-side; without them no payment is sent anywhere.
    'monetbil' => [
        'service_key' => env('MONETBIL_SERVICE_KEY'),
        'service_secret' => env('MONETBIL_SERVICE_SECRET'),
        'widget_url' => env('MONETBIL_WIDGET_URL', 'https://www.monetbil.com/widget/v2.1/'),
        'check_url' => env('MONETBIL_CHECK_URL', 'https://api.monetbil.com/payment/v1/checkPayment'),
        // Monetbil test transactions (status 7/8/9) are only accepted when this is explicitly enabled.
        'accept_testmode' => (bool) env('MONETBIL_ACCEPT_TESTMODE', false),
    ],

    // AI providers: nothing is sent while a key is missing; tests replace them with fakes.
    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-5-5'),
        'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1/messages'),
    ],
    'voyage' => [
        'key' => env('VOYAGE_API_KEY'),
        'model' => env('VOYAGE_MODEL', 'voyage-multimodal-3'),
        'url' => env('VOYAGE_URL', 'https://api.voyageai.com/v1/multimodalembeddings'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    // "Continue with Google" (Google Cloud console → APIs & Services → Credentials → OAuth client ID, type "Web").
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID', ''),
        'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
    ],

];
