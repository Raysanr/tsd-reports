<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Render terminates TLS at its edge and forwards to this container over
        // plain HTTP (see the Dockerfile's comment on the single-container deploy)
        // — without this, Laravel never learns the original request was HTTPS.
        // '*' trusts whatever immediate proxy delivered the request, standard for
        // PaaS platforms (Render/Heroku/Railway) where that hop is always the
        // platform's own edge, never arbitrary internet traffic.
        $middleware->trustProxies(at: '*');

        $middleware->alias([
            'active' => \App\Http\Middleware\EnsureUserIsActive::class,
            'role'   => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
