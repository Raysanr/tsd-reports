<?php

namespace App\Providers;

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
    }
}
