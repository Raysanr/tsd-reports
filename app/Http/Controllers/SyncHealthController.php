<?php

namespace App\Http\Controllers;

use App\Models\SyncRun;
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

        return view('sync-health', compact('health', 'runs', 'totalRuns', 'failedRuns', 'successRate'));
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

        return redirect()->route('sync-health')
            ->with($success ? 'success' : 'error', $message);
    }
}
