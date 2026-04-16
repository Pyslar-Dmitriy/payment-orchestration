<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Active Scenario
    |--------------------------------------------------------------------------
    |
    | Controls which behaviour MockProviderAdapter exhibits. Switch in tests
    | via config()->set('mock_provider.scenario', MockScenario::Timeout->value).
    |
    | Allowed values: success | timeout | hard_failure | async_webhook |
    |                 delayed_webhook | duplicate_webhook | out_of_order
    |
    */
    'scenario' => env('MOCK_PROVIDER_SCENARIO', 'success'),

    /*
    |--------------------------------------------------------------------------
    | Webhook Delivery URL
    |--------------------------------------------------------------------------
    |
    | URL that DeliverMockWebhookJob POSTs mock webhook payloads to.
    | Typically the webhook-ingest service endpoint (TASK-080).
    | If null/empty the job is a no-op — safe for unit tests.
    |
    */
    'webhook_url' => env('MOCK_PROVIDER_WEBHOOK_URL'),

    /*
    |--------------------------------------------------------------------------
    | Delayed Webhook Delay
    |--------------------------------------------------------------------------
    |
    | Seconds to wait before dispatching a webhook in the delayed_webhook
    | scenario. Only effective when using an actual queue driver (not sync).
    |
    */
    'webhook_delay_seconds' => (int) env('MOCK_PROVIDER_WEBHOOK_DELAY_SECONDS', 5),

];
