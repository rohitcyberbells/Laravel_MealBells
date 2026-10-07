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

    /*
     * Largest number of rows one CSV import may confirm. The upload itself is
     * capped by file size, but the confirm step takes JSON rows.
     */
    'max_import_rows' => env('MEALBELLS_MAX_IMPORT_ROWS', 2000),

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
     * Suffix for the stand-in address given to an employee who has none.
     *
     * users.email is NOT NULL and unique, so an account needs one even when the
     * person cannot receive mail. Anything ending in this is never written to:
     * that employee signs in with their company code and employee code, and a
     * password reset for them goes through their HR team instead.
     *
     * Shared by CreateEmployeeLogins and the reset flow so the two cannot
     * disagree about what counts as unreachable.
     */
    'placeholder_email_suffix' => '.local',

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
