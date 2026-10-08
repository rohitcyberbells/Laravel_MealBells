<?php

use App\Http\Controllers\HealthPingController;
use App\Http\Middleware\EnsureMustChangePassword;
use App\Http\Middleware\EnsureUserRole;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\VerifyHrmsSignature;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',

        /*
         * Registered here rather than in routes/web.php so it carries neither
         * sessions nor CSRF nor EnsureMustChangePassword: a monitor can satisfy
         * none of those, and a session started per poll would fill the session
         * table. Same reasoning as the HRMS webhook in routes/api.php, but this
         * one keeps a bare path because an uptime monitor is configured by hand
         * and /api/health/ping reads like an application endpoint.
         *
         * '/up' is left exactly as it was, so anything already pointed at it
         * keeps working.
         */
        then: function (): void {
            Route::get('/health/ping', HealthPingController::class)
                ->middleware('throttle:health-ping')
                ->name('health.ping');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            EnsureMustChangePassword::class,
        ]);

        // Every response, including the API: headers that only constrain.
        $middleware->append(SecurityHeaders::class);

        $middleware->alias([
            'role' => EnsureUserRole::class,
            'must_change_password' => EnsureMustChangePassword::class,
            'hrms.signature' => VerifyHrmsSignature::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
