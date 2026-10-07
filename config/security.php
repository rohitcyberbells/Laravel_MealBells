<?php

return [
    /*
     * Strict-Transport-Security max-age, in seconds. Only ever sent over HTTPS.
     * One year is the usual value; start lower if you are not yet certain every
     * subdomain can serve https.
     */
    'hsts_max_age' => env('SECURITY_HSTS_MAX_AGE', 31536000),

    'csp' => [
        'enabled' => env('SECURITY_CSP_ENABLED', true),

        /*
         * False sends Content-Security-Policy-Report-Only: the browser reports
         * what it would have blocked and nothing breaks.
         *
         * Turn this on only after the reports are quiet. The policy still needs
         * 'unsafe-inline' for scripts because Inertia writes the page payload
         * into a script tag, so enforcing it today buys less than it looks.
         */
        'enforce' => env('SECURITY_CSP_ENFORCE', false),
    ],
];
