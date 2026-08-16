<?php

declare(strict_types=1);

$csv = static fn (?string $value): array => array_values(array_filter(array_map(
    static fn (string $item): string => trim($item),
    explode(',', (string) $value)
)));

return [
    'android_package' => env('APP_LINK_ANDROID_PACKAGE', 'com.rokn'),
    'android_sha256_fingerprints' => $csv(env('APP_LINK_ANDROID_SHA256_FINGERPRINTS')),
    'apple_app_ids' => $csv(env('APP_LINK_APPLE_APP_IDS')),
];
