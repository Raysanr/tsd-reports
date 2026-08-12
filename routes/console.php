<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Console\Commands\SyncTodayOrders;
use App\Console\Commands\PancakeReconcile;
use App\Console\Commands\ReconcileOrderStatuses;
use App\Console\Commands\SyncCallRecordings;
use App\Console\Commands\SyncPancakeLeads;
use App\Console\Commands\LinkSeparateParcelOrders;
use App\Models\Setting;
use Illuminate\Support\Carbon;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Auto-sync today's Pancake orders on the interval configured in Settings
 * (minutes; defaults to 2). This makes re-tagged/backfilled orders (e.g. a
 * TSA tagging an order as upsell after the fact) show up on their own
 * without a manual "Sync" click.
 * Run in dev:  php artisan schedule:work
 * Run in prod: add to crontab → * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
 */
$interval = max(1, min(60, (int) Setting::get('sync_interval', 2)));

// Delta run: only orders updated since the last successful run (5-min overlap) —
// a fraction of the data the old full-day run pulled every interval.
Schedule::command(SyncTodayOrders::class, ['--delta'])->cron("*/{$interval} * * * *")->withoutOverlapping();

// Full-day safety sweep: catches anything a delta window could ever miss (clock
// skew, API hiccups). This is the same complete sync that used to run EVERY
// interval — now it only needs to run 4x/hour. Upserts are idempotent, so an
// occasional overlap with a delta run is harmless.
Schedule::command(SyncTodayOrders::class)->everyFifteenMinutes()->withoutOverlapping();

// Reconciliation: checks yesterday's completeness + TSA tag-keyword drift against
// Pancake's own data. Runs hourly rather than once a day at a fixed time — both
// checks are cheap (one page_size=1 orders call, one tags call, no pagination),
// and Carbon::now('Asia/Manila')->subDay() inside the command means "yesterday" is
// always correct regardless of what timezone the server's cron actually fires in.
Schedule::command(PancakeReconcile::class)->hourly()->withoutOverlapping();

// Explicit request (2026-08-13): fills in a missing TSA/team on a "separate
// parcel" order's siblings (same customer, same day) once the group is
// tagged — same hourly cadence as the reconciliation check above, since a
// tag can be added well after both orders already synced.
Schedule::command(LinkSeparateParcelOrders::class)->hourly()->withoutOverlapping();

// Corrects local orders Pancake has since canceled/deleted — the regular sync
// above can never catch this on its own: Pancake's list-orders endpoint
// excludes removed orders by default, so no date-scoped query (delta or full
// day, any date) ever sees one again once it's gone. Daily is enough — this
// isn't time-critical the way today's own orders are, and confirmed live this
// is small sequential JSON list calls, not file downloads, so even a wide
// window finishes in seconds.
Schedule::command(ReconcileOrderStatuses::class)->daily()->withoutOverlapping();

// Full re-sync of the last few days, once nightly — a safety net against rare
// completeness gaps the continuous "today" sync can miss right at the midnight
// boundary. Confirmed live: a Scar Cream order updated at 11:21 PM stayed
// completely unsynced for 2 days until a manual re-sync of that date caught it
// — status wasn't the issue (it was a normal, non-hidden status), the order
// just never made it into the sync's window before the day rolled over.
// PancakeReconcile's own completeness check only flags a LARGE shortfall (90%
// threshold) — one missing order out of hundreds never trips it, so this
// doesn't just report the gap, it actively re-fetches and upserts (idempotent)
// each of the last 3 days to actually close it. Staggered a few minutes apart
// (not truly time-critical) so three full-day resyncs don't all start at once.
foreach ([1, 2, 3] as $daysAgo) {
    Schedule::command(SyncTodayOrders::class, [
        '--date' => Carbon::now('Asia/Manila')->subDays($daysAgo)->toDateString(),
    ])->dailyAt(sprintf('01:%02d', $daysAgo * 5))->withoutOverlapping();
}

// Real call-duration data (synced from each team's Google Drive recordings folder)
// feeds the individual TSA page's OPT/AHT columns. Every 2 hours rather than more
// often — each run walks and re-downloads every matching recording for today fresh
// (no incremental cache), so a tighter interval would burn Drive API calls/bandwidth
// re-fetching files that haven't changed since the last run.
Schedule::command(SyncCallRecordings::class)->cron('0 */2 * * *')->withoutOverlapping();

// Ported from call-tracker (merged into one app 2026-08-12) — pulls
// unclaimed Pancake orders and round-robin assigns each to a TSA. Rides the
// same persistent `schedule:work` service as everything else above, no
// second scheduler/service needed.
Schedule::command(SyncPancakeLeads::class)->everyMinute()->withoutOverlapping();
