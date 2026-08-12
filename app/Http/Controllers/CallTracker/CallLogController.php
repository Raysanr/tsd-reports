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

        $rows = TsaShift::orderBy('sort_order')->get()->map(function (TsaShift $tsa) use ($events) {
            $mine = $events->where('tsa_id', $tsa->id);

            return [
                'tsa'              => $tsa,
                'total_calls'      => $mine->count(),
                'outgoing'         => $mine->where('direction', 'outgoing')->count(),
                'incoming'         => $mine->where('direction', 'incoming')->count(),
                'missed'           => $mine->where('direction', 'missed')->count(),
                'total_duration_s' => $mine->sum('duration_seconds'),
            ];
        })->filter(fn ($row) => $row['total_calls'] > 0)->values();

        return view('calls.call-log', [
            'rows'     => $rows,
            'events'   => $events->take(200), // recent-first raw list, capped same reasoning as other reports
            'dateFrom' => $dateFrom,
            'dateTo'   => $dateTo,
        ]);
    }
}
