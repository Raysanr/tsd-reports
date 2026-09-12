<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CronController extends Controller
{
    /**
     * Render's free tier has no persistent cron process, so routes/console.php's
     * scheduled syncs would never fire on their own. An external free pinger
     * (e.g. cron-job.org) hits this URL every minute instead; each hit just runs
     * whatever is actually due right now (`schedule:run`), same as a real crontab
     * entry would — the interval/delta logic in routes/console.php is unchanged.
     *
     * Response body is deliberately a tiny fixed JSON ack (see the earlier fix
     * for why: cron-job.org auto-disables a job after enough "response too big"
     * failures, which silently killed this cron for over a day).
     *
     * schedule:run is launched as a DETACHED background process (exec ... &),
     * never in-process via Artisan::call(). This container serves every request
     * through a single php artisan serve worker (see Dockerfile — no php-fpm,
     * no worker pool, one request at a time). Running the sync in-process
     * blocked that one worker for its entire duration — a multi-page Pancake
     * API fetch can take several seconds — during which NOTHING else could be
     * served, Render's own health check included. Confirmed in production:
     * a health check timeout (5s) flagged the instance as down, coinciding
     * with a cron-triggered sync still running in-process. Backgrounding the
     * process lets this response return immediately, freeing the worker right
     * away; withoutOverlapping() on every scheduled command (routes/console.php)
     * already makes it safe for two backgrounded runs to occasionally overlap.
     * Output goes to its own log file instead of Laravel's logger, since the
     * backgrounded process is a separate PHP process with no HTTP request
     * context to log through.
     */
    public function run(Request $request): JsonResponse
    {
        $secret = config('services.cron.secret');

        if (!$secret || !hash_equals($secret, (string) $request->query('token'))) {
            abort(403);
        }

        $php     = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $logFile = escapeshellarg(storage_path('logs/cron-schedule-run.log'));
        exec("{$php} {$artisan} schedule:run >> {$logFile} 2>&1 &");

        // Fires pancake:sync-leads directly on EVERY hit, independent of
        // schedule:run's once-a-minute resolution (explicit request,
        // 2026-09-12: "make it like realtime... i want dont have to wait
        // minutes"). Laravel's own scheduler can't express anything below
        // one-minute intervals at all — there's no ->everySeconds() — so
        // the only way to assign leads faster than that ceiling is to stop
        // going through schedule:run for this one command and instead run
        // it every time this endpoint itself is hit. The real ceiling
        // becomes whatever interval the EXTERNAL pinger (e.g. cron-job.org)
        // is configured to call this endpoint at — configure that to
        // ~15-20s to get assignment down to roughly that latency instead of
        // up to 60s. Safe to fire this often: SyncPancakeLeads now has its
        // own self-contained overlap lock (pancake_sync_leads_running,
        // see that class's own doc comment) independent of
        // Schedule::withoutOverlapping(), which only ever guarded the
        // schedule:run path above and would do nothing for this one.
        // Backgrounded the same detached way as schedule:run above and for
        // the exact same reason (this container serves one request at a
        // time — see that call's own comment) — this response must still
        // return immediately regardless of how long a sync takes.
        $logFile2 = escapeshellarg(storage_path('logs/cron-sync-leads.log'));
        exec("{$php} {$artisan} pancake:sync-leads >> {$logFile2} 2>&1 &");

        return response()->json(['ok' => true]);
    }
}
