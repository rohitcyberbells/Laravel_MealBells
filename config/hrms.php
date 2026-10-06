<?php

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
     * Largest webhook body accepted, checked before the signature is verified.
     * A leave event is a couple of kilobytes; 256KB leaves generous headroom for
     * a verbose vendor while keeping the pre-auth work bounded.
     */
    'max_body_bytes' => env('HRMS_MAX_BODY_BYTES', 262144),

    /*
     * Requests per minute per company on the webhook endpoint.
     */
    'rate_limit_per_minute' => env('HRMS_RATE_LIMIT', 300),

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
