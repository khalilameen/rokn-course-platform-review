<?php

return [
    'queue' => env('WEBHOOK_QUEUE', 'webhooks'),
    'claim_stale_seconds' => (int) env('WEBHOOK_CLAIM_STALE_SECONDS', 180),
    'max_timeout_seconds' => (int) env('WEBHOOK_MAX_TIMEOUT_SECONDS', 15),
    'connect_timeout_seconds' => (int) env('WEBHOOK_CONNECT_TIMEOUT_SECONDS', 3),
];
