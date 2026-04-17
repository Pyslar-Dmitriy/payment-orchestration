<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Webhook Provider Registry
    |--------------------------------------------------------------------------
    |
    | Each key is a provider slug that maps to the URL segment in
    | POST /webhooks/{provider}. Providers absent from this list are rejected
    | with 404.
    |
    | signing_secret   – HMAC-SHA256 secret. When non-empty, the request
    |                    must carry a valid signature in signature_header.
    | signature_header – Request header carrying the hex HMAC-SHA256 digest.
    | event_id_header  – Request header carrying the provider's event ID
    |                    (used as the dedup key together with provider name).
    |
    */

    'providers' => [

        'mock' => [
            'signing_secret' => env('WEBHOOK_SIGNING_SECRET_MOCK', ''),
            'signature_header' => 'x-webhook-signature',
            'event_id_header' => 'x-event-id',
        ],

    ],

];
