<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('role:super_admin,admin') — 403s unless the signed-in
 * user's role is in the allow-list. Assumes 'auth' (and normally 'active')
 * already ran first, so $request->user() is present.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array($request->user()->role, $roles, true), 403);

        return $next($request);
    }
}
