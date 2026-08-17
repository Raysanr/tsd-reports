<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs on every authenticated route (same group as EnsureUserIsActive) —
 * stamps last_seen_at so User Management can show who's currently online
 * (User::isOnline(), a recent-enough last_seen_at) instead of a manually
 * toggled status nobody remembers to update.
 *
 * Throttled to once a minute per user rather than every single request —
 * the sidebar's own 30s notification poll alone would otherwise double the
 * write traffic on this column for no real gain in accuracy.
 */
class TrackLastSeen
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && (!$user->last_seen_at || $user->last_seen_at->lt(now()->subMinute()))) {
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
