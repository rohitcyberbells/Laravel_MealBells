<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\CompanyAdmin\AdoptionReportController;
use App\Http\Controllers\CompanyAdmin\AttendanceReportController;
use App\Http\Controllers\CompanyAdmin\CompanyAdminController;
use App\Http\Controllers\CompanyAdmin\CompanyCalendarController;
use App\Http\Controllers\CompanyAdmin\CompanyDailyController;
use App\Http\Controllers\CompanyAdmin\CompanyExportController;
use App\Http\Controllers\CompanyAdmin\CompanyHrmsController;
use App\Http\Controllers\CompanyAdmin\CompanyRecurringSkipController;
use App\Http\Controllers\CompanyAdmin\CompanySettingController;
use App\Http\Controllers\CompanyAdmin\EmployeeController;
use App\Http\Controllers\CompanyAdmin\MealAdjustmentController;
use App\Http\Controllers\CompanyAdmin\SkipController;
use App\Http\Controllers\CompanyAdmin\SkipImportController;
use App\Http\Controllers\Employee\EmployeeDashboardController;
use App\Http\Controllers\Employee\RecurringSkipController;
use App\Http\Controllers\SuperAdmin\HealthController;
use App\Http\Controllers\SuperAdmin\HrmsConnectionController;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Http\Controllers\TiffinAdmin\TiffinAdminController;
use App\Http\Controllers\TiffinAdmin\VendorPreparationController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (Auth::check()) {
        return match (Auth::user()->role) {
            'super_admin' => redirect()->route('super-admin.dashboard'),
            'tiffin_admin' => redirect()->route('tiffin-admin.dashboard'),
            'company_admin' => redirect()->route('company-admin.dashboard'),
            'employee' => redirect()->route('employee.dashboard'),
            default => redirect('/login'),
        };
    }

    // Guests go straight to the sign-in page. There is nothing public to show:
    // every screen in MealBells belongs to a signed-in role, so a separate
    // landing page was only ever a developer smoke test.
    return redirect()->route('login');
});

