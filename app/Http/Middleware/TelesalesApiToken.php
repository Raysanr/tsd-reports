<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer-token gate for the external Telesales APIs. One token
 *  (TELESALES_API_TOKEN) opens everything; setting TELESALES_MC_TOKEN gives
 *  the payroll ('mc') endpoints their own token instead. Off (503) until the
 *  token is set, so it can never be open by accident. The 'mc' scope can
 *  also be limited to a list of caller IPs (TELESALES_MC_ALLOWED_IPS). */
class TelesalesApiToken
{
    public function handle(Request $request, Closure $next, string $scope = 'sales'): Response
    {
        $config = config('services.telesales_api', []);
        // The payroll endpoints use the sales token unless a separate
        // TELESALES_MC_TOKEN is set.
        $token = (string) ($config['token'] ?? '');
        if ($scope === 'mc' && filled($config['mc_token'] ?? null)) {
            $token = (string) $config['mc_token'];
        }
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
