<?php

return [
    // Development fixtures are opt-in and their seeders independently refuse
    // every environment except local/testing. There is deliberately no
    // production override.
    'seed_enabled' => (bool) env('ROKN_SEED_DEMO', false),
];
