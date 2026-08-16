<?php

return [
    'minimum_verified_seconds' => (int) env('LEARNING_MINIMUM_VERIFIED_SECONDS', 20),
    'required_fraction' => (float) env('LEARNING_REQUIRED_WATCH_FRACTION', 0.80),
    'maximum_playback_rate' => (float) env('LEARNING_MAXIMUM_PLAYBACK_RATE', 2.0),
    'maximum_heartbeat_gap_seconds' => (int) env('LEARNING_MAXIMUM_HEARTBEAT_GAP_SECONDS', 45),
    'maximum_credit_per_heartbeat' => (int) env('LEARNING_MAXIMUM_CREDIT_PER_HEARTBEAT', 30),
];
