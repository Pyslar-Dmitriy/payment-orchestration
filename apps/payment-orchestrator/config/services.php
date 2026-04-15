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

    'internal' => [
        'secret' => env('INTERNAL_SERVICE_SECRET'),
    ],

    'payment_domain' => [
        'base_url' => env('PAYMENT_DOMAIN_BASE_URL', 'http://payment-domain'),
        'internal_secret' => env('INTERNAL_SERVICE_SECRET'),
        'connect_timeout' => env('PAYMENT_DOMAIN_CONNECT_TIMEOUT', 2),
        'timeout' => env('PAYMENT_DOMAIN_TIMEOUT', 5),
    ],

    'provider_gateway' => [
        'base_url' => env('PROVIDER_GATEWAY_BASE_URL', 'http://provider-gateway'),
        'connect_timeout' => env('PROVIDER_GATEWAY_CONNECT_TIMEOUT', 2),
        'timeout' => env('PROVIDER_GATEWAY_TIMEOUT', 30),
    ],

    'ledger_service' => [
        'base_url' => env('LEDGER_SERVICE_BASE_URL', 'http://ledger-service'),
        'connect_timeout' => env('LEDGER_SERVICE_CONNECT_TIMEOUT', 2),
        'timeout' => env('LEDGER_SERVICE_TIMEOUT', 10),
    ],

    'callback_delivery' => [
        'base_url' => env('CALLBACK_DELIVERY_BASE_URL', 'http://merchant-callback-delivery'),
        'connect_timeout' => env('CALLBACK_DELIVERY_CONNECT_TIMEOUT', 2),
        'timeout' => env('CALLBACK_DELIVERY_TIMEOUT', 10),
    ],

];
