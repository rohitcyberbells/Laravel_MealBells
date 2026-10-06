<?php

use App\Http\Controllers\Api\HrmsWebhookController;
use Illuminate\Support\Facades\Route;

/*
 * HRMS inbound webhook.
 *
 * Deliberately not in routes/web.php: the web group carries sessions, CSRF and
 * EnsureMustChangePassword, none of which a machine caller can satisfy.
 *
 * The company is bound by its public code, so each tenant gets its own URL and
 * its own secret, and internal sequential ids are not exposed.
 */
Route::post('/hrms/{company:code}/events', [HrmsWebhookController::class, 'store'])
    ->middleware(['hrms.signature', 'throttle:hrms-webhook'])
    ->name('api.hrms.events');
