<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token gate for the external Telesales API. Off (503) until
 *  TELESALES_API_TOKEN is set, so it can never be open by accident. */
class TelesalesApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = (string) config('services.telesales_api.token');
        if ($token === '') {
            return response()->json(['error' => 'API not configured'], 503);
        }
        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}
