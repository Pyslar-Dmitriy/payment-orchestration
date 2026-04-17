<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Provider Routing Configuration
    |--------------------------------------------------------------------------
    |
    | Each entry defines the routing rules for one PSP adapter.
    |
    | Fields:
    |   key            — matches ProviderAdapterInterface::providerKey()
    |   currencies     — ISO 4217 codes this provider accepts (uppercase)
    |   countries      — ISO 3166-1 alpha-2 merchant country codes (uppercase)
    |   merchant_types — optional whitelist of merchant categories;
    |                    empty array means the provider accepts all categories
    |   priority       — integer; lower value = higher preference
    |   available      — when false the provider is excluded from all routing;
    |                    can be overridden at runtime via the admin endpoint
    |                    PATCH /internal/providers/{key}/availability
    |
    */
    'routing' => [
        [
            'key' => 'mock',
            'currencies' => ['USD', 'EUR', 'GBP'],
            'countries' => ['US', 'GB', 'DE', 'FR', 'NL'],
            'merchant_types' => [],
            'priority' => 10,
            'available' => (bool) env('PROVIDER_MOCK_AVAILABLE', true),
        ],
    ],

];
