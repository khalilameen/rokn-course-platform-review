<?php

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'endpoint' => env('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
    'default_model' => env('OPENROUTER_DEFAULT_MODEL'),
    'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 420),
    'timeout_seconds' => (int) env('OPENROUTER_TIMEOUT_SECONDS', 45),
    'connect_timeout_seconds' => (int) env('OPENROUTER_CONNECT_TIMEOUT_SECONDS', 5),
    'circuit_failure_threshold' => (int) env('OPENROUTER_CIRCUIT_FAILURE_THRESHOLD', 3),
    'circuit_open_seconds' => (int) env('OPENROUTER_CIRCUIT_OPEN_SECONDS', 30),
    'billing_circuit_open_seconds' => (int) env('OPENROUTER_BILLING_CIRCUIT_OPEN_SECONDS', 900),
    'per_minute_limit' => (int) env('OPENROUTER_PER_MINUTE_LIMIT', 8),
    'daily_user_limit' => (int) env('OPENROUTER_DAILY_USER_LIMIT', 100),
    'global_daily_request_limit' => (int) env('OPENROUTER_GLOBAL_DAILY_REQUEST_LIMIT', 5000),
    'global_daily_token_budget' => (int) env('OPENROUTER_GLOBAL_DAILY_TOKEN_BUDGET', 2100000),
    'global_monthly_token_budget' => (int) env('OPENROUTER_GLOBAL_MONTHLY_TOKEN_BUDGET', 50000000),
    'answer_cache_minutes' => (int) env('OPENROUTER_ANSWER_CACHE_MINUTES', 360),
    'chat_history_days' => (int) env('OPENROUTER_CHAT_HISTORY_DAYS', 90),
    'allowed_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPENROUTER_ALLOWED_MODELS', ''))
    ))),
];
