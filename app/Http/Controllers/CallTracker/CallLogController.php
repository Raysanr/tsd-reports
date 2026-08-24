<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift.
 * The load-reimbursement report — per-TSA call count/duration for a date
 * range, built from real call events their own phone's automation reports
 * (see CallEventController). This is the practical stand-in for "deduct
 * their SIM load": no telco exposes a way to read a personal prepaid
 * balance, so this is what an admin uses to work out how much load to pay
 * back each TSA instead.
 */
class CallLogController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->string('date_from', now('Asia/Manila')->format('Y-m-d'))->toString();
        $dateTo   = $request->string('date_to', $dateFrom)->toString();
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        $events = CallEvent::with(['tsa', 'lead'])
            ->whereBetween('occurred_at', [$from, $to])
            ->orderByDesc('occurred_at')
            ->get();

        // Gap-to-next-customer (explicit request, 2026-08-24) — replaces the
        // outgoing/incoming/missed/duration breakdown with "how much idle
        // time sat between this TSA's calls", the actual pace question this
        // page was for. occurred_at is stamped when Macro 1's "Call Ended"
        // trigger fires (see CallEventController::store()'s own default),
        // i.e. each call's END time — so a call's own START is occurred_at
        // minus its duration, and the gap before it is that start minus the
        // PREVIOUS call's own occurred_at (its end). Computed per TSA in
        // chronological order regardless of $events' own display order
        // (newest-first).
        $gapBeforeSeconds = []; // CallEvent id => seconds idle before this call started
        $tsaGapStats       = []; // tsa_id => ['avg_gap_seconds' => int, 'longest_gap_seconds' => int]

        foreach ($events->groupBy('tsa_id') as $tsaId => $tsaEvents) {
            $chronological = $tsaEvents->sortBy('occurred_at')->values();
            $gaps = [];

            for ($i = 1; $i < $chronological->count(); $i++) {
                $previousCallEndedAt = $chronological[$i - 1]->occurred_at;
                $thisCallStartedAt   = $chronological[$i]->occurred_at->copy()->subSeconds($chronological[$i]->duration_seconds ?? 0);
                $gapSeconds = max(0, $thisCallStartedAt->timestamp - $previousCallEndedAt->timestamp);

                $gapBeforeSeconds[$chronological[$i]->id] = $gapSeconds;
                $gaps[] = $gapSeconds;
            }

            if (!empty($gaps)) {
                $tsaGapStats[$tsaId] = [
                    'avg_gap_seconds'     => (int) round(array_sum($gaps) / count($gaps)),
                    'longest_gap_seconds' => max($gaps),
                ];
            }
        }

        $rows = TsaShift::orderBy('sort_order')->get()->map(function (TsaShift $tsa) use ($events, $tsaGapStats) {
            $mine = $events->where('tsa_id', $tsa->id);

            return [
                'tsa'                 => $tsa,
                'total_calls'         => $mine->count(),
                'avg_gap_seconds'     => $tsaGapStats[$tsa->id]['avg_gap_seconds'] ?? null,
                'longest_gap_seconds' => $tsaGapStats[$tsa->id]['longest_gap_seconds'] ?? null,
            ];
        })->filter(fn ($row) => $row['total_calls'] > 0)->values();

        return view('calls.call-log', [
            'rows'             => $rows,
            'events'           => $events->take(200), // recent-first raw list, capped same reasoning as other reports
            'gapBeforeSeconds' => $gapBeforeSeconds,
            'dateFrom'         => $dateFrom,
            'dateTo'           => $dateTo,
        ]);
    }
}
