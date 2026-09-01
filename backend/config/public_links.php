<?php

declare(strict_types=1);

return [
    // Public shares and certificate QR codes must outlive API-host changes.
    'base_url' => env('PUBLIC_WEB_URL', 'https://rokn.app'),
];
