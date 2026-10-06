<?php

return [
    /*
     * Per-company HRMS connections, keyed by companies.id.
     *
     * A company with no entry here, or with no webhook secret, cannot deliver
     * webhooks at all: VerifyHrmsSignature rejects the request.
     *
     * Onboarding a new vendor means describing its JSON in 'payload_map', not
     * adding code. Step 1 reads only the envelope fields; the full leave mapping
     * arrives with the mapper in Step 2.
     */
    'companies' => [
        // 1 => [
        //     'webhook' => [
        //         'auth' => 'signature',   // 'signature' (preferred) | 'token'
        //         'secret' => env('HRMS_C1_WEBHOOK_SECRET'),
        //         'signature_header' => 'X-Hrms-Signature',
        //         'timestamp_header' => 'X-Hrms-Timestamp',
        //         'tolerance_seconds' => 300,
        //     ],
        //     'payload_map' => [
        //         'event_id' => 'event_id',
        //         'event_type' => 'event_type',
        //         'occurred_at' => 'occurred_at',
        //         'leave_id' => 'leave.id',
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

    'payload_defaults' => [
        'event_id' => 'event_id',
        'event_type' => 'event_type',
        'occurred_at' => 'occurred_at',
        'leave_id' => 'leave.id',
    ],

    /*
     * Requests per minute per company on the webhook endpoint.
     */
    'rate_limit_per_minute' => env('HRMS_RATE_LIMIT', 300),
];
