<?php

declare(strict_types=1);

use App\Support\SentryEventScrubber;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'release' => env('SENTRY_RELEASE'),
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),
    'sample_rate' => (float) env('SENTRY_SAMPLE_RATE', 1.0),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),
    'send_default_pii' => false,
    'max_request_body_size' => 'never',
    'max_breadcrumbs' => 0,
    'before_send' => [SentryEventScrubber::class, 'scrub'],
    'before_send_transaction' => [SentryEventScrubber::class, 'scrub'],
];
