<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\TsaShift;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift. */
class AnalyticsController extends Controller
{
    public function index(Request $request)
    {
        $dateFrom = $request->string('date_from', now('Asia/Manila')->format('Y-m-d'))->toString();
        $dateTo   = $request->string('date_to', $dateFrom)->toString();
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        // Scoped to when a lead entered a TSA's queue (assigned_at), not
        // when the underlying Pancake order was created — this answers "how
        // did TSAs perform on what they were actually given this window",
        // the same anchor Overdue already uses.
        $leads = Lead::with('tsa')->whereNotNull('tsa_id')
            ->whereBetween('assigned_at', [$from, $to])
            ->get();

        $rows = TsaShift::orderBy('sort_order')->get()->map(function (TsaShift $tsa) use ($leads) {
            $mine   = $leads->where('tsa_id', $tsa->id);
            $called = $mine->where('status', 'called');

            // Case-insensitive substring match, not an exact ->where() equals —
            // same convention LeadController::updateDisposition() already uses
            // for its own keyword checks: a real outcome can be several
            // comma-joined tags (e.g. "Confirmed, Call Back").
            $confirmed = $called->filter(fn (Lead $l) => stripos($l->disposition ?? '', 'confirmed') !== false)->count();
            $noAnswer  = $called->filter(fn (Lead $l) => stripos($l->disposition ?? '', 'not answering') !== false)->count();

            $responseMinutes = $called->filter(fn (Lead $l) => $l->assigned_at && $l->called_at)
                ->map(fn (Lead $l) => $l->assigned_at->diffInMinutes($l->called_at));

            return [
                'tsa'               => $tsa,
                'total'             => $mine->count(),
                'called'            => $called->count(),
                'confirmed'         => $confirmed,
                'no_answer'         => $noAnswer,
                'confirm_rate'      => $called->count() ? round($confirmed / $called->count() * 100, 1) : null,
                'no_answer_rate'    => $called->count() ? round($noAnswer / $called->count() * 100, 1) : null,
                'avg_response_mins' => $responseMinutes->isNotEmpty() ? round($responseMinutes->avg(), 1) : null,
            ];
        });

        // Chart payload — same $rows data, reshaped into plain arrays keyed by
        // TSA display name. Kept separate from $rows (which the table already
        // renders directly) rather than reshaping in the view, so the table
        // and charts can never disagree about which numbers they're showing —
        // both read from this one pass over $rows.
        $chartData = [
            'labels'          => $rows->pluck('tsa.display_name')->values(),
            'total'           => $rows->pluck('total')->values(),
            'called'          => $rows->pluck('called')->values(),
            'confirmRate'     => $rows->pluck('confirm_rate')->values(),
            'noAnswerRate'    => $rows->pluck('no_answer_rate')->values(),
            'avgResponseMins' => $rows->pluck('avg_response_mins')->values(),
            'hasAnyCalls'     => $rows->sum('called') > 0,
        ];

        return view('calls.analytics', [
            'rows'      => $rows,
            'dateFrom'  => $dateFrom,
            'dateTo'    => $dateTo,
            'chartData' => $chartData,
        ]);
    }
}
