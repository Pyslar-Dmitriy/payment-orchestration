<?php

declare(strict_types=1);

return [
    'host' => env('RABBITMQ_HOST', 'rabbitmq'),
    'port' => (int) env('RABBITMQ_PORT', 5672),
    'user' => env('RABBITMQ_USER', 'guest'),
    'password' => env('RABBITMQ_PASSWORD', 'guest'),
    'vhost' => env('RABBITMQ_VHOST', '/'),

    'queues' => [
        'dispatch' => env('CALLBACK_DISPATCH_QUEUE', 'merchant.callback.dispatch'),
        'retry_5s' => env('CALLBACK_RETRY_5S_QUEUE', 'merchant.callback.retry.5s'),
        'retry_30s' => env('CALLBACK_RETRY_30S_QUEUE', 'merchant.callback.retry.30s'),
        'retry_5m' => env('CALLBACK_RETRY_5M_QUEUE', 'merchant.callback.retry.5m'),
        'dlq' => env('CALLBACK_DLQ_QUEUE', 'merchant.callback.dlq'),
    ],

    'max_attempts' => (int) env('CALLBACK_MAX_ATTEMPTS', 5),
];
