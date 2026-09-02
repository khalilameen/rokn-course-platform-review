<?php

return [
    'economics_configured' => trim((string) env('ROKN_NET_USD_PER_PAID_COIN', '')) !== ''
        && trim((string) env('ROKN_AI_COST_SAFETY_MULTIPLIER', '')) !== '',

    /*
     * Net USD retained by Rokn for one purchased coin after payment fees and
     * taxes. Finance should update this whenever coin packages or FX change.
     */
    'net_usd_per_paid_coin' => (float) env('ROKN_NET_USD_PER_PAID_COIN', 0.001),

    /* Covers provider price movement, retries and normal usage variance. */
    'ai_cost_safety_multiplier' => (float) env('ROKN_AI_COST_SAFETY_MULTIPLIER', 2.0),

    /*
     * Releases a reservation left behind by a killed HTTP process or queue
     * worker. Keep this above both provider and queue execution timeouts.
     */
    'ai_reservation_ttl_seconds' => (int) env('ROKN_AI_RESERVATION_TTL_SECONDS', 120),

    /*
     * A provider timeout can be billable even though no answer reached Rokn.
     * Do not consume the learner's message allowance, but stop repeated
     * unknown outcomes on this paid enrollment before they become unbounded.
     */
    'ai_unanswered_provider_request_limit' => (int) env(
        'ROKN_AI_UNANSWERED_PROVIDER_REQUEST_LIMIT',
        2
    ),
    'ai_unanswered_provider_window_seconds' => (int) env(
        'ROKN_AI_UNANSWERED_PROVIDER_WINDOW_SECONDS',
        900
    ),
    'ai_provider_exposure_cooldown_seconds' => (int) env(
        'ROKN_AI_PROVIDER_EXPOSURE_COOLDOWN_SECONDS',
        900
    ),
];
