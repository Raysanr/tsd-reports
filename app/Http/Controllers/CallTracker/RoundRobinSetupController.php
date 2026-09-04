<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\TsaShift;
use App\Support\Teams;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Per-TSA daily lead cap (2026-08-15 explicit request) — how many leads
 * round-robin may assign a TSA today before skipping them in favor of the
 * next TSA in rotation (see RoundRobinAssigner::next()). No request/approval
 * flow: an admin just raises the number here whenever a TSA asks for more.
 */
class RoundRobinSetupController extends Controller
{
    use PersistsCallTrackerFilters;

    public function index(Request $request)
    {
        // Remembered across a tab-away-and-back navigation (explicit
        // request, 2026-08-24) — see PersistsCallTrackerFilters's own doc
        // comment. Falls back to '' (empty string, not the 'all' string
        // convention some other pages use), matching this page's own
        // existing "falsy = no filter applied" default.
        $team = $this->rememberedFilter($request, 'leads-setup', 'team', '') ?? '';

        // Date picker (explicit request, 2026-08-24, "like the Dashboard" —
        // follow-up: upgraded from a single date to a real range, same
        // two-calendar picker Dashboard itself uses, not just its icon) —
        // review a past day/range's assignment volume, not just live today.
        // Purely a viewing feature: leadsAssignedBetween() is a read-only
        // historical count, never what round-robin itself enforces (that's
        // always leadsAssignedToday(), hardcoded to the real today
        // regardless of what's picked here — see that method's own doc
        // comment).
        $dateFromInput = $this->rememberedFilter($request, 'leads-setup', 'date_from');
        $dateToInput   = $this->rememberedFilter($request, 'leads-setup', 'date_to');
        $dateFrom = $dateFromInput ? Carbon::parse($dateFromInput, 'Asia/Manila')->startOfDay() : today('Asia/Manila');
        $dateTo   = $dateToInput ? Carbon::parse($dateToInput, 'Asia/Manila')->startOfDay() : $dateFrom->copy();

        $query = TsaShift::where('active', true)->orderBy('sort_order');
        if ($team) {
            $query->where('team', $team);
        }

        $tsas = $query->get()
            ->map(fn (TsaShift $tsa) => [
                'tsa'            => $tsa,
                'assigned_today' => $tsa->leadsAssignedBetween($dateFrom, $dateTo),
            ]);

        $data = [
            'tsas'         => $tsas,
            // Renamed-team-aware AND dated (explicit follow-up requests,
            // 2026-09-04: first "in the settings when rename team i want in
            // the call tracker is will be change too", then "backtrack the
            // data like yesterday it is sh naturals and eyecare") —
            // order_team (the array KEY here) stays the fixed, never-
            // editable string every filter/query already matches against;
            // the VALUE is now resolved against $dateFrom/$dateTo (this
            // page's own "Assigned — {range}" column is scoped to exactly
            // that picked range, per the table partial's own comment) via
            // Teams::nameForRange(), not Teams::config()'s bare "name as of
            // today" — so reviewing a past range still shows whatever this
            // team was actually called back then, not today's rename.
            'teams'        => collect(Teams::config())
                ->mapWithKeys(fn ($t, $slug) => [$t['order_team'] => Teams::nameForRange($slug, $dateFrom, $dateTo)])
                ->all(),
            'selectedTeam' => $team,
            'dateFrom'     => $dateFrom,
            'dateTo'       => $dateTo,
        ];

        // The team filter fetches this same URL in the background (see the
        // page's own script) and swaps in just the table with a fade instead
        // of a full page reload — same X-Table-Refresh convention the Leads
        // page already uses for its own polling.
        if ($request->header('X-Table-Refresh')) {
            return view('calls.round-robin-setup._table', $data);
        }

        return view('calls.round-robin-setup', $data);
    }

    public function update(Request $request, TsaShift $tsaShift)
    {
        $data = $request->validate([
            'daily_lead_cap' => ['nullable', 'integer', 'min:1'],
        ]);

        $tsaShift->update(['daily_lead_cap' => $data['daily_lead_cap'] ?? null]);

        return back()->with('success', "{$tsaShift->display_name}'s daily lead cap updated.");
    }
}
