<?php

return [
    'batch_size' => env('OUTBOX_BATCH_SIZE', 50),
    'max_retries' => env('OUTBOX_MAX_RETRIES', 5),

    'kafka' => [
        'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
        'client_id' => env('KAFKA_CLIENT_ID', 'webhook-normalizer-outbox'),
    ],
];
