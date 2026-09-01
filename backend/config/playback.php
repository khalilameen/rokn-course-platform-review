<?php

return [
    // Short enough to limit leaked-link value and long enough for a lesson.
    // The mobile player refreshes proactively without losing its seek point.
    'signed_url_ttl_seconds' => (int) env('PLAYBACK_SIGNED_URL_TTL_SECONDS', 3600),
    'manifest_refresh_margin_seconds' => (int) env('PLAYBACK_REFRESH_MARGIN_SECONDS', 900),
    'stale_after_minutes' => (int) env('PLAYBACK_STALE_AFTER_MINUTES', 10),
    'metrics_max_window_hours' => (int) env('PLAYBACK_METRICS_MAX_WINDOW_HOURS', 720),
    'data_saver_max_height' => (int) env('PLAYBACK_DATA_SAVER_MAX_HEIGHT', 480),
    'data_saver_max_bitrate_kbps' => (int) env('PLAYBACK_DATA_SAVER_MAX_BITRATE_KBPS', 1200),
    'cellular_max_height' => (int) env('PLAYBACK_CELLULAR_MAX_HEIGHT', 720),
    'cellular_max_bitrate_kbps' => (int) env('PLAYBACK_CELLULAR_MAX_BITRATE_KBPS', 2500),
];
