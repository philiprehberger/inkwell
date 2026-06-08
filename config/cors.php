<?php

return [
    /*
     * Webhook Relay API + admin. The docs-site try-it console at
     * webhook-relay.dcsuniverse.com fires real calls cross-origin into
     * api.webhook-relay.dcsuniverse.com, so the API needs to allow that
     * origin specifically. The admin panel is same-origin so doesn't
     * need its own entry.
     */

    'paths' => ['v1/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'https://webhook-relay.dcsuniverse.com',
        'http://localhost:3000', // local Next dev server
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [
        'X-RateLimit-Limit',
        'X-RateLimit-Remaining',
        'X-RateLimit-Reset',
    ],

    'max_age' => 600,

    'supports_credentials' => false,
];
