<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\TsaShift;
use Illuminate\Http\Request;

/**
 * Per-TSA daily lead cap (2026-08-15 explicit request) — how many leads
 * round-robin may assign a TSA today before skipping them in favor of the
 * next TSA in rotation (see RoundRobinAssigner::next()). No request/approval
 * flow: an admin just raises the number here whenever a TSA asks for more.
 */
class RoundRobinSetupController extends Controller
{
    public function index()
    {
        $tsas = TsaShift::where('active', true)->orderBy('sort_order')->get()
            ->map(fn (TsaShift $tsa) => [
                'tsa'            => $tsa,
                'assigned_today' => $tsa->leadsAssignedToday(),
            ]);

        return view('calls.round-robin-setup', ['tsas' => $tsas]);
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
