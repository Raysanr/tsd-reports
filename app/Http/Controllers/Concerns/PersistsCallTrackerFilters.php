<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Explicit request (2026-08-24): sidebar navigation between Call Tracker
 * tabs (see layouts/calls.blade.php) is a set of plain GET links with no
 * query params, so every filter page previously reset to its own hard
 * default (today/all) on every visit — nothing remembered the last-picked
 * value across a genuine page navigation (unlike a same-page date-picker
 * Apply, which already carries the query string forward, or the Leads
 * group's own localStorage restore). Keyed per $pageKey.$param in session
 * so each Call Tracker page remembers its OWN last filter independently —
 * picking a date range on Monitor TSA has no effect on what Dashboard
 * shows, matching how each page's default already worked before this,
 * just remembered instead of reset every time.
 */
trait PersistsCallTrackerFilters
{
    protected function rememberedFilter(Request $request, string $pageKey, string $param, ?string $fallback = null): ?string
    {
        $sessionKey = "calltracker_filters.{$pageKey}.{$param}";

        // has(), not filled() — some pages' "clear this filter" link
        // explicitly sends an EMPTY value (e.g. TSA Management/Leads
        // Setup's "All teams" pill, team=&...) rather than a non-empty
        // sentinel like Dashboard/Monitor/Call Log's team=all. Using
        // filled() would silently ignore that explicit clear and keep
        // showing whatever team was remembered from before.
        if ($request->has($param)) {
            $value = $request->input($param);
            $value = $value !== '' ? $value : $fallback;
            session([$sessionKey => $value]);
            return $value;
        }

        return session($sessionKey, $fallback);
    }
}
