<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guests are view-only, and only in the modules picked for them in User
 * Management (explicit request, 2026-11-04). Runs on every signed-in route,
 * so a new route is covered by default: anything outside /calls, /data and
 * /partners counts as Leads Reports. Reads the URL, not {dm}, because
 * DataManagementDataset drops that parameter first. Role gates still apply
 * on top (Cost Breakdown, Overall Performance, Archive stay admin-only).
 */
class EnsureGuestModuleAccess
{
    private const SEGMENT_MODULES = ['calls' => 'calls', 'data' => 'data', 'partners' => 'partners'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user?->role !== 'guest' || $request->routeIs('logout', 'messages.*')) {
            return $next($request);
        }

        abort_unless($request->isMethodSafe(), 403, 'Guest accounts are view-only.');

        if (!$request->routeIs('hub')) {
            $module = self::SEGMENT_MODULES[$request->segment(1)] ?? 'leads';
            abort_unless($user->canAccessModule($module), 403);
        }

        return $next($request);
    }
}
