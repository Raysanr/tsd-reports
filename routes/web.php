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
    // throttle:login — named limiter defined in AppServiceProvider (5/min,
    // keyed by email+IP together). Previously unthrottled: unlimited password
    // guesses against any known/guessed email address.
    Route::post('/login',    [AuthController::class, 'login'])->middleware('throttle:login');

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
// anyone deactivated mid-session (see EnsureUserIsActive). 'last-seen' stamps
// last_seen_at here too, so User Management's online indicator (User::isOnline())
// covers Call Tracker's pages as well, not just TSD Reports' own.
Route::middleware(['auth', 'active', 'last-seen'])->group(function () {
    Route::get('/hub', fn () => view('hub'))->name('hub');

    Route::get('/',                [DashboardController::class,      'index'])->name('dashboard');
    Route::post('/sync',           [DashboardController::class,      'sync'])->name('dashboard.sync')
        ->middleware('role:super_admin,admin,normal');
    Route::get('/sync/status',     [DashboardController::class,      'syncStatus'])->name('dashboard.sync.status')
        ->middleware('role:super_admin,admin,normal');
    Route::get('/leads-report',    [LeadsReportController::class,    'index'])->name('leads-report');
    Route::get('/leads-report/drilldown', [LeadsReportController::class, 'drilldown'])->name('leads-report.drilldown');
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
        Route::post('/sync-health/reconcile-statuses', [SyncHealthController::class, 'reconcileStatuses'])->name('sync-health.reconcile-statuses');

        Route::get('/audit-log',         [ActivityLogController::class, 'index'])->name('audit-log');

        Route::get('/unmatched-orders',          [UnmatchedOrdersController::class, 'index'])->name('unmatched-orders');
        Route::post('/unmatched-orders/reinfer', [UnmatchedOrdersController::class, 'reinfer'])->name('unmatched-orders.reinfer');

        Route::get('/settings',          [SettingsController::class, 'index'])->name('settings');
        Route::post('/settings',         [SettingsController::class, 'save'])->name('settings.save');
        Route::post('/settings/detect',  [SettingsController::class, 'detect'])->name('settings.detect');
        Route::post('/settings/clear',   [SettingsController::class, 'clear'])->name('settings.clear');
        Route::post('/settings/shifts',  [SettingsController::class, 'saveShifts'])->name('settings.shifts');
        Route::post('/settings/drive',           [SettingsController::class, 'saveDrive'])->name('settings.drive.save');
        Route::post('/settings/drive/clear',     [SettingsController::class, 'clearDrive'])->name('settings.drive.clear');
        Route::post('/settings/drive/sync-now',  [SettingsController::class, 'syncDriveNow'])->name('settings.drive.sync-now');
        // Ported from call-tracker (merged into one app 2026-08-12) — folded
        // onto this same Settings page rather than a second one, since both
        // would otherwise edit the same underlying Pancake connection.
        Route::post('/settings/access-token',        [SettingsController::class, 'saveAccessToken'])->name('settings.access-token.save');
        Route::post('/settings/access-token/clear',  [SettingsController::class, 'clearAccessToken'])->name('settings.access-token.clear');

        Route::get('/user-management',                    [UserManagementController::class, 'index'])->name('user-management');
        Route::post('/user-management',                    [UserManagementController::class, 'store'])->name('user-management.store');
        Route::put('/user-management/{user}',               [UserManagementController::class, 'update'])->name('user-management.update');
        Route::patch('/user-management/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('user-management.toggle-active');

        // Hub-styled entry point to the SAME controller/data/rules as
        // /user-management above (explicit request, 2026-08-12) — a
        // separate view+route pair, not a separate feature, so there's only
        // ever one place these business rules live.
        Route::get('/hub/users',                    [UserManagementController::class, 'hubIndex'])->name('hub.users');
        Route::post('/hub/users',                    [UserManagementController::class, 'store'])->name('hub.users.store');
        Route::put('/hub/users/{user}',               [UserManagementController::class, 'update'])->name('hub.users.update');
        Route::patch('/hub/users/{user}/toggle-active', [UserManagementController::class, 'toggleActive'])->name('hub.users.toggle-active');
    });

    // Call Tracker — ported from the standalone call-tracker app (merged into
    // one Laravel app 2026-08-12). Fully qualified class names throughout
    // this group (not `use` imports at the top of this file) — several of
    // these short class names (DashboardController, TsaManagementController,
    // SyncHealthController, ActivityLogController) already collide with this
    // file's own existing imports above, which are a DIFFERENT set of
    // classes for TSD Reports' own unrelated pages.
    Route::prefix('calls')->name('calls.')->group(function () {
        Route::get('/', [\App\Http\Controllers\CallTracker\DashboardController::class, 'index'])->name('dashboard');
        Route::post('/sync', [\App\Http\Controllers\CallTracker\DashboardController::class, 'sync'])->name('dashboard.sync');
        Route::get('/sync/status', [\App\Http\Controllers\CallTracker\DashboardController::class, 'syncStatus'])->name('dashboard.sync.status');

        Route::get('/leads', [\App\Http\Controllers\CallTracker\LeadController::class, 'index'])->name('leads.index');
        Route::get('/leads/{lead}', [\App\Http\Controllers\CallTracker\LeadController::class, 'show'])->name('leads.show');
        Route::get('/leads/{lead}/conversation', [\App\Http\Controllers\CallTracker\LeadController::class, 'conversation'])->name('leads.conversation');
        Route::get('/leads/{lead}/tags', [\App\Http\Controllers\CallTracker\LeadController::class, 'searchTags'])->name('leads.tags');
        Route::post('/leads/{lead}/disposition', [\App\Http\Controllers\CallTracker\LeadController::class, 'updateDisposition'])->name('leads.disposition');
        Route::get('/leads/{lead}/products', [\App\Http\Controllers\CallTracker\LeadController::class, 'searchProducts'])->name('leads.products');
        Route::post('/leads/{lead}/upsell', [\App\Http\Controllers\CallTracker\LeadController::class, 'addUpsell'])->name('leads.upsell');
        Route::post('/leads/{lead}/status', [\App\Http\Controllers\CallTracker\LeadController::class, 'updateStatus'])->name('leads.status');
        Route::post('/leads/{lead}/call-click', [\App\Http\Controllers\CallTracker\LeadController::class, 'logCallClick'])->name('leads.call-click');
        Route::post('/leads/{lead}/end-call', [\App\Http\Controllers\CallTracker\LeadController::class, 'endCall'])->name('leads.end-call');
        Route::post('/leads/{lead}/pin', [\App\Http\Controllers\CallTracker\LeadController::class, 'togglePin'])->name('leads.pin');
        Route::post('/leads/{lead}/transfer', [\App\Http\Controllers\CallTracker\LeadController::class, 'transfer'])->name('leads.transfer');
        Route::get('/leads/{lead}/recordings', [\App\Http\Controllers\CallTracker\LeadController::class, 'recordings'])->name('leads.recordings');
        Route::get('/leads/{lead}/recordings/{fileId}/stream', [\App\Http\Controllers\CallTracker\LeadController::class, 'streamRecording'])->name('leads.recordings.stream');
        Route::get('/leads/{lead}/notes', [\App\Http\Controllers\CallTracker\LeadController::class, 'notes'])->name('leads.notes');
        Route::post('/leads/{lead}/notes', [\App\Http\Controllers\CallTracker\LeadController::class, 'updateNotes'])->name('leads.notes.update');

        Route::get('/api/notification-counts', [\App\Http\Controllers\CallTracker\NotificationController::class, 'counts'])->name('notifications.counts');
        Route::post('/tsa-status', [\App\Http\Controllers\CallTracker\TsaStatusController::class, 'update'])->name('tsa-status.update');
        Route::get('/api/own-status', [\App\Http\Controllers\CallTracker\TsaStatusController::class, 'own'])->name('tsa-status.own');

        Route::middleware('role:super_admin,admin')->group(function () {
            Route::get('/tsa-management', [\App\Http\Controllers\CallTracker\TsaManagementController::class, 'index'])->name('tsa-management');
            Route::post('/tsa-management', [\App\Http\Controllers\CallTracker\TsaManagementController::class, 'store'])->name('tsa-management.store');
            Route::get('/tsa-management/pos-users', [\App\Http\Controllers\CallTracker\TsaManagementController::class, 'searchPosUsers'])->name('tsa-management.pos-users');
            Route::post('/tsa-management/{tsaShift}', [\App\Http\Controllers\CallTracker\TsaManagementController::class, 'update'])->name('tsa-management.update');
            Route::post('/tsa-management/{tsaShift}/regenerate-token', [\App\Http\Controllers\CallTracker\TsaManagementController::class, 'regenerateApiToken'])->name('tsa-management.regenerate-token');

            Route::get('/round-robin-setup', [\App\Http\Controllers\CallTracker\RoundRobinSetupController::class, 'index'])->name('round-robin-setup');
            Route::post('/round-robin-setup/{tsaShift}', [\App\Http\Controllers\CallTracker\RoundRobinSetupController::class, 'update'])->name('round-robin-setup.update');

            Route::get('/monitor', [\App\Http\Controllers\CallTracker\MonitorController::class, 'index'])->name('monitor');
            Route::post('/monitor/{tsa}/end-call', [\App\Http\Controllers\CallTracker\MonitorController::class, 'endCall'])->name('monitor.end-call');
            Route::get('/monitor/export', [\App\Http\Controllers\CallTracker\MonitorController::class, 'export'])->name('monitor.export');

            Route::get('/sync-health', [\App\Http\Controllers\CallTracker\SyncHealthController::class, 'index'])->name('sync-health');
            Route::get('/analytics', [\App\Http\Controllers\CallTracker\AnalyticsController::class, 'index'])->name('analytics');
            Route::get('/call-log', [\App\Http\Controllers\CallTracker\CallLogController::class, 'index'])->name('call-log');
            Route::get('/tsa-logs', [\App\Http\Controllers\CallTracker\TsaStatusController::class, 'index'])->name('tsa-logs');

            // Same shared SettingsController@index as TSD Reports' own
            // /settings — reuses the one form/data, just rendered inside
            // Call Tracker's own layout (see SettingsController::index()'s
            // $layout logic and redirectToCaller()).
            Route::get('/settings', [SettingsController::class, 'index'])->name('settings');
        });
    });
});
