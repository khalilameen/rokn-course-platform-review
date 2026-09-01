<?php

return [
    // Comma-separated IPs/CIDRs for fixed reverse proxies. Managed platforms
    // whose private edge addresses rotate may deliberately use `*`, but only
    // with the separate acknowledgement below and an origin that cannot be
    // reached directly from the internet.
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),
    'allow_dynamic_edge' => (bool) env('TRUSTED_PROXIES_ALLOW_DYNAMIC_EDGE', false),
];
