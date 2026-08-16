<?php

return [
    // Never silently fall back to the test gateway. The selected environment
    // and its credentials must be explicit on every deployed instance.
    'mode' => env('KASHIER_MODE'),
    
    'live' => [
        'base_url' => 'https://checkout.kashier.io',
        'api_key' => env('KASHIER_LIVE_API_KEY', ''),
        'mid' => env('KASHIER_LIVE_MID', ''),
    ],
    
    'test' => [
        'base_url' => 'https://checkout.kashier.io',
        'api_key' => env('KASHIER_TEST_API_KEY', ''),
        'mid' => env('KASHIER_TEST_MID', ''),
    ],
];
