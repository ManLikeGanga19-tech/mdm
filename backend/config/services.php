<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Firebase Cloud Messaging (wake-up pushes, App\Mdm\Services\PushService). The service-account JSON is a secret:
    // it lives only on the server, outside git.
    'fcm' => [
        'project_id' => env('FCM_PROJECT_ID'),
        'credentials' => env('FCM_CREDENTIALS_PATH'),
    ],

    // Real-time channel to the phones: the Mercure hub built into FrankenPHP (`frankenphp php-server --mercure`), which
    // reads the same MERCURE_* variables. Phones hold a server-sent-events connection to `public_url`; the server
    // publishes wake-ups to `internal_url` (inside the Docker network). Both JWT keys are secrets.
    'mercure' => [
        'public_url' => env('MERCURE_RESOURCE_IDENTIFIER'), // the hub's public URL, also the tokens' audience
        'internal_url' => env('MERCURE_INTERNAL_URL', 'http://app/.well-known/mercure'),
        'issuer' => env('MERCURE_TRUSTED_ISSUERS', 'noahport-mdm'),
        'publisher_key' => env('MERCURE_PUBLISHER_JWT_KEY'),
        'subscriber_key' => env('MERCURE_SUBSCRIBER_JWT_KEY'),
    ],

];
