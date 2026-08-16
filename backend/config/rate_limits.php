<?php

declare(strict_types=1);

return [
    'api_read_identity_per_minute' => (int) env('API_READ_IDENTITY_PER_MINUTE', 240),
    // Deliberately generous for campuses, workplaces and shared mobile NATs;
    // still finite so rotating fake bearer tokens cannot create infinite keys.
    'api_read_ip_per_minute' => (int) env('API_READ_IP_PER_MINUTE', 1200),
    'api_write_identity_per_minute' => (int) env('API_WRITE_IDENTITY_PER_MINUTE', 90),
    'api_write_ip_per_minute' => (int) env('API_WRITE_IP_PER_MINUTE', 450),
    // Browser redirects can legitimately repeat, while a per-order bucket
    // prevents one payment from being replayed without punishing shared NATs.
    'kashier_callback_order_per_minute' => (int) env('KASHIER_CALLBACK_ORDER_PER_MINUTE', 30),
    'kashier_callback_ip_per_minute' => (int) env('KASHIER_CALLBACK_IP_PER_MINUTE', 120),
    // Kashier may retry webhooks in bursts. These defaults remain generous for
    // provider traffic and add both order and IP ceilings for forged floods.
    'kashier_webhook_order_per_minute' => (int) env('KASHIER_WEBHOOK_ORDER_PER_MINUTE', 30),
    'kashier_webhook_ip_per_minute' => (int) env('KASHIER_WEBHOOK_IP_PER_MINUTE', 300),
    'kashier_webhook_ip_per_hour' => (int) env('KASHIER_WEBHOOK_IP_PER_HOUR', 2000),
];
