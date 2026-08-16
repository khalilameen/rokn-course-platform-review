<?php

return [
    'table' => 'api_tokens',

    'token' => [
        /*
         * Amount of days token should live
         * Value is in days.
        */
        'life_length' => 60,

        /*
         * Amount of days left of life when we should extend it
         * Value is in days.
        */
        'extend_life_at' => 10,
    ],

    /**
     * Set to true or false to enable/disable token hashing.
     * When set to null, it will default to the auth.guards.api.hash config var.
     */
    'hash' => true,

    // Temporary zero-downtime bridge. Existing plaintext credentials are
    // migrated to SHA-256 on first successful use. Turn this off after 60 days.
    'allow_legacy_plaintext' => (bool) env('API_TOKEN_ALLOW_LEGACY_PLAINTEXT', false),

    // Bearer headers are the only safe production credential transport.
    'allow_legacy_transports' => (bool) env('API_TOKEN_ALLOW_LEGACY_TRANSPORTS', false),
];
