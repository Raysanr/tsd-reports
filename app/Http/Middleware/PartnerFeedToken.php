<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Partner apps calling INTO this app (2026-11-27, Daily Target): same shared
 *  secret this app already uses to read their /api/tsd-feed (PARTNER_FEED_SECRET
 *  here, TSD_MAIN_FEED_SECRET on the partner side). */
class PartnerFeedToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('partners.secret', '');
        if ($secret === '') {
            return response()->json(['error' => 'API not configured'], 503);
        }
        if (! hash_equals($secret, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
