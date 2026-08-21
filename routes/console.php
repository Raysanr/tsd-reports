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
use App\Console\Commands\ExpireWrapUpStatuses;
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
//
// withoutOverlapping(10): root-caused 2026-08-18 — a redeploy of the
// scheduler service (upbeat-light) killed a run mid-flight, and because this
// call had no explicit timeout, the mutex defaulted to 1440 minutes (24h).
// Every tick after that silently found the job "already running elsewhere"
// and skipped it — confirmed live via `railway logs --filter`: zero
// sync-today runs for over an hour, while the hourly jobs below (much less
// likely to get caught mid-run, and each one only misses ~1 run per stuck
// hour instead of ~1440) kept firing normally the whole time. 10 minutes
// matches SyncTodayOrders::RUNNING_STALE_MINUTES's own precedent — generous
// headroom over a real run's actual duration (seconds, up to ~80s for a
// catch-up sync per Sync Health data) so a future interrupted run self-heals
// in minutes instead of potentially a full day.
Schedule::command(SyncTodayOrders::class, ['--delta'])->cron("*/{$interval} * * * *")->withoutOverlapping(10);

// Full-day safety sweep: catches anything a delta window could ever miss (clock
// skew, API hiccups). This is the same complete sync that used to run EVERY
// interval — now it only needs to run 4x/hour. Upserts are idempotent, so an
// occasional overlap with a delta run is harmless.
//
// withoutOverlapping(10): same 2026-08-18 incident as the delta sync above, same
// fix — every job below got this on 2026-08-21 after confirming (via
// app(Schedule::class)->events()) that everything except the three every-minute
// jobs was still sitting on withoutOverlapping()'s bare 1440-minute (24h)
// default, so a scheduler-service redeploy interrupting any ONE of them could
// silently block it for up to a full day. 10 matches this exact command's own
// delta invocation and SyncTodayOrders::RUNNING_STALE_MINUTES precedent.
Schedule::command(SyncTodayOrders::class)->everyFifteenMinutes()->withoutOverlapping(10);

// Reconciliation: checks yesterday's completeness + TSA tag-keyword drift against
// Pancake's own data. Runs hourly rather than once a day at a fixed time — both
// checks are cheap (one page_size=1 orders call, one tags call, no pagination),
// and Carbon::now('Asia/Manila')->subDay() inside the command means "yesterday" is
// always correct regardless of what timezone the server's cron actually fires in.
//
// withoutOverlapping(10): see the full-day safety sweep's own comment above —
// same 2026-08-21 fix, same reasoning, applied uniformly.
Schedule::command(PancakeReconcile::class)->hourly()->withoutOverlapping(10);

// Explicit request (2026-08-13): fills in a missing TSA/team on a "separate
// parcel" order's siblings (same customer, same day) once the group is
// tagged — same hourly cadence as the reconciliation check above, since a
// tag can be added well after both orders already synced.
//
// withoutOverlapping(10): same 2026-08-21 fix as the jobs above.
Schedule::command(LinkSeparateParcelOrders::class)->hourly()->withoutOverlapping(10);

// Corrects local orders Pancake has since canceled/deleted — the regular sync
// above can never catch this on its own: Pancake's list-orders endpoint
// excludes removed orders by default, so no date-scoped query (delta or full
// day, any date) ever sees one again once it's gone. Daily is enough — this
// isn't time-critical the way today's own orders are, and confirmed live this
// is small sequential JSON list calls, not file downloads, so even a wide
// window finishes in seconds.
//
// withoutOverlapping(30): same 2026-08-21 fix as the jobs above — a daily job
// is less exposed to a redeploy landing mid-run than an every-minute one, but
// getting stuck on the bare 1440-minute default here is WORSE if it ever does
// happen: it would block this command's entire NEXT day's run too, not just
// a few missed ticks. 30 (vs. the 10 used above) gives real headroom over its
// "finishes in seconds" actual duration while staying far under 24h.
Schedule::command(ReconcileOrderStatuses::class)->daily()->withoutOverlapping(30);

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
//
// withoutOverlapping(10): same 2026-08-21 fix as the jobs above — same
// per-command mutex SyncTodayOrders' other schedules already use (10 matches
// RUNNING_STALE_MINUTES), and each of these three carries its own distinct
// --date argument, so they don't share a single mutex with each other or with
// the plain full-day sweep above.
foreach ([1, 2, 3] as $daysAgo) {
    Schedule::command(SyncTodayOrders::class, [
        '--date' => Carbon::now('Asia/Manila')->subDays($daysAgo)->toDateString(),
    ])->dailyAt(sprintf('01:%02d', $daysAgo * 5))->withoutOverlapping(10);
}

// Real call-duration data (synced from each team's Google Drive recordings folder)
// feeds the individual TSA page's OPT/AHT columns. Every 2 hours rather than more
// often — each run walks and re-downloads every matching recording for today fresh
// (no incremental cache), so a tighter interval would burn Drive API calls/bandwidth
// re-fetching files that haven't changed since the last run.
//
// withoutOverlapping(30): same 2026-08-21 fix as the jobs above — 30, not 10,
// since this one genuinely can take several minutes end to end (real Drive
// downloads across every TSA/team, per SettingsController::syncDriveNow()'s
// own doc comment), not the few-seconds duration the other jobs here have.
Schedule::command(SyncCallRecordings::class)->cron('0 */2 * * *')->withoutOverlapping(30);

// Ported from call-tracker (merged into one app 2026-08-12) — pulls
// unclaimed Pancake orders and round-robin assigns each to a TSA. Rides the
// same persistent `schedule:work` service as everything else above, no
// second scheduler/service needed.
//
// withoutOverlapping(10): same 2026-08-18 incident as the delta sync above —
// this is also an every-minute job, so it's just as exposed to a redeploy
// interrupting a run and leaving a 24h-default mutex stuck. Same fix, same
// reasoning.
Schedule::command(SyncPancakeLeads::class)->everyMinute()->withoutOverlapping(10);

// Monitor TSA's Wrap Up auto-expiry (explicit request, 2026-08-20) — every
// minute is the tightest this can run on the scheduler, which caps how
// precisely the configured ~60s duration can actually be honored (see the
// command's own doc comment). withoutOverlapping(2): a real run is a single
// cheap query + a handful of in-memory model updates, nowhere near a full
// minute, but every-minute jobs get this same short explicit timeout on
// principle now (2026-08-18 incident, see the delta sync above) rather than
// each one re-deciding it from scratch.
Schedule::command(ExpireWrapUpStatuses::class)->everyMinute()->withoutOverlapping(2);
