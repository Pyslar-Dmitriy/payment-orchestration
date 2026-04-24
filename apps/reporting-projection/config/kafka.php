<?php

return [
    'brokers' => env('KAFKA_BROKERS', 'kafka:9092'),
    'consumer_group_id' => env('KAFKA_CONSUMER_GROUP_ID', 'reporting-projection'),
    'client_id' => env('KAFKA_CLIENT_ID', 'reporting-projection-consumer'),
    'auto_offset_reset' => env('KAFKA_AUTO_OFFSET_RESET', 'earliest'),

    'topics' => [
        'payments_lifecycle' => env('KAFKA_TOPIC_PAYMENTS_LIFECYCLE', 'payments.lifecycle.v1'),
        'refunds_lifecycle' => env('KAFKA_TOPIC_REFUNDS_LIFECYCLE', 'refunds.lifecycle.v1'),
    ],
];
