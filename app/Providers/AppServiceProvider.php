<?php

namespace App\Providers;

use Illuminate\Auth\Middleware\RedirectIfAuthenticated;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Render (like most PaaS hosts) terminates TLS at its edge and forwards
        // plain HTTP to the container, so Laravel's request-scheme detection
        // sees "http" and generates http:// asset/URL links even though the
        // public page is https:// — browsers silently block that as mixed
        // content, which is why CSS/JS never loaded despite the page itself
        // rendering fine. Forcing https in production sidesteps the scheme
        // detection entirely rather than trying to trust proxy headers.
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Login brute-force protection — POST /login had no throttling at all
        // (confirmed: no throttle middleware, no named limiter anywhere in the
        // app), so any known/guessed email could be hammered with unlimited
        // password attempts. Keyed by email+IP together, not just IP alone —
        // an office/VPN full of legitimate coworkers sharing one outbound IP
        // shouldn't all get locked out by one person's typos or one attacker's
        // guesses against a DIFFERENT account. See routes/web.php's
        // throttle:login on the login POST route, and resources/views/
        // errors/429.blade.php for the branded page a lockout shows.
        RateLimiter::for('login', function (Request $request) {
            $key = strtolower((string) $request->input('email')) . '|' . $request->ip();

            return Limit::perMinute(5)->by($key);
        });

        // Explicit request (2026-08-12): "sometimes logging in redirects to
        // the dashboard instead of the Hub." Root cause: an already-
        // authenticated session hitting GET /login (bookmark, restored tab,
        // "Keep me signed in" never expiring) never reaches AuthController
        // at all — the 'guest' route middleware's stock RedirectIfAuthenticated
        // intercepts first, and its own default destination is whichever of
        // 'dashboard'/'home' has a registered route (see that class's
        // defaultRedirectUri()) — this app has a 'dashboard' route, so that's
        // where it went. AuthController's own "always land on Hub" logic
        // (see its login()/handleGoogleCallback() doc comments) only runs on
        // an actual fresh login and never had a chance to apply here.
        // redirectUsing() is Laravel's own supported
        // override point for exactly this — same destination the real login
        // flows already use, so "already signed in" and "just signed in"
        // now agree.
        RedirectIfAuthenticated::redirectUsing(fn () => route('hub'));

        // Sub-minute leads loop (explicit request, 2026-09-12: "make it like
        // realtime displaying in the leads when there's new in the POS").
        // This used to live in docker/entrypoint.sh's schedule:work branch,
        // but Railway's upbeat-light service runs `php artisan schedule:work`
        // directly as its configured start command — confirmed via
        // `railway ssh -- cat /proc/1/cmdline`, which showed schedule:work
        // as PID 1 itself, not a child of entrypoint.sh — so that script
        // never executes at all for this service and the loop never started
        // (storage/logs/leads-loop.log never got created; sync-leads kept
        // firing at the old 60s cadence). Hooking it here instead runs
        // inside the same schedule:work PHP process regardless of how
        // Railway invokes it, so it can't be silently bypassed the same way
        // again. Only ever fires once: boot() runs a single time at process
        // startup for a long-running command like schedule:work, not once
        // per scheduler tick.
        //
        // Must be nohup'd: exec() forks a throwaway `sh -c` to launch the
        // background subshell, then that throwaway shell exits immediately
        // once the job is backgrounded. Without nohup, the kernel sent
        // SIGHUP to the loop's process group when its parent shell died —
        // confirmed live: the loop ran exactly one iteration then went
        // silent (leads-loop.log stopped growing entirely, never crashed,
        // no second line ever appeared) since SIGHUP's default disposition
        // kills the process, and it always happened to survive just long
        // enough to finish whichever sync was already in flight.
        if ($this->app->runningInConsole() && in_array('schedule:work', $_SERVER['argv'] ?? [], true)) {
            $interval = (int) (env('LEADS_LOOP_INTERVAL', 15));
            $logFile  = storage_path('logs/leads-loop.log');
            $cmd = sprintf(
                'nohup sh -c \'while true; do %s %s pancake:sync-leads >> %s 2>&1; sleep %d; done\' > /dev/null 2>&1 &',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                escapeshellarg($logFile),
                max(5, $interval)
            );
            exec($cmd);
        }
    }
}
