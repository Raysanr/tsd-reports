<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\TsaShift;
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
    public function index(Request $request)
    {
        $team = $request->string('team')->toString();

        // Date picker (explicit request, 2026-08-24, "like the Dashboard") —
        // review a past day's assignment volume, not just live today. Purely
        // a viewing feature: leadsAssignedOn() is a read-only historical
        // count, never what round-robin itself enforces (that's always
        // leadsAssignedToday(), hardcoded to the real today regardless of
        // what's picked here — see that method's own doc comment).
        $dateInput = $request->string('date_from')->toString();
        $date      = $dateInput ? Carbon::parse($dateInput, 'Asia/Manila')->startOfDay() : today('Asia/Manila');

        $query = TsaShift::where('active', true)->orderBy('sort_order');
        if ($team) {
            $query->where('team', $team);
        }

        $tsas = $query->get()
            ->map(fn (TsaShift $tsa) => [
                'tsa'            => $tsa,
                'assigned_today' => $tsa->leadsAssignedOn($date),
            ]);

        $data = [
            'tsas'         => $tsas,
            'teams'        => collect(config('teams'))->pluck('order_team')->all(),
            'selectedTeam' => $team,
            'date'         => $date,
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
