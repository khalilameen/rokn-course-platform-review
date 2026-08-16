<?php

return [
    'client_ttl_seconds' => max(60, (int) env('PRODUCT_FEATURE_TTL_SECONDS', 300)),
    'definitions' => [
        'checkout' => [
            'default_enabled' => env('PRODUCT_CHECKOUT_ENABLED', true),
            'safe_default' => false,
            'description' => 'Cash package checkout and payment initiation',
        ],
        'playback' => [
            'default_enabled' => env('PRODUCT_PLAYBACK_ENABLED', true),
            // Existing paid learning remains available if client config is stale.
            'safe_default' => true,
            'description' => 'Issuing protected lesson playback manifests',
        ],
        'project_uploads' => [
            'default_enabled' => env('PRODUCT_PROJECT_UPLOADS_ENABLED', true),
            'safe_default' => false,
            'description' => 'Uploading or submitting learner projects',
        ],
        'ai_chat' => [
            'default_enabled' => env('PRODUCT_AI_CHAT_ENABLED', true),
            'safe_default' => false,
            'description' => 'Course AI assistant requests',
        ],
    ],
];
