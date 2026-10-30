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
        // Partners Data Management's separate database + /data|/partners
        // URL prefix — see App\Support\DataSet.
        \App\Support\DataSet::boot();

        // Scheduler container start (2026-10-30): a redeploy kills the old
        // container mid-run, leaving its withoutOverlapping() locks behind,
        // so the new one skipped pancake:sync-leads / sync-today for up to
        // 10 minutes ("Sync appears stale" after the 13:53 deploy). Nothing
        // can be running in a container that is only now starting, so its
        // schedule:work process (the parent, once — not each schedule:run
        // child) drops those locks before the first tick.
        if ($this->app->runningInConsole() && ($_SERVER['argv'][1] ?? null) === 'schedule:work') {
            self::clearScheduleMutexes();
        }

        // RequestMemo is never allowed to serve a value older than a write
        // made earlier in the same request — see its own doc comment.
        \Illuminate\Support\Facades\DB::listen(function ($query) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                \App\Support\RequestMemo::flush();
            }
        });

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

        // Sub-minute leads loop REMOVED (explicit request, 2026-09-29: "i
        // just want to reduce the consumption" — a real cost driver found
        // while investigating Railway's bill). This used to spawn a brand
        // new PHP process running pancake:sync-leads every 15 seconds,
        // forever, via a raw nohup'd shell loop — completely independent of
        // (and on top of) routes/console.php's own `Schedule::command(
        // SyncPancakeLeads::class)->everyMinute()`, which runs the EXACT
        // SAME command. That's ~5,700 extra full Laravel boots/day beyond
        // the scheduled once-a-minute sync, confirmed as the dominant cost
        // in the upbeat-light service (~62% of this app's entire Railway
        // bill) via `railway usage projects --json`'s own per-service
        // breakdown. Leads now surface within the scheduler's normal ~60s
        // cadence instead of ~15s — same latency every other scheduled sync
        // in this app already has, and the tradeoff explicitly accepted
        // over paying for near-real-time.
    }

    /** Drops every Schedule::withoutOverlapping() lock (database cache store). */
    public static function clearScheduleMutexes(): int
    {
        try {
            $table = config('cache.stores.database.lock_table') ?: 'cache_locks';

            return \Illuminate\Support\Facades\DB::connection(config('cache.stores.database.lock_connection') ?: config('cache.stores.database.connection'))
                ->table($table)->where('key', 'like', '%framework' . DIRECTORY_SEPARATOR . 'schedule-%')->delete();
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Could not clear schedule locks at scheduler start: ' . $e->getMessage());

            return 0;
        }
    }
}
