<?php

return [
    // Demo data is opt-in everywhere. Production additionally requires the
    // production-specific acknowledgement below, preventing an ordinary
    // `db:seed --force` from publishing placeholder courses or accounts.
    'seed_enabled' => (bool) env('ROKN_SEED_DEMO', false),
    'allow_in_production' => (bool) env('ROKN_ALLOW_PRODUCTION_DEMO_SEED', false),
];
