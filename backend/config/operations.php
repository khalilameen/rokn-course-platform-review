<?php

return [
    'queue_heartbeat_key' => env('QUEUE_HEARTBEAT_KEY', 'operations:queue-heartbeat:v1'),
    'queue_heartbeat_required_queues' => array_values(array_unique(array_filter(array_map(
        static fn (string $queue): string => trim($queue),
        explode(',', (string) env(
            'QUEUE_HEARTBEAT_REQUIRED_QUEUES',
            'default,notifications,ai-feedback,webhooks'
        ))
    )))),
    'queue_heartbeat_ttl_seconds' => (int) env('QUEUE_HEARTBEAT_TTL_SECONDS', 600),
    'queue_heartbeat_max_age_seconds' => (int) env('QUEUE_HEARTBEAT_MAX_AGE_SECONDS', 180),

    // A player is considered live only while it keeps reporting progress.
    // The wider stale window avoids terminating a session during a short
    // mobile-network hand-off or while Android briefly backgrounds the app.
    'playback_active_window_seconds' => (int) env('PLAYBACK_ACTIVE_WINDOW_SECONDS', 90),
    'playback_stale_after_minutes' => (int) env('PLAYBACK_STALE_AFTER_MINUTES', 10),
    'playback_metrics_days' => (int) env('PLAYBACK_METRICS_DAYS', 7),

    'media_reconcile_status_key' => env('MEDIA_RECONCILE_STATUS_KEY', 'operations:media-reconcile:status:v1'),
    'media_reconcile_lock_key' => env('MEDIA_RECONCILE_LOCK_KEY', 'operations:media-reconcile:lock:v1'),
    'media_reconcile_lock_seconds' => (int) env('MEDIA_RECONCILE_LOCK_SECONDS', 10800),
    'media_reconcile_batch_size' => (int) env('MEDIA_RECONCILE_BATCH_SIZE', 25),
    'media_reconcile_fetch_manifest' => filter_var(
        env('MEDIA_RECONCILE_FETCH_MANIFEST', true),
        FILTER_VALIDATE_BOOL
    ),

    'kashier_reconcile_lock_key' => env(
        'KASHIER_RECONCILE_LOCK_KEY',
        'operations:kashier-reconcile:lock:v1'
    ),
    'kashier_reconcile_lock_seconds' => (int) env('KASHIER_RECONCILE_LOCK_SECONDS', 1800),

    // These values are operator evidence only. The dashboard never runs a
    // backup or restore; it reports whether a verified process exists.
    'backup_provider' => env('BACKUP_PROVIDER'),
    'backup_last_verified_at' => env('BACKUP_LAST_VERIFIED_AT'),
    'restore_drill_verified_at' => env('RESTORE_DRILL_VERIFIED_AT'),
    'backup_max_age_hours' => (int) env('BACKUP_MAX_AGE_HOURS', 26),
    'restore_drill_max_age_days' => (int) env('RESTORE_DRILL_MAX_AGE_DAYS', 90),
];
