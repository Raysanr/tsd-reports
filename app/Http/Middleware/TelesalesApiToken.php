<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token gate for the external Telesales APIs. $scope picks the
 *  token: 'sales' (TELESALES_API_TOKEN) or 'mc' (TELESALES_MC_TOKEN), so
 *  the payroll endpoints never accept the sales token. Off (503) until the
 *  token is set, so it can never be open by accident. The 'mc' scope can
 *  also be limited to a list of caller IPs (TELESALES_MC_ALLOWED_IPS). */
class TelesalesApiToken
{
    public function handle(Request $request, Closure $next, string $scope = 'sales'): Response
    {
        $config = config('services.telesales_api', []);
        $token = (string) ($scope === 'mc' ? ($config['mc_token'] ?? '') : ($config['token'] ?? ''));
        if ($token === '') {
            return response()->json(['error' => 'API not configured'], 503);
        }
        if (! hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $allowedIps = array_filter(array_map('trim', explode(',', (string) ($config['mc_allowed_ips'] ?? ''))));
        if ($scope === 'mc' && $allowedIps !== [] && ! in_array($request->ip(), $allowedIps, true)) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
