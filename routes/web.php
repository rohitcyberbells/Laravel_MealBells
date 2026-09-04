<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\EnsureUserRole;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\SuperAdmin\SuperAdminController;
use App\Http\Controllers\TiffinAdmin\TiffinAdminController;
use App\Http\Controllers\CompanyAdmin\CompanyAdminController; 
use Illuminate\Support\Facades\Auth;

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
        Route::post('/tiffin-services', [SuperAdminController::class, 'storeTiffin'])->name('super-admin.tiffin-services.store');
        Route::post('/assign', [SuperAdminController::class, 'assign'])->name('super-admin.assign');
    });

    // Tiffin Admin Routes
    Route::middleware('role:tiffin_admin')->prefix('tiffin-admin')->group(function () {
        Route::get('/dashboard', [TiffinAdminController::class, 'index'])->name('tiffin-admin.dashboard');
        Route::post('/weekly-menu', [TiffinAdminController::class, 'saveMenu'])->name('tiffin-admin.menu.save');
        Route::post('/daily-override', [TiffinAdminController::class, 'storeOverride'])->name('tiffin-admin.override.store');

    });


    // Company Admin Routes
    Route::middleware('role:company_admin')->prefix('company-admin')->group(function () {
       Route::get('/dashboard',[CompanyAdminController::class,'index'])->name('company-admin.dashboard');
    });
});
















