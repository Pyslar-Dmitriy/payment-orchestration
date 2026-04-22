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
    ],

    'max_attempts' => (int) env('CALLBACK_MAX_ATTEMPTS', 5),
];
