<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\TeamReportController;
use App\Http\Controllers\TsaPerformanceController;
use App\Http\Controllers\ChartsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TsaManagementController;
use App\Http\Controllers\ProductManagementController;

// Guest-only: a signed-in user hitting these is bounced to the dashboard
// instead of seeing the login/register form again.
Route::middleware('guest')->group(function () {
    Route::get('/login',     [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',    [AuthController::class, 'login']);
    Route::get('/register',  [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register']);

    Route::get('/auth/google',          [AuthController::class, 'redirectToGoogle'])->name('google.redirect');
    Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback'])->name('google.callback');
});

Route::post('/logout', [AuthController::class, 'logout'])
    ->middleware('auth')
    ->name('logout');

// No 'auth' middleware — this is hit by an external cron pinger, not a signed-in
// user. Protected instead by a random token (CRON_SECRET, checked in the
// controller) that only Render's env vars and the pinger config know.
Route::get('/cron/run', [CronController::class, 'run'])->name('cron.run');

// Every report/config page requires a signed-in user — this is the
// "before the dashboard" gate the login/register pages exist for.
Route::middleware('auth')->group(function () {
    Route::get('/',                [DashboardController::class,      'index'])->name('dashboard');
    Route::post('/sync',           [DashboardController::class,      'sync'])->name('dashboard.sync');
    Route::get('/team-report',     [TeamReportController::class,     'index'])->name('team-report');
    Route::get('/tsa-performance', [TsaPerformanceController::class, 'index'])->name('tsa-performance');
    Route::get('/tsa-performance/{team}/{tsaKey}', [TsaPerformanceController::class, 'showTsa'])->name('tsa-performance.individual');
    Route::get('/charts',          [ChartsController::class,         'index'])->name('charts');

    Route::get('/tsa-management',             [TsaManagementController::class, 'index'])->name('tsa-management');
    Route::get('/tsa-management/pos-users',   [TsaManagementController::class, 'searchPosUsers'])->name('tsa-management.pos-users');
    Route::post('/tsa-management',            [TsaManagementController::class, 'store'])->name('tsa-management.store');
    Route::put('/tsa-management/{tsaShift}',  [TsaManagementController::class, 'update'])->name('tsa-management.update');
    Route::delete('/tsa-management/{tsaShift}', [TsaManagementController::class, 'destroy'])->name('tsa-management.destroy');

    Route::get('/product-management',               [ProductManagementController::class, 'index'])->name('product-management');
    Route::post('/product-management',               [ProductManagementController::class, 'store'])->name('product-management.store');
    Route::put('/product-management/{product}',      [ProductManagementController::class, 'update'])->name('product-management.update');
    Route::delete('/product-management/{product}',   [ProductManagementController::class, 'destroy'])->name('product-management.destroy');
    Route::patch('/product-management/{product}/toggle-hidden', [ProductManagementController::class, 'toggleHidden'])->name('product-management.toggle-hidden');

    Route::get('/settings',          [SettingsController::class, 'index'])->name('settings');
    Route::post('/settings',         [SettingsController::class, 'save'])->name('settings.save');
    Route::post('/settings/detect',  [SettingsController::class, 'detect'])->name('settings.detect');
    Route::post('/settings/clear',   [SettingsController::class, 'clear'])->name('settings.clear');
    Route::post('/settings/shifts',  [SettingsController::class, 'saveShifts'])->name('settings.shifts');
});
