<?php

return [
    'max_extra_meals' => 100,
    'advance_limit_days' => 60,
    'allowed_extra_types' => ['guest', 'visitor', 'other'],
    'canonical_skip_sources' => ['leave', 'wfh', 'hr', 'self', 'link', 'recurring'], // 'link' is reserved, MVP mein use nahi
    'auto_skip_sources' => ['leave', 'wfh', 'recurring'],
    'manual_skip_sources' => ['hr', 'self'],
    'default_timezone' => 'Asia/Kolkata',
    'attendance_sources' => ['manual', 'integrated', 'none'],
    'anomaly' => [
        'deviation_threshold_percent' => 20,
        'history_min_days' => 3,
        'max_extra_spike' => 10,
        'max_skip_ratio' => 0.30,
    ],
];
