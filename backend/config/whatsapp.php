<?php

return [
    // The linking reward is optional until a real inbound provider is connected.
    // Keeping it disabled removes the task instead of exposing a dead action.
    'enabled' => filter_var(env('WHATSAPP_LINKING_ENABLED', false), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Whatspie API Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Whatspie WhatsApp API integration.
    |
    */

    'whatspie' => [
        'api_url' => env('WHATSPIE_API_URL', 'https://api.whatspie.com/messages'),
        'api_key' => env('WHATSPIE_API_KEY', ''),
        'device' => env('WHATSPIE_DEVICE', ''),
        'connect_timeout_seconds' => env('WHATSPIE_CONNECT_TIMEOUT_SECONDS', 5),
        'timeout_seconds' => env('WHATSPIE_TIMEOUT_SECONDS', 15),
    ],

    'linking' => [
        // International digits only, for example 201001234567.
        'bot_phone' => env('WHATSAPP_BOT_PHONE', ''),
        'webhook_secret' => env('WHATSAPP_WEBHOOK_SECRET', ''),
        'token_minutes' => env('WHATSAPP_LINK_TOKEN_MINUTES', 30),
    ],

    'verification' => [
        'code_length' => env('WHATSAPP_VERIFICATION_CODE_LENGTH', 6),
    ],
];
