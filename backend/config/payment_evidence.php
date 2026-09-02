<?php

return [
    // Financial evidence must never use the web-readable public disk.
    // Production selects shared private object storage; local is a private
    // development fallback outside public/storage.
    'disk' => env('PAYMENT_EVIDENCE_DISK', 'local'),
];
