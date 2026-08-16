<?php

return [
    // Milestones only. Never add video ticks, free-form messages, names,
    // emails, URLs or device identifiers to this contract.
    'allowed' => [
        'app_opened',
        'home_viewed',
        'search_submitted',
        'search_zero_results',
        'course_impression',
        'course_opened',
        'sample_started',
        'sample_completed',
        'lesson_started',
        'lesson_milestone',
        'lesson_completed',
        'paywall_viewed',
        'paywall_dismissed',
        'earn_tasks_opened',
        'purchase_started',
        'purchase_completed',
        'grant_claimed',
        'module_completed',
        'project_opened',
        'project_submitted',
        'project_passed',
        'certificate_issued',
        'notification_opened',
    ],
    'sources' => ['app', 'web', 'dashboard', 'system', 'notification'],
    'screens' => [
        'app', 'home', 'search', 'course_details', 'player', 'project',
        'wallet', 'tasks', 'checkout', 'corner', 'saved', 'profile',
        'certificate', 'notification',
    ],
    'lesson_milestones' => [25, 50, 75, 95, 100],
];
