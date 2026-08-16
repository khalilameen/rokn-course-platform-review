<?php

return [
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
    ],

    /*
    |--------------------------------------------------------------------------
    | Verification Code Settings
    |--------------------------------------------------------------------------
    */

    'verification' => [
        'code_length' => 6,
        'expiry_minutes' => 10,
    ],
];
