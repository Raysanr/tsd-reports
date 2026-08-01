<?php

namespace App\Providers;

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
    }
}
