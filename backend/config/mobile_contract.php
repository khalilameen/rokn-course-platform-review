<?php

return [
    // Increment only for a deliberately breaking mobile API release. Additive
    // fields and endpoints remain inside the same contract.
    'current_version' => 1,
    'minimum_supported_version' => 1,
    // Capabilities the server needs from every supported client contract.
    // Keep this list additive and publish an actionable forced-update release
    // before adding a capability here.
    'required_capabilities' => array_values(array_filter(array_map(
        static fn (string $value): string => trim($value),
        explode(',', (string) env('MOBILE_API_REQUIRED_CAPABILITIES', '')),
    ))),
    'capabilities' => [
        'account_scoped_storage_v1',
        'secure_session_v2',
        'social_oauth_pkce_v1',
        'product_feature_flags_v1',
        'playback_manifest_v2',
        'app_update_policy_v2',
    ],
    // Launch readiness is stricter than traffic readiness. Direct is the
    // current test/launch channel; set a comma-separated list such as
    // play,direct,appstore when the store records are ready.
    'launch_channels' => array_values(array_filter(array_map(
        static fn (string $value): string => trim(strtolower($value)),
        explode(',', (string) env('MOBILE_RELEASE_REQUIRED_CHANNELS', 'direct')),
    ))),
];
