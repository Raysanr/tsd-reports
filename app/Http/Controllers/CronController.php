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

        return response()->json(['ok' => true]);
    }
}
