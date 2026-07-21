<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Throwable;

class CronController extends Controller
{
    /**
     * Render's free tier has no persistent cron process, so routes/console.php's
     * scheduled syncs would never fire on their own. An external free pinger
     * (e.g. cron-job.org) hits this URL every minute instead; each hit just runs
     * whatever is actually due right now (`schedule:run`), same as a real crontab
     * entry would — the interval/delta logic in routes/console.php is unchanged.
     *
     * Response body is deliberately a tiny fixed JSON ack, never Artisan::output()
     * or an exception's own body — cron-job.org auto-disables a job after enough
     * "response too big" failures (confirmed in production: 26 failures silently
     * killed this cron for over a day, during which no sync ran at all). Whatever
     * schedule:run printed or threw is logged instead, where it's actually visible.
     */
    public function run(Request $request): JsonResponse
    {
        $secret = config('services.cron.secret');

        if (!$secret || !hash_equals($secret, (string) $request->query('token'))) {
            abort(403);
        }

        try {
            Artisan::call('schedule:run');
            Log::info('cron.run: schedule:run completed', ['output' => Artisan::output()]);
        } catch (Throwable $e) {
            Log::error('cron.run: schedule:run threw', ['message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }
}
