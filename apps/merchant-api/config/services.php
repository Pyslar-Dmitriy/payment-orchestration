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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'payment_domain' => [
        'base_url' => env('PAYMENT_DOMAIN_URL', 'http://payment-domain/'),
        'connect_timeout' => env('PAYMENT_DOMAIN_CONNECT_TIMEOUT', 2),
        'timeout' => env('PAYMENT_DOMAIN_TIMEOUT', 5),
        'circuit_breaker' => [
            'threshold' => env('PAYMENT_DOMAIN_CB_THRESHOLD', 5),
            'cooldown_seconds' => env('PAYMENT_DOMAIN_CB_COOLDOWN', 60),
        ],
    ],

    'rate_limit' => [
        'per_minute' => env('RATE_LIMIT_PER_MINUTE', 60),
    ],

];
