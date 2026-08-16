<?php

declare(strict_types=1);

return [
    // Exact public hosts only, comma separated. Wildcards and URLs are not
    // accepted; the middleware escapes every value before building a pattern.
    'hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('APP_TRUSTED_HOSTS', ''))
    ))),
];
