<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Artisan;

class CronController extends Controller
{
    /**
     * Render's free tier has no persistent cron process, so routes/console.php's
     * scheduled syncs would never fire on their own. An external free pinger
     * (e.g. cron-job.org) hits this URL every minute instead; each hit just runs
     * whatever is actually due right now (`schedule:run`), same as a real crontab
     * entry would — the interval/delta logic in routes/console.php is unchanged.
     */
    public function run(Request $request): Response
    {
        $secret = config('services.cron.secret');

        if (!$secret || !hash_equals($secret, (string) $request->query('token'))) {
            abort(403);
        }

        Artisan::call('schedule:run');

        return response(Artisan::output(), 200)->header('Content-Type', 'text/plain');
    }
}
