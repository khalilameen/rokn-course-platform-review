<?php

return [
    'api_key' => env('OPENROUTER_API_KEY'),
    'endpoint' => env('OPENROUTER_ENDPOINT', 'https://openrouter.ai/api/v1/chat/completions'),
    'default_model' => env('OPENROUTER_DEFAULT_MODEL'),
    // Course chat is deliberately short and immediate. Reasoning models can
    // otherwise spend the entire small completion budget thinking and return
    // a successful response with no learner-visible answer.
    'reasoning_effort' => env('OPENROUTER_REASONING_EFFORT', 'none'),
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
    // OpenRouter PDF parsing is explicit so adding a PDF never silently
    // switches to a paid parser. cloudflare-ai is currently the free parser.
    'pdf_parser_engine' => env('OPENROUTER_PDF_PARSER_ENGINE', 'cloudflare-ai'),
    'attachment_provider_max_bytes' => (int) env('OPENROUTER_ATTACHMENT_PROVIDER_MAX_BYTES', 8388608),
    // Course-chat context never crosses a new learner session. This keeps the
    // user-visible privacy promise while preserving continuity during a real
    // study session and across lesson swipes.
    'chat_context_session_minutes' => (int) env('OPENROUTER_CHAT_CONTEXT_SESSION_MINUTES', 120),
    'queue_stale_seconds' => (int) env('OPENROUTER_QUEUE_STALE_SECONDS', 900),
    // A provider response is cached briefly under the same API key. This is a
    // recovery optimization for an identical request, not the correctness
    // boundary: account-level ZDR or edge eviction may disable the cache, so
    // an uncertain call is still quarantined rather than blindly repeated.
    'response_recovery_cache_ttl_seconds' => (int) env(
        'OPENROUTER_RESPONSE_RECOVERY_CACHE_TTL_SECONDS',
        900
    ),
    'allowed_models' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('OPENROUTER_ALLOWED_MODELS', ''))
    ))),
];
