<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\CompanyAdmin\CompanyAdminController;
use App\Http\Controllers\CompanyAdmin\CompanySettingController;
use App\Http\Controllers\CompanyAdmin\EmployeeController;
use App\Http\Controllers\CompanyAdmin\MealAdjustmentController;
use App\Http\Controllers\CompanyAdmin\SkipController;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Http\Controllers\TiffinAdmin\TiffinAdminController;
use App\Http\Controllers\TiffinAdmin\VendorPreparationController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    if (Auth::check()) {
        return match (Auth::user()->role) {
            'super_admin' => redirect()->route('super-admin.dashboard'),
            'tiffin_admin' => redirect()->route('tiffin-admin.dashboard'),
            'company_admin' => redirect()->route('company-admin.dashboard'),
            default => redirect('/login'),
        };
    }

    return Inertia::render('Welcome');
});

// Guest Auth Routes
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:5,1');
});

// Authenticated Routes
Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    // Super Admin Routes
    Route::middleware('role:super_admin')->prefix('super-admin')->group(function () {
        Route::get('/dashboard', [SuperAdminController::class, 'index'])->name('super-admin.dashboard');
        Route::post('/companies', [SuperAdminController::class, 'storeCompany'])->name('super-admin.companies.store');
        Route::delete('/companies/{company}', [SuperAdminController::class, 'destroyCompany'])->name('super-admin.companies.destroy');
        Route::post('/tiffin-services', [SuperAdminController::class, 'storeTiffin'])->name('super-admin.tiffin-services.store');
        Route::delete('/tiffin-services/{tiffinService}', [SuperAdminController::class, 'destroyTiffin'])->name('super-admin.tiffin-services.destroy');
        Route::post('/assign', [SuperAdminController::class, 'assign'])->name('super-admin.assign');
        Route::post('/unpair', [SuperAdminController::class, 'unpair'])->name('super-admin.unpair');
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

        // Settings Management
        Route::put('/settings', [CompanySettingController::class, 'update'])->name('company-admin.settings.update');
        // CSV
        Route::get('/employees', [EmployeeController::class, 'index'])->name('company-admin.employees.index');
        Route::post('/employees', [EmployeeController::class, 'store'])->name('company-admin.employees.store');
        Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('company-admin.employees.update');
        Route::post('/employees/csv-preview', [EmployeeController::class, 'previewCsv'])->name('company-admin.employees.csv-preview');
        Route::post('/employees/csv-import', [EmployeeController::class, 'importCsv'])->name('company-admin.employees.csv-import');

        // Skip Management
        Route::post('/skips', [SkipController::class, 'store'])->name('company-admin.skips.store');
        Route::delete('/skips/{skip}', [SkipController::class, 'destroy'])->name('company-admin.skips.destroy');
        Route::post('/skips/bulk', [SkipController::class, 'bulkStore'])->name('company-admin.skips.bulk-store');

        // Extra Meals Management
        Route::post('/extra-meals', [MealAdjustmentController::class, 'store'])->name('company-admin.extra-meals.store');
        Route::delete('/extra-meals/{adjustment}', [MealAdjustmentController::class, 'destroy'])->name('company-admin.extra-meals.destroy');

    });
});
