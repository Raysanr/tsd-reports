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
use App\Console\Commands\BackfillLostUpsellTags;
use App\Console\Commands\LogoutAllTsasAtMidnight;
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

// Corrects local orders Pancake has since canceled/deleted, or whose upsell
// add-on was removed after the fact — the regular sync above can never catch
// either on its own: Pancake's list-orders endpoint excludes removed orders
// by default, so no date-scoped query (delta or full day, any date) ever
// sees one again once it's gone, and an upsell cancellation doesn't change
// which date-window the order falls into at all. Switched from daily to
// hourly (2026-08-25, explicit request) — daily (midnight-only) left same-day
// cancellations showing stale on the Dashboard/TSA Leaderboard for up to 24h,
// confirmed live: order #1356481 (Mariel's upsell) was canceled in Pancake
// ~8:53 PM but the day's one reconcile run had already happened before that,
// so it kept counting until the NEXT day's midnight run — manually re-running
// this exact command confirmed it corrects the stale flag immediately once
// run. Confirmed live this is small sequential JSON list calls, not file
// downloads, so even a wide window finishes in seconds — hourly is cheap.
//
// Tightened from hourly to every 15 minutes (explicit follow-up request,
// 2026-09-11: "i want to make it like immediately fix" — re: the new
// note-based cancelled-upsell check, Order::noteSaysCancelledUpsell(), which
// this same command also now evaluates on every run). A TSA typing
// "cancelled upsell" into Pancake's Note field is invisible to every OTHER
// signal this command checks (see that method's own doc comment — no tag/
// item/status change happens), so THIS job is the only thing that will ever
// catch it — worst case is now ~15 minutes instead of ~1 hour, not
// realistically improvable further without Pancake pushing webhooks on note
// edits, which it doesn't.
//
// withoutOverlapping(10), not 30 — the command itself still finishes in
// seconds (small sequential JSON list calls, not file downloads, per the
// 2026-08-21 comment this schedule inherited its own reasoning from), so 30
// minutes of overlap protection no longer makes sense against a 15-minute
// gap between runs; 10 still gives comfortable headroom.
Schedule::command(ReconcileOrderStatuses::class)->everyFifteenMinutes()->withoutOverlapping(10);

// Self-heals real upsell tags Pancake's own API silently drops on a brand-
// new tag attachment — a confirmed, external Pancake-side bug (root-caused
// 2026-09-18, still reproducing live 2026-09-21: 16 orders lost their
// upsell tag in a single day, most exhausting all 3 of addTagsToOrder()'s
// own retries with the tag never landing). Explicit follow-up requests,
// 2026-09-21 ("make it every time working", then "i want realtime working"
// — TSA feedback that the upsell tag "sometimes it is tagging and there
// are times that is not working"): this command already existed
// (calls:backfill-lost-upsell-tags, 2026-09-19) as a safe, idempotent,
// tested one-off — it only re-touches an order whose local
// is_upsell/is_returned_upsell/is_upsell_on_voided_order are ALL still
// false, so a re-run against an already-fixed order is a harmless no-op —
// but it only ever ran when someone remembered to trigger it by hand,
// leaving a real lost tag invisible to the Dashboard/Leaderboard/TSA
// Performance until then.
//
// everyFiveMinutes(), settled on after two follow-ups the same day
// ("make it every time working", then "i want realtime working" — tried
// at everyMinute() briefly before this — then "make it every 5 mins"):
// this only self-heals an ALREADY-failed write (Pancake's bug happens at
// the moment of the original write; no polling frequency prevents that
// first failure) — faster polling only shrinks how long a lost tag sits
// broken before something notices. 5 minutes is the settled middle ground
// between the 1-minute floor Laravel's scheduler can express at all (there
// is no ->everySeconds(), same ceiling SyncPancakeLeads' own doc comment
// documents) and the safer 15-minute default this codebase otherwise uses
// for "immediately fix" requests (see ReconcileOrderStatuses' own comment
// above) — every-minute piles live Pancake calls onto an API already
// confirmed under real strain (frequent 15s timeouts observed live) far
// more aggressively than the modest latency win justified.
//
// --days=1 (not the command's own 30-day default): only today's own lost
// tags matter for this recurring pass — anything older either already got
// caught by a previous run of this same schedule, or predates this fix
// entirely and is a one-off backfill's job (calls:backfill-lost-upsell-tags
// --days=N, run by hand), not this ongoing job's.
//
// withoutOverlapping(5): matches the job's own 5-minute interval, same
// "the mutex window should track the schedule's own cadence" reasoning as
// every other job here — a run that's merely a little slow still self-heals
// within one missed tick, while a run genuinely stuck clears for the next
// tick instead of blocking for several multiples of the job's own interval.
Schedule::command(BackfillLostUpsellTags::class, ['--days' => 1])->everyFiveMinutes()->withoutOverlapping(5);

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

// Wrap Up's auto-expiry (ExpireWrapUpStatuses, explicit request 2026-08-20)
// was removed 2026-09-01 (explicit follow-up): Wrap Up is a TSA's genuine
// after-call state until they pick something else themselves — a call
// ending is only ever detectable via the MacroDroid webhook, which isn't
// reliably real-time (phone-side automation getting killed by Android's
// battery management is the common failure mode), so silently bouncing
// someone out of Wrap Up on a timer was masking that unreliability rather
// than reflecting their real state.
//
// Reinstated 2026-09-24 (explicit request) at a fixed 1-minute timer,
// pointed at Ready to Call instead of Login — accepted tradeoff this time
// around despite the same webhook-reliability caveat above. See
// ExpireWrapUpStatuses' own doc comment for the full reasoning.
//
// everyMinute() + withoutOverlapping(10): same reasoning as every other
// every-minute job in this file — a 1-minute expiry window needs at least
// a 1-minute poll to ever fire on time, and the shared mutex-window
// convention (2026-08-21 fix) keeps a redeploy mid-run from wedging this
// one for 24h same as it would any other every-minute job here.
Schedule::command(ExpireWrapUpStatuses::class)->everyMinute()->withoutOverlapping(10);

// Force-logs-out every still-logged-in TSA at Manila midnight — explicit
// request, 2026-09-21, directly tied to a real same-day incident: Hannah
// forgot to log out the previous day, stayed in Login overnight absorbing
// unattended round-robin leads, and the manual cleanup (transferring her
// backlog to Marisol/Marsha) is what led to the "same lead visible to two
// TSAs" confusion reported that morning. See LogoutAllTsasAtMidnight's own
// doc comment for why this safely reuses TsaShift::applyStatusChange()
// (the exact same path a real topbar logout uses, including
// redistributing that TSA's uncalled leads) rather than a raw status write,
// and why STATUS_LOCKED TSAs are deliberately skipped.
//
// ->timezone('Asia/Manila'), explicit not implied — config('app.timezone')
// already IS 'Asia/Manila' so the scheduler's default already matches, but
// stating it here means this stays correct even if that config ever drifts,
// same "never trust an implicit default for something time-sensitive"
// reasoning PancakeReconcile's own comment above already applies to
// Carbon::now('Asia/Manila') explicitly, rather than bare now().
//
// withoutOverlapping(10): same 2026-08-21 fix as every other job here — a
// once-a-day job has the least natural self-healing of any schedule in this
// file (a stuck run means literally waiting until tomorrow for the next
// tick), so the stale-mutex protection matters here as much as anywhere.
Schedule::command(LogoutAllTsasAtMidnight::class)->dailyAt('00:00')->timezone('Asia/Manila')->withoutOverlapping(10);