// Guest Auth Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:login');

    // Forgotten passwords. Throttled per address as well as per place, because
    // the response is identical either way - so without a limit this form is a
    // way to mail-bomb one person, or to walk a list of addresses.
    Route::get('/forgot-password', [PasswordResetController::class, 'showRequestForm'])
        ->name('password.request');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendResetLink'])
        ->middleware('throttle:password-reset')
        ->name('password.email');

    Route::get('/reset-password/{token}', [PasswordResetController::class, 'showResetForm'])
        ->name('password.reset');
    Route::post('/reset-password', [PasswordResetController::class, 'resetPassword'])
        ->middleware('throttle:password-reset')
        ->name('password.store');
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/change-password', [LoginController::class, 'showChangePassword'])->name('password.change');
    Route::post('/change-password', [LoginController::class, 'updatePassword'])->name('password.update');

    // Super Admin Routes
    Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'index'])->name('super-admin.dashboard');
        Route::get('/health', [HealthController::class, 'index'])->name('super-admin.health');
        Route::post('/companies', [SuperAdminController::class, 'storeCompany'])->name('super-admin.companies.store');
        Route::delete('/companies/{company}', [SuperAdminController::class, 'destroyCompany'])->name('super-admin.companies.destroy');
        Route::post('/tiffin-services', [SuperAdminController::class, 'storeTiffin'])->name('super-admin.tiffin-services.store');
        Route::delete('/tiffin-services/{tiffinService}', [SuperAdminController::class, 'destroyTiffin'])->name('super-admin.tiffin-services.destroy');
        Route::post('/assign', [SuperAdminController::class, 'assign'])->name('super-admin.assign');
        Route::post('/unpair', [SuperAdminController::class, 'unpair'])->name('super-admin.unpair');
        Route::post('/users/{user}/reset-password', [SuperAdminController::class, 'resetPassword'])->name('super-admin.users.reset-password');
        Route::post('/users/{user}/active', [SuperAdminController::class, 'setActive'])->name('super-admin.users.active');
        Route::post('/companies/{company}/restore', [SuperAdminController::class, 'restoreCompany'])->name('super-admin.companies.restore');
        Route::post('/tiffin-services/{tiffinService}/restore', [SuperAdminController::class, 'restoreTiffin'])->name('super-admin.tiffin-services.restore');
        Route::post('/companies/{company}/hrms-secret', [HrmsConnectionController::class, 'rotate'])->name('super-admin.companies.hrms-secret');
    });

    // Tiffin Admin Routes
    Route::middleware('role:tiffin_admin')->prefix('tiffin-admin')->group(function () {
        Route::get('/dashboard', [TiffinAdminController::class, 'index'])->name('tiffin-admin.dashboard');
        Route::get('/preparation', [VendorPreparationController::class, 'show'])->name('tiffin-admin.preparation');
        Route::post('/weekly-menu', [TiffinAdminController::class, 'saveMenu'])->name('tiffin-admin.menu.save');
        Route::post('/daily-override', [TiffinAdminController::class, 'storeOverride'])->name('tiffin-admin.override.store');
    });

    // Company Admin Routes
    Route::middleware('role:company_admin')->prefix('company-admin')->group(function () {
        Route::get('/dashboard', [CompanyAdminController::class, 'index'])->name('company-admin.dashboard');

        // Daily Count & Confirmation
        Route::get('/daily', [CompanyDailyController::class, 'index'])->name('company-admin.daily.index');
        Route::post('/daily/acknowledge', [CompanyDailyController::class, 'acknowledge'])->name('company-admin.daily.acknowledge');
        Route::post('/late-changes', [CompanyDailyController::class, 'recordLateChange'])->name('company-admin.late-changes.store');

        // Settings Management
        Route::get('/settings', [CompanySettingController::class, 'index'])->name('company-admin.settings.index');
        Route::put('/settings', [CompanySettingController::class, 'update'])->name('company-admin.settings.update');
        Route::get('/export', [CompanyExportController::class, 'download'])->name('company-admin.export');
        Route::post('/admins', [CompanySettingController::class, 'storeAdmin'])->name('company-admin.admins.store');

        // CSV & Logins
        Route::get('/employees', [EmployeeController::class, 'index'])->name('company-admin.employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('company-admin.employees.store');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('company-admin.employees.update');
        Route::post('/employees/csv-preview', [EmployeeController::class, 'previewCsv'])->name('company-admin.employees.csv-preview');
        Route::post('/employees/csv-import', [EmployeeController::class, 'importCsv'])->name('company-admin.employees.csv-import');
        Route::post('/employees/logins', [EmployeeController::class, 'createLogins'])->name('company-admin.employees.logins');
        Route::post('/employees/{employee}/reset-password', [EmployeeController::class, 'resetPassword'])->name('company-admin.employees.reset-password');
        Route::post('/employees/{employee}/anonymise', [EmployeeController::class, 'anonymise'])->name('company-admin.employees.anonymise');

        // Skip Management
        Route::post('/skips', [SkipController::class, 'store'])->name('company-admin.skips.store');
        Route::delete('/skips/{skip}', [SkipController::class, 'destroy'])->name('company-admin.skips.destroy');
        Route::post('/skips/bulk', [SkipController::class, 'bulkStore'])->name('company-admin.skips.bulk-store');

        // CSV Skip Import (Leave / WFH)
        Route::post('/skip-imports/preview', [SkipImportController::class, 'preview'])->name('company-admin.skip-imports.preview');
        Route::post('/skip-imports/confirm', [SkipImportController::class, 'confirm'])->name('company-admin.skip-imports.confirm');

        // Company Calendar
        Route::get('/calendar', [CompanyCalendarController::class, 'index'])->name('company-admin.calendar.index');
        Route::post('/calendar', [CompanyCalendarController::class, 'store'])->name('company-admin.calendar.store');
        Route::post('/calendar/bulk', [CompanyCalendarController::class, 'storeBulk'])->name('company-admin.calendar.bulk-store');
        Route::delete('/calendar/{day}', [CompanyCalendarController::class, 'destroy'])->name('company-admin.calendar.destroy');

        // HR Recurring Skip Management for Employee
        Route::post('/employees/{employee}/recurring-skips', [CompanyRecurringSkipController::class, 'store'])->name('company-admin.employees.recurring-skips.store');
        Route::patch('/employees/{employee}/recurring-skips/{rule}', [CompanyRecurringSkipController::class, 'update'])->name('company-admin.employees.recurring-skips.update');
        Route::delete('/employees/{employee}/recurring-skips/{rule}', [CompanyRecurringSkipController::class, 'destroy'])->name('company-admin.employees.recurring-skips.destroy');

        // HRMS Connection
        Route::get('/hrms', [CompanyHrmsController::class, 'index'])->name('company-admin.hrms.index');
        Route::post('/hrms/secret', [CompanyHrmsController::class, 'rotateSecret'])->name('company-admin.hrms.secret');
        Route::post('/hrms/test-event', [CompanyHrmsController::class, 'sendTestEvent'])->name('company-admin.hrms.test-event');
        Route::post('/hrms/pull-connection', [CompanyHrmsController::class, 'savePullConnection'])->name('company-admin.hrms.pull-connection');
        Route::post('/hrms/pull-test', [CompanyHrmsController::class, 'testPullConnection'])->name('company-admin.hrms.pull-test');

        // Adoption Report
        Route::get('/reports/adoption', [AdoptionReportController::class, 'index'])->name('company-admin.reports.adoption');
        Route::get('/reports/attendance', [AttendanceReportController::class, 'index'])->name('company-admin.reports.attendance');

        // Extra Meals Management
        Route::post('/extra-meals', [MealAdjustmentController::class, 'store'])->name('company-admin.extra-meals.store');
        Route::delete('/extra-meals/{adjustment}', [MealAdjustmentController::class, 'destroy'])->name('company-admin.extra-meals.destroy');
    });

    // Employee Portal Routes
    Route::middleware('role:employee')->prefix('employee')->group(function () {
        Route::get('/dashboard', [EmployeeDashboardController::class, 'index'])->name('employee.dashboard');
        Route::post('/skips', [EmployeeDashboardController::class, 'storeSkip'])->name('employee.skips.store');
        Route::post('/skips/range', [EmployeeDashboardController::class, 'storeSkipRange'])->name('employee.skips.range.store');
        Route::delete('/skips/{skip}', [EmployeeDashboardController::class, 'destroySkip'])->name('employee.skips.destroy');

        // Employee Recurring Skips
        Route::post('/recurring-skips', [RecurringSkipController::class, 'store'])->name('employee.recurring-skips.store');
        Route::patch('/recurring-skips/{rule}', [RecurringSkipController::class, 'update'])->name('employee.recurring-skips.update');
        Route::delete('/recurring-skips/{rule}', [RecurringSkipController::class, 'destroy'])->name('employee.recurring-skips.destroy');
    });
});
