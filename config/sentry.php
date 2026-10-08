<?php

use App\Observability\SentryScrubber;

/**
 * Sentry, entirely optional.
 *
 * With no DSN the SDK initialises nothing, reports nothing and makes no network
 * calls - so an installation that does not want a third party seeing its errors
 * simply leaves SENTRY_DSN unset and is unaffected.
 *
 * Everything that does leave passes through App\Observability\SentryScrubber
 * first. That is not optional, and it is the reason this is safe to enable: by
 * default an error tracker receives the request body that caused the error, and
 * in MealBells that body is sometimes a sign-in form holding a password and
 * sometimes an HRMS payload holding a whole company's leave with employee names
 * and codes. Turning off send_default_pii does not cover that - the payload is
 * in the request data, not in the PII fields.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    // @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    /*
     * Every event and every transaction is scrubbed before it is sent.
     *
     * Returning null from either of these drops the event, so a fault in the
     * scrubber fails closed - nothing is reported - rather than open.
     */
    'before_send' => [SentryScrubber::class, 'scrub'],
    'before_send_transaction' => [SentryScrubber::class, 'scrub'],

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    /*
     * Which build an error came from. Without it every regression looks like it
     * has always been there, and "did the deploy cause this" is unanswerable.
     *
     * Deliberately not read from git at runtime: a production release folder
     * often has no .git, and shelling out on every boot to find out is worse
     * than being told. Set it in the deploy script.
     */
    'release' => env('SENTRY_RELEASE'),

    // Falls back to APP_ENV, which is what you want: without it a staging
    // error is indistinguishable from a production one, and wakes someone up.
    'environment' => env('SENTRY_ENVIRONMENT', env('APP_ENV')),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_metrics
    'enable_metrics' => env('SENTRY_ENABLE_METRICS', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // The minimum log level that will be sent to Sentry as logs using the `sentry_logs` logging channel
    'logs_channel_level' => env('SENTRY_LOG_LEVEL', env('SENTRY_LOGS_LEVEL', env('LOG_LEVEL', 'debug'))),

    /*
     * Stays false. The scrubber would strip what this adds anyway, so turning
     * it on buys nothing and removes the outer guard.
     *
     * @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
     */
    'send_default_pii' => false,

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    'ignore_transactions' => [
        // The monitoring endpoints, polled every minute. Tracing them would be
        // most of the quota and tells nobody anything.
        '/up',
        '/health/ping',
    ],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture Laravel logs as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),

        // Capture Livewire components like routes as breadcrumbs
        'livewire' => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        /*
         * Stays off. Bindings are the actual values: an email address, an
         * employee code, a leave reason. The query shape is the useful part for
         * debugging, and it is kept.
         */
        'sql_bindings' => false,

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Off, for the same reason as the breadcrumb setting above.
        'sql_bindings' => false,

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture Livewire components as spans
        'livewire' => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Configures if the performance trace should continue after the response has been sent to the user until the application terminates
        // This is required to capture any spans that are created after the response has been sent like queue jobs dispatched using `dispatch(...)->afterResponse()` for example
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),

        // Capture AI agent interactions as spans (requires laravel/ai)
        'gen_ai' => env('SENTRY_TRACE_GEN_AI_ENABLED', true),

        // Capture AI invoke_agent spans
        'gen_ai_invoke_agent' => env('SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED', true),

        // Capture AI chat spans
        'gen_ai_chat' => env('SENTRY_TRACE_GEN_AI_CHAT_ENABLED', true),

        // Capture AI execute_tool spans
        'gen_ai_execute_tool' => env('SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED', true),

        // Capture AI embeddings spans
        'gen_ai_embeddings' => env('SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED', true),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
