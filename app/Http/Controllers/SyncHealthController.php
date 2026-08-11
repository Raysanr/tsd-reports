<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Models\SyncRun;
use App\Support\ActivityLogger;
use App\Support\SyncHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class SyncHealthController extends Controller
{
    /** Bound on how many of the selected range's days "Fix Now" re-syncs from
     *  Pancake (see reconcileStatuses() doc comment) — this service runs
     *  `php artisan serve` as a single process (no php-fpm, no queue worker;
     *  see the Dockerfile), so a wide range looped synchronously here risks
     *  hitting Railway's request timeout and freezing the whole app for every
     *  user mid-request. 3 is a deliberately conservative starting point given
     *  no production timing data exists yet for a full-day pancake:sync-today
     *  call (unlike reconcile-statuses, which already has a measured data
     *  point: a 9-day window took long enough to look like a hang) — revisit
     *  upward once a real duration-per-day number is known. */
    private const MAX_TSA_REMATCH_DAYS = 3;

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
     * on its own (see that command's docblock) — PLUS (2026-08-11) a bounded
     * TSA/team re-match pass, since reconcile-statuses alone never touches
     * tsa_name/team.
     *
     * Why the re-match needs a real Pancake re-fetch, not a local recompute:
     * extractTsaInfo()'s PRIMARY signal is assigning_seller off Pancake's raw
     * item payload, which isn't persisted locally (orders only keeps raw_tags)
     * — so re-deriving tsa_name from local data alone would silently fall back
     * to the weaker tag-only path and could make some orders WRONG, not right.
     * pancake:sync-today (the same command "Retry a Date" already uses) is the
     * only thing that re-fetches that signal, so the re-match loop below is
     * just that command run once per date, upserting idempotently.
     *
     * Why it's capped to MAX_TSA_REMATCH_DAYS instead of the whole selected
     * range: this service runs `php artisan serve` as a single process, no
     * queue worker to push this into the background — looping a full-day
     * Pancake re-fetch over a wide range inside one synchronous request risks
     * a Railway request timeout that would freeze the app for every user
     * mid-request. Runs the days closest to date_to (most likely to matter —
     * e.g. right after editing a TSA's keywords) rather than refusing outright
     * on a wide range. Reconciliation itself is unaffected by this cap — it
     * still runs across the FULL selected range, same as before.
     *
     * 2026-08-10: "Fix Now" switched from a "days back" number input to the
     * same date-range picker every other report uses, so date_from/date_to
     * (an explicit calendar range) is now the primary path — --days is kept
     * as the fallback default (30) for any caller that only sends `days` or
     * nothing at all, so nothing already depending on that shape breaks. The
     * TSA re-match pass below only runs when an explicit date_from/date_to is
     * present, since --days-only callers predate this feature.
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

        // TSA/team re-match — runs BEFORE reconcile-statuses below, not after:
        // pancake:sync-today upserts every field it fetches, including status/
        // amount, so running it after reconcile-statuses could clobber that
        // command's own (more targeted, more authoritative) corrections back
        // to whatever the regular list-orders endpoint still shows. Running
        // it first keeps reconcile-statuses as the final word on those fields,
        // same guarantee it already had before this addition existed.
        $rematchSummary = null;
        if (!empty($data['date_from'])) {
            $rematchSummary = $this->rematchTsaAttribution(
                $data['date_from'],
                $data['date_to'] ?? now('Asia/Manila')->toDateString()
            );
        }

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

        if ($rematchSummary) {
            $message .= ' ' . $rematchSummary;
        }

        ActivityLogger::log('sync-health.reconcile-statuses', null, $message);

        return redirect()->route('sync-health')
            ->with($failed ? 'error' : 'success', $message);
    }

    /** Re-syncs each date from Pancake (same mechanism as "Retry a Date"),
     *  which re-derives tsa_name/team fresh from current tsa_shifts keyword
     *  config — this is what actually corrects TSA Performance data, since
     *  reconcile-statuses never touches those columns. Capped at
     *  MAX_TSA_REMATCH_DAYS (see reconcileStatuses()'s doc comment for why);
     *  runs the days closest to $to first when the range exceeds the cap. */
    private function rematchTsaAttribution(string $from, string $to): string
    {
        $allDates = Carbon::parse($from)->toPeriod(Carbon::parse($to))
            ->toArray();

        $totalDays = count($allDates);
        $dates     = collect($allDates)
            ->sortByDesc(fn (Carbon $d) => $d->timestamp)
            ->take(self::MAX_TSA_REMATCH_DAYS)
            ->sortBy(fn (Carbon $d) => $d->timestamp)
            ->values();

        $succeeded = 0;
        $failed    = 0;

        foreach ($dates as $date) {
            $exitCode = \Artisan::call('pancake:sync-today', ['--date' => $date->toDateString()]);
            if ($exitCode === 0) {
                $succeeded++;
            } else {
                $failed++;
            }
        }

        $daysNote = $totalDays > $dates->count()
            ? " (most recent {$dates->count()} of {$totalDays} selected days — wider ranges are capped to keep this request from timing out)"
            : '';

        return $failed > 0
            ? "TSA re-match: {$succeeded} day(s) succeeded, {$failed} failed{$daysNote}."
            : "Also re-matched TSA attribution for {$dates->count()} day(s){$daysNote}.";
    }
}
