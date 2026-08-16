<?php

return [
    // Comma-separated IPs/CIDRs for the actual reverse proxies that can reach
    // the origin. Never use *; enforce the same allow-list at the origin firewall.
    'proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),
];
