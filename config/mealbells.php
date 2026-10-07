<?php

return [
    /*
     * Largest number of extra meals one adjustment may add.
     */
    'max_extra_meals' => env('MEALBELLS_MAX_EXTRA_MEALS', 100),

    /*
     * How far ahead a meal may be recorded. A leave beyond this is reported as
     * outside the window rather than applied, so raising it lets the HRMS push
     * further into the future.
     */
    'advance_limit_days' => env('MEALBELLS_ADVANCE_LIMIT_DAYS', 60),

    'allowed_extra_types' => ['guest', 'visitor', 'other'],

    // 'link' is reserved, MVP mein use nahi
    'canonical_skip_sources' => ['leave', 'wfh', 'hr', 'self', 'link', 'recurring'],

    /*
     * Which sources the engine treats as automated. An automated source cannot
     * resurrect a skip a person cancelled - changing these changes who can
     * override whom, so they are deliberately not env-tunable.
     */
    'auto_skip_sources' => ['leave', 'wfh', 'recurring'],
    'manual_skip_sources' => ['hr', 'self'],

    /*
     * Used for any company whose settings row has no timezone of its own.
     */
    'default_timezone' => env('MEALBELLS_DEFAULT_TIMEZONE', 'Asia/Kolkata'),

    'attendance_sources' => ['manual', 'integrated', 'none'],

    /*
     * When a day's count is flagged as unusual and escalated. Worth tuning per
     * deployment: a company of 20 and one of 2,000 do not deviate alike.
     */
    'anomaly' => [
        'deviation_threshold_percent' => env('MEALBELLS_ANOMALY_DEVIATION_PERCENT', 20),
        'history_min_days' => env('MEALBELLS_ANOMALY_HISTORY_MIN_DAYS', 3),
        'max_extra_spike' => env('MEALBELLS_ANOMALY_MAX_EXTRA_SPIKE', 10),
        'max_skip_ratio' => env('MEALBELLS_ANOMALY_MAX_SKIP_RATIO', 0.30),
    ],
];
