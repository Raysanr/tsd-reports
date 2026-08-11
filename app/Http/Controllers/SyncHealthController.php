<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Support\ActivityLogger;
use App\Support\SyncHealth;
use Illuminate\Http\Request;

class SyncHealthController extends Controller
{
    public function index(Request $request)
    {
        $health = SyncHealth::status();

        $runs = SyncRun::orderByDesc('ran_at')->paginate(30)->withQueryString();

        // Same aggregate stats a health dashboard needs at a glance — computed
        // over ALL runs, not just the current page, so paginating doesn't change
        // these numbers.
        $totalRuns   = SyncRun::count();
        $failedRuns  = SyncRun::where('success', false)->count();
        $successRate = $totalRuns > 0 ? round(($totalRuns - $failedRuns) / $totalRuns * 100, 1) : null;

        // The card's headline number used to be the all-time failure count —
        // a background job running every minute racks up hundreds of historical
        // failures over months even when everything's fine right now, so that
        // number sat in alarming red directly beside a green "Sync healthy"
        // banner. 24h is what's actually actionable; all-time stays as
        // secondary context underneath instead of disappearing.
        $failedRuns24h = SyncRun::where('success', false)->where('ran_at', '>=', now()->subDay())->count();

        return view('sync-health', compact('health', 'runs', 'totalRuns', 'failedRuns', 'failedRuns24h', 'successRate'));
    }

    /**
     * Manually re-run the sync for one specific date, from this page (distinct
     * from the Dashboard's Sync button, which syncs a date RANGE) — useful when
     * this page's history shows one specific day failed or looks incomplete.
     */
    public function retry(Request $request)
    {
        $data = $request->validate([
            'date' => 'required|date|before_or_equal:today',
        ]);

        \Artisan::call('pancake:sync-today', ['--date' => $data['date']]);

        $lastRun = SyncRun::orderByDesc('id')->first();
        $success = $lastRun && $lastRun->success;

        // error_message can contain a redacted-but-still-present API-key-adjacent
        // string (see SyncHealth::redactSecrets doc-comment) — this flash message
        // is rendered straight into the page as a toast, so it goes through the
        // same redaction DashboardController::sync() already applies to the same
        // field before it reaches a browser.
        $message = $success
            ? "Synced {$data['date']} — {$lastRun->new_orders} new orders, {$lastRun->upsell_count} upsells."
            : 'Sync failed: ' . (SyncHealth::redactSecrets($lastRun->error_message ?? null) ?? 'Unknown error.');

        ActivityLogger::log('sync-health.retry', null, "Retried sync for {$data['date']} — " . ($success ? 'succeeded' : 'failed') . '.');

        return redirect()->route('sync-health')
            ->with($success ? 'success' : 'error', $message);
    }

    /**
     * Manual trigger for pancake:reconcile-statuses — corrects local orders
     * Pancake has since canceled/deleted, which the regular sync can never catch
     * on its own (see that command's docblock).
     *
     * 2026-08-10: "Fix Now" switched from a "days back" number input to the
     * same date-range picker every other report uses, so date_from/date_to
     * (an explicit calendar range) is now the primary path — --days is kept
     * as the fallback default (30) for any caller that only sends `days` or
     * nothing at all, so nothing already depending on that shape breaks.
     *
     * Note on "safe to run synchronously": true for the list-fetch pass (a
     * handful of paginated JSON calls), but the second pass makes one live
     * Pancake lookup PER SUSPECT ORDER in the window — confirmed live
     * (2026-08-10), a 9-day window checked 647 orders and took long enough,
     * with zero progress feedback on a plain form POST, to look indistin-
     * guishable from a hang even though it did complete. Both HTTP call
     * sites are now resilient to a single slow/failed request (see that
     * command's own try/catch), but the wall-clock time for a wide range
     * is still real — this endpoint would benefit from a progress indicator
     * or a background job if wide ranges become the norm rather than the
     * exception.
     */
    public function reconcileStatuses(Request $request)
    {
        $data = $request->validate([
            'days'      => 'nullable|integer|min:1|max:365',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        $options = !empty($data['date_from'])
            ? ['--from' => $data['date_from'], '--to' => $data['date_to'] ?? now('Asia/Manila')->toDateString()]
            : ['--days' => $data['days'] ?? 30];

        $exitCode = \Artisan::call('pancake:reconcile-statuses', $options);

        $failed         = $exitCode !== 0;
        $checked        = (int) Setting::get('order_status_reconcile_last_checked', 0);
        $corrected      = (int) Setting::get('order_status_reconcile_last_corrected', 0);
        $amountCorrected = (int) Setting::get('order_status_reconcile_last_amount_corrected', 0);

        $message = $failed
            ? 'Reconciliation failed — check the Pancake API key/shop ID.'
            : "Checked {$checked} Pancake-removed order(s); corrected {$corrected} stale local record(s)"
                . ($amountCorrected > 0 ? ", refreshed {$amountCorrected} upsell amount(s)." : '.');

        ActivityLogger::log('sync-health.reconcile-statuses', null, $message);

        return redirect()->route('sync-health')
            ->with($failed ? 'error' : 'success', $message);
    }
}
