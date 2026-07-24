<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LeadsReportController;
use App\Http\Controllers\TsaPerformanceController;
use App\Http\Controllers\ChartsController;
use App\Http\Controllers\RtsReportController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\TsaManagementController;
use App\Http\Controllers\ProductManagementController;
use App\Http\Controllers\UserManagementController;
use App\Http\Controllers\SyncHealthController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\UnmatchedOrdersController;

// Guest-only: a signed-in user hitting these is bounced to the dashboard
// instead of seeing the login/register form again.
Route::middleware('guest')->group(function () {
    Route::get('/login',     [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login',    [AuthController::class, 'login']);

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

// Every report/config page requires a signed-in, active user — 'active' force-logs-out
// anyone deactivated mid-session (see EnsureUserIsActive).
Route::middleware(['auth', 'active'])->group(function () {
    Route::get('/',                [DashboardController::class,      'index'])->name('dashboard');
    Route::post('/sync',           [DashboardController::class,      'sync'])->name('dashboard.sync')
        ->middleware('role:super_admin,admin,normal');
    Route::get('/leads-report',    [LeadsReportController::class,    'index'])->name('leads-report');
    // Old URL kept alive for bookmarks/history — permanent redirect to the new name.
    Route::redirect('/team-report', '/leads-report', 301);
    Route::get('/tsa-performance', [TsaPerformanceController::class, 'index'])->name('tsa-performance');
    Route::get('/tsa-performance/drilldown', [TsaPerformanceController::class, 'drilldown'])->name('tsa-performance.drilldown');
    Route::get('/tsa-performance/{team}/{tsaKey}', [TsaPerformanceController::class, 'showTsa'])->name('tsa-performance.individual');
    Route::get('/charts',          [ChartsController::class,         'index'])->name('charts');
    Route::get('/rts-report',      [RtsReportController::class,      'index'])->name('rts-report');
    Route::get('/search',          [SearchController::class,         'search'])->name('search');

    // CONFIG — Super Admin and Admin only.
    Route::middleware('role:super_admin,admin')->group(function () {
        Route::get('/tsa-management',             [TsaManagementController::class, 'index'])->name('tsa-management');
        Route::get('/tsa-management/pos-users',   [TsaManagementController::class, 'searchPosUsers'])->name('tsa-management.pos-users');
        Route::get('/tsa-management/tags',        [TsaManagementController::class, 'searchTags'])->name('tsa-management.tags');
        Route::post('/tsa-management/bulk',       [TsaManagementController::class, 'bulk'])->name('tsa-management.bulk');
        Route::post('/tsa-management',            [TsaManagementController::class, 'store'])->name('tsa-management.store');
        Route::put('/tsa-management/{tsaShift}',  [TsaManagementController::class, 'update'])->name('tsa-management.update');
        Route::delete('/tsa-management/{tsaShift}', [TsaManagementController::class, 'destroy'])->name('tsa-management.destroy');
        Route::post('/tsa-management/{id}/restore', [TsaManagementController::class, 'restore'])->name('tsa-management.restore');
        Route::post('/tsa-management/rest-days/{date}', [TsaManagementController::class, 'saveRestDays'])->name('tsa-management.rest-days');

        Route::get('/product-management',               [ProductManagementController::class, 'index'])->name('product-management');
        Route::get('/product-management/search-pos-products', [ProductManagementController::class, 'searchPosProducts'])->name('product-management.search-pos-products');
        Route::post('/product-management/bulk',          [ProductManagementController::class, 'bulk'])->name('product-management.bulk');
        Route::post('/product-management',               [ProductManagementController::class, 'store'])->name('product-management.store');
        Route::put('/product-management/{product}',      [ProductManagementController::class, 'update'])->name('product-management.update');
        Route::delete('/product-management/{product}',   [ProductManagementController::class, 'destroy'])->name('product-management.destroy');
        Route::post('/product-management/{id}/restore', [ProductManagementController::class, 'restore'])->name('product-management.restore');
        Route::patch('/product-management/{product}/toggle-hidden', [ProductManagementController::class, 'toggleHidden'])->name('product-management.toggle-hidden');

        Route::get('/sync-health',       [SyncHealthController::class, 'index'])->name('sync-health');
        Route::post('/sync-health/retry', [SyncHealthController::class, 'retry'])->name('sync-health.retry');

        Route::get('/audit-log',         [ActivityLogController::class, 'index'])->name('audit-log');

        Route::get('/unmatched-orders',          [UnmatchedOrdersController::class, 'index'])->name('unmatched-orders');
        Route::post('/unmatched-orders/reinfer', [UnmatchedOrdersController::class, 'reinfer'])->name('unmatched-orders.reinfer');

        Route::get('/settings',          [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings',         [SettingsController::class, 'save'])->name('settings.save');
        Route::post('/settings/detect',  [SettingsController::class, 'detect'])->name('settings.detect');
        Route::post('/settings/clear',   [SettingsController::class, 'clear'])->name('settings.clear');
        Route::post('/settings/shifts',  [SettingsController::class, 'saveShifts'])->name('settings.shifts');
        Route::post('/settings/drive',       [SettingsController::class, 'saveDrive'])->name('settings.drive.save');
        Route::post('/settings/drive/clear', [SettingsController::class, 'clearDrive'])->name('settings.drive.clear');

        Route::get('/user-management',                    [UserManagementController::class, 'index'])->name('user-management');
        Route::post('/user-management',                    [UserManagementController::class, 'store'])->name('user-management.store');
        Route::put('/user-management/{user}',               [UserManagementController::class, 'update'])->name('user-management.update');
        Route::patch('/user-management/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('user-management.toggle-active');
    });
});
