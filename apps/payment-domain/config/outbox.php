<?php

return [
    'batch_size' => env('OUTBOX_BATCH_SIZE', 50),
    'max_retries' => env('OUTBOX_MAX_RETRIES', 5),

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
        'client_id' => env('KAFKA_CLIENT_ID', 'payment-domain-outbox'),
    ],

    'rabbitmq' => [
        'host' => env('RABBITMQ_HOST', 'rabbitmq'),
        'port' => (int) env('RABBITMQ_PORT', 5672),
        'user' => env('RABBITMQ_USER', 'guest'),
        'password' => env('RABBITMQ_PASSWORD', 'guest'),
        'vhost' => env('RABBITMQ_VHOST', '/'),
    ],
];
