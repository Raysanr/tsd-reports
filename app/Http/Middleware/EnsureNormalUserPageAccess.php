<?php

namespace App\Http\Middleware;

use App\Support\NormalUserPages;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Settings → Page Access: a Normal user opening a page an admin turned
 *  off goes back to the Hub (see NormalUserPages). */
class EnsureNormalUserPageAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (NormalUserPages::blockedPage($request) === null) {
            return $next($request);
        }

        abort_if($request->expectsJson() || !$request->isMethod('GET'), 403, 'This page is turned off for your account.');

        return redirect()->route('hub')->with('error', 'That page is turned off for your account.');
    }
}
