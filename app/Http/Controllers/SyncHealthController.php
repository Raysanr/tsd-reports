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
     *  user mid-request. Cut from 3 to 1 after a real production incident
     *  (2026-08-11): a POST to this endpoint took 3m9s and every other route
     *  returned 499 (client gave up) for the ENTIRE duration — confirmed via
     *  Railway network logs this app has no request concurrency at all
     *  (`PHP_CLI_SERVER_WORKERS` is unset in .env.example, so `php artisan
     *  serve` handles exactly one request at a time), so any slow request
     *  here doesn't just make its own caller wait, it takes the whole app
     *  down for everyone until it finishes. See MAX_RECONCILE_DAYS below too
     *  — that incident's range was very likely the default 30-day reconcile
     *  window (uncapped until this same fix), not just this addition. */
    private const MAX_TSA_REMATCH_DAYS = 1;

    /** Hard cap on how wide a date_from/date_to span reconcile-statuses itself
     *  will run over per click, regardless of what's selected in the picker —
     *  added after the 2026-08-11 incident above. Set to 9, not lower: that's
     *  the exact width reconcile-statuses' own doc comment already measured
     *  as "took long enough to look like a hang, but did complete" — a real,
     *  tested (see SyncHealthTest::test_reconcile_statuses_accepts_an_explicit_
     *  date_range_from_the_picker) known-good boundary, not a guess. Fix
     *  Now's picker defaults to a 30-day window, which — uncapped until this
     *  fix — was very likely most of that incident's 3m9s on its own, before
     *  MAX_TSA_REMATCH_DAYS's addition on top. Same "run the days closest to
     *  date_to" reasoning as the re-match cap. */
    private const MAX_RECONCILE_DAYS = 9;

    /** Same single-process/no-concurrency constraint as MAX_RECONCILE_DAYS above
     *  (see that constant's own doc comment — a slow request here freezes the
     *  WHOLE app for every user until it finishes), capped tighter: unlike
     *  reconcile-statuses' passes, which only ever live-check a narrow, cheaply
     *  pre-filtered candidate set, pancake:backfill-duplicated-logistics has no
     *  local signal to pre-filter on at all (nothing local distinguishes a
     *  warehouse-duplicated order from a real one) — it has to live-check EVERY
     *  order in the window. Confirmed live (2026-08-22): a single day alone
     *  turned up 215 matching orders shop-wide, and a 2-day run against
     *  production caused a real "upstream error" (Railway's proxy timing out
     *  while this single-process app was tied up) — cut from 2 to 1 the same
     *  day for that reason. Still capable of being slow on a high-volume day;
     *  this only bounds the worst case, it doesn't make it fast. */
    private const MAX_BACKFILL_DAYS = 1;

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
     * queue worker to push this into the background, and (confirmed via a
     * real incident, 2026-08-11) no request concurrency either — a slow
     * request here doesn't just make its own caller wait, it makes the WHOLE
     * APP return 499s for every other user until it finishes. Runs the days
     * closest to date_to (most likely to matter — e.g. right after editing a
     * TSA's keywords) rather than refusing outright on a wide range.
     * Reconciliation itself is ALSO now capped, to MAX_RECONCILE_DAYS — it
     * used to run across the full selected range unconditionally, which was
     * very likely the dominant cost in that same incident (reconcile-
     * statuses' own doc comment below already measured 9 days as borderline-
     * hang territory; Fix Now's picker defaults to 30).
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
        $rematchSummary  = null;
        $reconcileCapped = null;

        if (!empty($data['date_from'])) {
            $requestedFrom = $data['date_from'];
            $requestedTo   = $data['date_to'] ?? now('Asia/Manila')->toDateString();

            $rematchSummary = $this->rematchTsaAttribution($requestedFrom, $requestedTo);

            // Clamp reconcile-statuses to MAX_RECONCILE_DAYS closest to $requestedTo,
            // same "days nearest the end date matter most" reasoning as the re-match
            // cap above — this is what actually caused the 2026-08-11 incident
            // (see MAX_RECONCILE_DAYS's doc comment), not the re-match addition alone.
            $requestedSpanDays = Carbon::parse($requestedFrom)->diffInDays(Carbon::parse($requestedTo)) + 1;
            if ($requestedSpanDays > self::MAX_RECONCILE_DAYS) {
                $cappedFrom = Carbon::parse($requestedTo)->subDays(self::MAX_RECONCILE_DAYS - 1)->toDateString();
                $reconcileCapped = "reconciliation limited to the most recent " . self::MAX_RECONCILE_DAYS
                    . " of {$requestedSpanDays} selected days";
                $data['date_from'] = $cappedFrom;
            }
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
                . ($amountCorrected > 0 ? ", refreshed {$amountCorrected} upsell amount(s)." : '.')
                . ($reconcileCapped ? " ({$reconcileCapped})" : '');

        if ($rematchSummary) {
            $message .= ' ' . $rematchSummary;
        }

        ActivityLogger::log('sync-health.reconcile-statuses', null, $message);

        // AJAX path (explicit request, 2026-08-14): "Fix Now" used to be a
        // plain form POST — a full page reload for an action that can run
        // long on a wide range. The JS now submits this via fetch with
        // Accept: application/json instead, wants an in-page loading state
        // it can render/cancel client-side rather than a browser navigation.
        // A canceled fetch doesn't reach here at all (the connection drops
        // before this method returns) — this is a genuine "cancel just
        // stops watching", the reconciliation itself finishes server-side
        // regardless, matching Artisan::call() above having no cancellation
        // hook of its own. The redirect() fallback below still runs this
        // same logic for a plain (non-JS) form submit.
        if ($request->expectsJson()) {
            return response()->json(['success' => !$failed, 'message' => $message]);
        }

        return redirect()->route('sync-health')
            ->with($failed ? 'error' : 'success', $message);
    }

    /**
     * Manual trigger for pancake:backfill-duplicated-logistics (explicit
     * request, 2026-08-22) — one-off backfill for orders synced before
     * Order::isDuplicatedByLogistics() existed. Same date-range-picker +
     * synchronous Artisan::call() shape as reconcileStatuses() above, capped
     * to MAX_BACKFILL_DAYS instead of MAX_RECONCILE_DAYS (see that constant's
     * own doc comment for why this needs a tighter cap: no cheap local
     * pre-filter narrows the candidate set the way reconcile-statuses' own
     * passes do, so even a couple of days here is already a large live-check
     * batch on this single-process, no-concurrency server).
     */
    public function backfillDuplicatedLogistics(Request $request)
    {
        $data = $request->validate([
            'days'      => 'nullable|integer|min:1|max:365',
            'date_from' => 'nullable|date',
            'date_to'   => 'nullable|date|after_or_equal:date_from',
        ]);

        $capped = null;
        if (!empty($data['date_from'])) {
            $to   = $data['date_to'] ?? now('Asia/Manila')->toDateString();
            $span = Carbon::parse($data['date_from'])->diffInDays(Carbon::parse($to)) + 1;
            if ($span > self::MAX_BACKFILL_DAYS) {
                $data['date_from'] = Carbon::parse($to)->subDays(self::MAX_BACKFILL_DAYS - 1)->toDateString();
                $capped = "limited to the most recent " . self::MAX_BACKFILL_DAYS . " of {$span} selected days";
            }
        }

        $options = !empty($data['date_from'])
            ? ['--from' => $data['date_from'], '--to' => $data['date_to'] ?? now('Asia/Manila')->toDateString()]
            : ['--days' => min($data['days'] ?? self::MAX_BACKFILL_DAYS, self::MAX_BACKFILL_DAYS)];

        $exitCode = \Artisan::call('pancake:backfill-duplicated-logistics', $options);

        $failed    = $exitCode !== 0;
        $checked   = (int) Setting::get('duplicated_logistics_backfill_last_checked', 0);
        $corrected = (int) Setting::get('duplicated_logistics_backfill_last_corrected', 0);

        $message = $failed
            ? 'Backfill failed — check the Pancake API key/shop ID.'
            : "Checked {$checked} order(s) not yet flagged; corrected {$corrected} newly-found duplicate(s)."
                . ($capped ? " ({$capped})" : '');

        ActivityLogger::log('sync-health.backfill-duplicated-logistics', null, $message);

        if ($request->expectsJson()) {
            return response()->json(['success' => !$failed, 'message' => $message]);
        }

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
