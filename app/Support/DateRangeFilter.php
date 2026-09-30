<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Shared "remember the last date range picked on this page" logic for
 * every Data Management report page (DSPPR, Summary Sales Report, Expected
 * Income) — explicit request, 2026-09-30: "why is it when i am clicking
 * other page and then go back why is it resetting to the sep 1 to 29 i
 * want to make it it is first like today only when first open and depend
 * of the user if they will date pick wide range."
 *
 * Each of these pages is a plain server-rendered GET form with no other
 * client-side state — navigating away via a sidebar link and back is a
 * completely fresh request with no query string, so the only place a
 * "remembered" range can live between visits is the session. Resolution
 * order per request:
 *   1. date_from/date_to on the URL itself (the user just submitted the
 *      filter form, or followed a link/bookmark with an explicit range) —
 *      always wins, and gets saved to session for next time.
 *   2. Whatever range was last saved to session for this SAME page (keyed
 *      by $sessionKey, so each report page remembers its own range
 *      independently of the others).
 *   3. "Today only" — the true first-visit default (a fresh session, or
 *      this page never visited before this session), same across all
 *      three pages per explicit decision, 2026-09-30 (previously DSPPR/
 *      Summary Sales Report defaulted to "this week" and Expected Income
 *      to "this month" — a real behavior change, not just this bug fix).
 */
class DateRangeFilter
{
    /** Returns ['from' => 'Y-m-d', 'to' => 'Y-m-d']. $sessionKey should be
     *  unique per report page (e.g. 'dsppr', 'expected-income',
     *  'tsa-sales') so each page's own last-picked range persists
     *  independently. */
    public static function resolve(Request $request, string $sessionKey): array
    {
        $from = $request->input('date_from');
        $to = $request->input('date_to');

        if ($from && $to) {
            session(["date_range.{$sessionKey}" => ['from' => $from, 'to' => $to]]);
            return ['from' => $from, 'to' => $to];
        }

        $remembered = session("date_range.{$sessionKey}");
        if ($remembered) {
            return $remembered;
        }

        $today = today()->toDateString();
        return ['from' => $today, 'to' => $today];
    }
}
