<?php

namespace App\Http\Controllers;

use App\Support\InsightsGenerator;
use Illuminate\Support\Carbon;

/**
 * Explicit request, 2026-08-26: an admin-only "Insights" page of rule-based
 * cards — see InsightsGenerator's own doc comment for the accuracy
 * reasoning (every number reuses an existing page's own formula, nothing
 * new invented here). Route itself is gated role:super_admin,admin in
 * routes/web.php, same group Settings/User Management already sit in — this
 * controller doesn't re-check it, matching every other controller behind
 * that same middleware group.
 */
class InsightsController extends Controller
{
    public function index()
    {
        // Explicit request, 2026-08-27: "supervisors report today but
        // yesterday data" — they often catch up on the previous day's
        // numbers, so this page needs to be able to show any past day's
        // cards, not just today's. The date-picker partial (mode='single',
        // submit='navigate') always sends BOTH date_from/date_to regardless
        // of mode (see partials/date-picker.blade.php's applyBtn handler) —
        // same param names RtsReportController's own single-day picker
        // reads, just using date_from here since date_to is identical in
        // single mode.
        //
        // Session fallback added 2026-08-27: unlike team/view below, this
        // used to have NONE at all — navigating away to a different page
        // (a bare sidebar link, no date_from in the URL) and back always
        // silently reset the date to today, even though team/view both
        // correctly remembered their last value. Same session-persistence
        // shape as those two now.
        $date = request('date_from')
            ? Carbon::parse(request('date_from'))->startOfDay()
            : Carbon::parse(session('filters.insights.date', today()->toDateString()))->startOfDay();

        // A future date has no data to show and breaks the "vs yesterday"/
        // "vs last week" comparisons' own assumptions — clamp instead of
        // letting a hand-edited URL (or a stale session value) past the
        // calendar's own maxDate='today' produce a confusing empty page.
        if ($date->isFuture()) {
            $date = today();
        }

        session(['filters.insights.date' => $date->toDateString()]);

        // Team filter — explicit request, 2026-08-27: "like in the
        // dashboard." Same ALL/per-team shape, key names, and session
        // persistence as DashboardController::index()'s own $selectedTeam,
        // so switching teams here behaves exactly like it does everywhere
        // else in the app.
        $teamsConfig = config('teams', []);
        $teams = ['all' => 'ALL'] + array_map(fn ($t) => $t['name'], $teamsConfig);
        $selectedTeam = request('team', session('filters.insights.team', 'all'));
        if ($selectedTeam !== 'all' && !array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'all';
        }
        session(['filters.insights.team' => $selectedTeam]);

        // Insights / Action Plan view toggle — explicit request, 2026-08-27:
        // "action plan is smart report too based of the accurate data." Same
        // underlying cards either way; the Action Plan view (insights.blade.
        // php) just renders each card's 'action' instead of its 'message',
        // filtered to cards that actually have one.
        $view = request('view', session('filters.insights.view', 'insights'));
        if (!in_array($view, ['insights', 'action-plan'], true)) {
            $view = 'insights';
        }
        session(['filters.insights.view' => $view]);

        $cards = (new InsightsGenerator())->generate($date, $selectedTeam === 'all' ? null : $selectedTeam);

        return view('insights', [
            'cards' => $cards, 'date' => $date,
            'teams' => $teams, 'selectedTeam' => $selectedTeam,
            'view' => $view,
        ]);
    }
}
