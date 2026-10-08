<?php

return [
    /*
     * The shared secret /health/ping requires.
     *
     * With no token set the endpoint does not exist - it answers 404, the same
     * as any unknown path. That is deliberate: an unauthenticated endpoint that
     * reports which internal component is down is reconnaissance, and a default
     * token would be worse than none. Generate one with
     * `php artisan mealbells:health-token`.
     */
    'ping_token' => env('HEALTH_PING_TOKEN'),

    /*
     * Minutes after which the scheduler is considered stale. The cron entry
     * runs every minute and `mealbells:process-cutoff` writes the heartbeat, so
     * three minutes is a missed run rather than a few seconds of drift.
     *
     * This is the single threshold the health page and the ping endpoint both
     * read; they used to disagree.
     */
    'scheduler_stale_after_minutes' => env('HEALTH_SCHEDULER_STALE_AFTER_MINUTES', 3),

    'queue' => [
        /*
         * Failed jobs above this are reported as a failure rather than a
         * number. Some failures are normal - one unreachable HRMS host
         * produces a few - so the threshold is not 1.
         */
        'failed_jobs_threshold' => env('HEALTH_FAILED_JOBS_THRESHOLD', 25),

        /*
         * Minutes a job may sit unreserved in `jobs` before the worker is
         * presumed dead.
         *
         * This is the only evidence of a live worker that costs nothing: with
         * QUEUE_CONNECTION=database there is no worker heartbeat, and a stopped
         * worker is the failure that silently loses leave, because every
         * notification in MealBells is queued.
         *
         * It is an inference, not a fact: an empty queue proves nothing either
         * way, so this check passes when there is no work waiting.
         */
        'pending_job_stale_after_minutes' => env('HEALTH_PENDING_JOB_STALE_AFTER_MINUTES', 10),
    ],

    /*
     * Whether a stale HRMS pull fails the endpoint, as opposed to merely being
     * reported in the body.
     *
     * On by default. A pull that stopped looks exactly like a quiet day with no
     * leave, so it is worth waking someone for - but a site that polls no HRMS
     * at all is unaffected either way, because the check only considers
     * companies with a pull configured.
     */
    'pull_staleness_fails' => env('HEALTH_PULL_STALENESS_FAILS', true),

    'alerts' => [
        /*
         * Whether a failing check emails anyone. Off would leave the health
         * page as the only signal, which means someone has to remember to look
         * at it - the state this replaced.
         */
        'enabled' => env('HEALTH_ALERTS_ENABLED', true),

        /*
         * Comma-separated addresses. Empty means every super admin, which is
         * the sensible default; set it when alerts should go to an on-call
         * address or a ticketing inbox instead of to people's own accounts.
         */
        'recipients' => env('HEALTH_ALERT_RECIPIENTS', ''),

        /*
         * Minutes before the same unresolved issue is mailed again.
         *
         * The command runs every five minutes, so without this a dead worker
         * would send twelve mails an hour and be filtered within a day - at
         * which point the alerting is worse than none. An hour is long enough
         * to stay credible and short enough to catch someone coming back to
         * their desk.
         */
        'repeat_after_minutes' => env('HEALTH_ALERT_REPEAT_AFTER_MINUTES', 60),
    ],
];
