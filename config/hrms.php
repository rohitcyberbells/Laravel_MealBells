<?php

use App\Services\Hrms\Adapters\CyberPulseAdapter;
use App\Services\Hrms\Adapters\GenericHrmsAdapter;

return [
    /*
     * Per-company HRMS connections, keyed by companies.id.
     *
     * A company with no entry here, or with no webhook secret, cannot deliver
     * webhooks at all: VerifyHrmsSignature rejects the request.
     *
     * Onboarding a new vendor means describing its JSON below, not adding code:
     * 'payload_map' points at its field paths, 'event_type_map' and 'type_map'
     * translate its vocabulary. Anything omitted falls back to the defaults.
     */
    'companies' => [
        // 1 => [
        //     'timezone' => 'Asia/Kolkata', // timezone the vendor sends times in
        //
        //     'webhook' => [
        //         'auth' => 'signature',   // 'signature' (preferred) | 'token'
        //         'secret' => env('HRMS_C1_WEBHOOK_SECRET'),
        //         'signature_header' => 'X-Hrms-Signature',
        //         'timestamp_header' => 'X-Hrms-Timestamp',
        //         'tolerance_seconds' => 300,
        //     ],
        //
        //     'payload_map' => [
        //         'event_id' => 'id',
        //         'event_type' => 'type',
        //         'occurred_at' => 'created_at',
        //         'leave_id' => 'data.leave_id',
        //         'employee_ref' => 'data.employee.code',
        //         'employee_email' => 'data.employee.email',
        //         'from_date' => 'data.start',
        //         'to_date' => 'data.end',
        //         'leave_type' => 'data.category',
        //         'reason' => 'data.remarks',
        //     ],
        //
        //     'event_type_map' => [
        //         'LeaveApproved' => ['action' => 'approved', 'source' => 'leave'],
        //         'LeaveRevoked' => ['action' => 'cancelled', 'source' => null],
        //     ],
        //
        //     'type_map' => [
        //         'Casual Leave' => 'leave',
        //         'Work From Home' => 'wfh',
        //     ],
        // ],
    ],

    /*
     * Applied when a company's webhook block omits them.
     */
    'webhook_defaults' => [
        'auth' => 'signature',
        'signature_header' => 'X-Hrms-Signature',
        'timestamp_header' => 'X-Hrms-Timestamp',
        'tolerance_seconds' => 300,
    ],

    /*
     * Canonical envelope. Dot paths, read with Arr::get().
     */
    'payload_defaults' => [
        'event_id' => 'event_id',
        'event_type' => 'event_type',
        'occurred_at' => 'occurred_at',
        'leave_id' => 'leave.id',
        'employee_ref' => 'leave.employee_id',
        // Optional. A secondary key for employee matching, used only when
        // external_id and employee_code both miss.
        'employee_email' => 'leave.employee_email',
        'from_date' => 'leave.from_date',
        'to_date' => 'leave.to_date',
        'leave_type' => 'leave.type',
        'reason' => 'leave.reason',
    ],

    /*
     * Event vocabulary. 'action' is 'approved' or 'cancelled'.
     *
     * A null 'source' means the event name does not say whether this is leave or
     * WFH, so the mapper reads the payload's leave type through 'type_map'
     * instead. Cancellations never need a source: the skips to release are found
     * by skips.external_ref.
     */
    'event_type_defaults' => [
        'leave_approved' => ['action' => 'approved', 'source' => 'leave'],
        'wfh_approved' => ['action' => 'approved', 'source' => 'wfh'],
        'leave_cancelled' => ['action' => 'cancelled', 'source' => null],
        'wfh_cancelled' => ['action' => 'cancelled', 'source' => null],
    ],

    /*
     * Vendor leave-type strings onto MealBells skip sources. Only consulted when
     * the event name alone does not determine the source.
     */
    'type_defaults' => [
        'leave' => 'leave',
        'wfh' => 'wfh',
    ],

    /*
     * Leave types that cover part of a day.
     *
     * A skip is all-or-nothing: one row removes the whole day's meal. A half day
     * is therefore neither a skip nor a non-skip, and guessing either way is
     * wrong - cancelling the meal of someone who is in for lunch, or counting
     * someone who is not. These are recorded as ignored with a reason, so the
     * decision is visible rather than silently resolved.
     *
     * Compared case-insensitively, after trimming. A company can extend the list
     * with its own vocabulary via 'partial_day_types' in its block.
     */
    'partial_day_defaults' => [
        'half-day',
        'short-leave',
    ],

    /*
     * Adapter used when a company does not name its own. Most vendors fit the
     * generic one, because differing field names are handled by 'payload_map'.
     */
    'default_adapter' => GenericHrmsAdapter::class,

    /*
     * Adapters that can be driven by hrms:pull, keyed by the name stored in
     * company_hrms_connections.pull_adapter.
     *
     * A company admin chooses from these on the connect screen, so the value can
     * never be an arbitrary class name from a form post.
     */
    'pull_adapters' => [
        'cyberpulse' => CyberPulseAdapter::class,
    ],

    /*
     * Largest webhook body accepted, checked before the signature is verified.
     * A leave event is a couple of kilobytes; 256KB leaves generous headroom for
     * a verbose vendor while keeping the pre-auth work bounded.
     */
    'max_body_bytes' => env('HRMS_MAX_BODY_BYTES', 262144),

    /*
     * Requests per minute per company on the webhook endpoint.
     */
    'rate_limit_per_minute' => env('HRMS_RATE_LIMIT', 300),

    'retention' => [
        /*
         * Days before a received payload is redacted. The row survives: status,
         * result and timings remain as the audit trail of what the integration
         * did, while the vendor's raw PII does not.
         */
        'payload_days' => env('HRMS_PAYLOAD_RETENTION_DAYS', 30),

        /*
         * Days of pull-run history to keep. These rows hold only counts and a
         * status, so they are deleted outright rather than redacted.
         */
        'pull_run_days' => env('HRMS_PULL_RUN_RETENTION_DAYS', 30),
    ],

    /*
     * CyberPulse HRMS, which offers no webhooks and has to be polled.
     */
    'cyberpulse' => [
        /*
         * The vendor's token lasts 30 days and cannot be refreshed, so it is
         * cached slightly short of that and replaced before it lapses rather
         * than after.
         */
        'token_days' => env('HRMS_CP_TOKEN_DAYS', 29),

        /*
         * Self-imposed. Their login endpoint has no rate limit or lockout of its
         * own, which makes restraint our responsibility: without this a crash
         * loop would hammer it once per run.
         */
        'min_seconds_between_logins' => env('HRMS_CP_LOGIN_COOLDOWN', 60),

        'timeout_seconds' => env('HRMS_CP_TIMEOUT', 15),

        /*
         * Largest share of the leaves we currently hold that one run may cancel.
         *
         * A deleted leave leaves nothing behind to receive, so cancellation is
         * inferred by absence from a fetch - and a partial or mis-scoped fetch is
         * indistinguishable from a mass withdrawal. Above this share the run
         * cancels nothing and is marked suspicious, because adding meals back for
         * people who are actually on leave is worse than being a few hours stale.
         */
        'max_cancel_share' => env('HRMS_CP_MAX_CANCEL_SHARE', 0.3),

        /*
         * Cancellations allowed in one run regardless of the share above.
         *
         * A share alone cannot express "this looks like a mass withdrawal": a
         * company holding two leaves is already at 50% when one is withdrawn, so
         * a pure percentage would refuse every ordinary cancellation at small
         * scale and the integration would silently stop releasing meals. Up to
         * this many is treated as routine; past it, the share applies.
         */
        'cancels_always_allowed' => env('HRMS_CP_CANCELS_ALWAYS_ALLOWED', 3),

        /*
         * Minutes between scheduled pulls. The scheduler also runs one shortly
         * before each company's cutoff, so the final count reflects leave
         * approved during the morning.
         */
        'pull_every_minutes' => env('HRMS_CP_PULL_MINUTES', 15),

        /*
         * Minutes before a company's cutoff to force one extra pull.
         *
         * The regular cadence can leave a gap right before the count locks,
         * which is exactly when leave approved that morning matters most.
         */
        'pull_before_cutoff_minutes' => env('HRMS_CP_PULL_BEFORE_CUTOFF_MINUTES', 20),

        /*
         * Largest share of a company's eligible employees that may look absent
         * before an attendance run refuses to record anything.
         *
         * Forty percent of a company is not away; the HR system is far more
         * likely to be broken, mis-scoped or mid-restart. Above this the run
         * records nothing and is marked suspicious, because a wasted meal costs
         * less than somebody going without lunch.
         *
         * In shadow mode this decides whether the day enters the report at all:
         * a suspicious day must not quietly contribute a large "would have
         * saved" number and flatter the case for acting on attendance.
         */
        'max_absent_share' => env('HRMS_CP_MAX_ABSENT_SHARE', 0.4),

        /*
         * Minutes before a company's cutoff that attendance is read.
         *
         * Later than the leave pull's window on purpose: leave should already
         * be applied, so anyone still unaccounted for is genuinely unaccounted
         * for. It has to be early enough for the run to finish before the
         * cutoff, since a run that straddles it would act on part of the
         * company and be refused for the rest.
         */
        'attendance_before_cutoff_minutes' => env('HRMS_CP_ATTENDANCE_BEFORE_CUTOFF_MINUTES', 5),
    ],

    'reconcile' => [
        /*
         * How long an event may sit unfinished before the backstop picks it up.
         * Doubles as the per-event cooldown, so one event is re-dispatched at
         * most once per this window however often the scheduler runs.
         */
        'stale_after_minutes' => env('HRMS_RECONCILE_STALE_MINUTES', 10),

        /*
         * Reconciliation passes allowed on a FAILED event before it is left for
         * a human. Does not apply to events stuck in 'received', whose job has
         * not run even once.
         */
        'max_attempts' => env('HRMS_RECONCILE_MAX_ATTEMPTS', 3),
    ],
];
