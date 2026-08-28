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
    /** date_from/date_to are the only remembered params whose default
     *  ("today") silently goes stale as soon as the calendar day rolls
     *  over — a team pill picked last week is still exactly as valid
     *  today, but a date range picked last week is not. Confirmed live,
     *  2026-08-28: Leads Setup's "Assigned" column comparing a many-day-old
     *  remembered range against each TSA's DAILY cap (329/75), reading like
     *  a blown cap on a day nobody had logged in yet — the range just never
     *  expired. */
    private const DATE_PARAMS = ['date_from', 'date_to'];

    protected function rememberedFilter(Request $request, string $pageKey, string $param, ?string $fallback = null): ?string
    {
        $sessionKey = "calltracker_filters.{$pageKey}.{$param}";
        $isDateParam = in_array($param, self::DATE_PARAMS, true);
        // A SIBLING key, not "{$sessionKey}.remembered_on" — session storage
        // resolves dots as nested-array paths (Arr::set/Arr::get), so an
        // extension of $sessionKey's own dotted path collided with $sessionKey
        // itself and silently corrupted the remembered value into an array.
        $rememberedOnKey = "calltracker_filters.{$pageKey}.{$param}_remembered_on";

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
            if ($isDateParam) {
                session([$rememberedOnKey => today('Asia/Manila')->toDateString()]);
            }
            return $value;
        }

        // A stored value with no matching remembered_on stamp counts as
        // stale too, not just a mismatched one — a session that had a date
        // filter saved before this staleness check existed would otherwise
        // never self-heal (no stamp to compare, so it'd pass silently
        // forever instead of just until the next actual day change).
        if ($isDateParam && session()->has($sessionKey)) {
            if (session($rememberedOnKey) !== today('Asia/Manila')->toDateString()) {
                session()->forget([$sessionKey, $rememberedOnKey]);
                return $fallback;
            }
        }

        return session($sessionKey, $fallback);
    }
}
