<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\Lead;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift. */
class AnalyticsController extends Controller
{
    // Fixed shift length (minutes/working day) used as "Total Logged-in Hours"
    // in the Unproductive Time formula — a flat per-TSA constant, not derived
    // from TsaShift::shift_start/shift_end (those currently run ~540min/9hr
    // for most TSAs, a different number than the 440 actually used here).
    private const SHIFT_MINUTES_PER_DAY = 440;

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

        // AHT (Average Handle Time) — real per-call durations from
        // CallEvent.duration_seconds (MacroDroid's own call-log report, see
        // the auto-upload setup on Call Rotation), not an estimate. Missing
        // durations (e.g. a call event logged before duration_seconds
        // existed, or MacroDroid failing to report one) are excluded rather
        // than treated as 0 — a handle time of "zero seconds" would quietly
        // drag every average down. Missed calls are excluded outright: a
        // call nobody answered has no handle time to average in at all.
        $callEvents = CallEvent::whereIn('tsa_id', TsaShift::pluck('id'))
            ->where('direction', '!=', 'missed')
            ->whereNotNull('duration_seconds')
            ->whereBetween('occurred_at', [$from, $to])
            ->get();

        $rows = TsaShift::with('restDays')->orderBy('sort_order')->get()->map(function (TsaShift $tsa) use ($leads, $callEvents, $from, $to) {
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

            $myCallEvents = $callEvents->where('tsa_id', $tsa->id);
            $ahtSeconds   = $myCallEvents->isNotEmpty() ? (int) round($myCallEvents->avg('duration_seconds')) : null;
            $thtSeconds   = (int) $myCallEvents->sum('duration_seconds');

            // Working days in range = calendar days minus this TSA's own rest
            // days (TsaShift::isOffOn(), same rule round-robin assignment
            // already respects) — a TSA out for 1 of 5 days in range should
            // only be charged 4 days' worth of logged-in time, not 5.
            $workingDays = 0;
            for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
                if (!$tsa->isOffOn($day)) {
                    $workingDays++;
                }
            }

            $loggedInMinutes  = $workingDays * self::SHIFT_MINUTES_PER_DAY;
            $unproductiveMins = max(0, $loggedInMinutes - ($thtSeconds / 60));

            return [
                'tsa'                 => $tsa,
                'total'               => $mine->count(),
                'called'              => $called->count(),
                'confirmed'           => $confirmed,
                'no_answer'           => $noAnswer,
                'confirm_rate'        => $called->count() ? round($confirmed / $called->count() * 100, 1) : null,
                'no_answer_rate'      => $called->count() ? round($noAnswer / $called->count() * 100, 1) : null,
                'avg_response_mins'   => $responseMinutes->isNotEmpty() ? round($responseMinutes->avg(), 1) : null,
                'aht_seconds'         => $ahtSeconds,
                'aht_call_count'      => $myCallEvents->count(),
                'tht_seconds'         => $thtSeconds,
                'working_days'        => $workingDays,
                'logged_in_minutes'   => $loggedInMinutes,
                'unproductive_minutes'=> $unproductiveMins,
                'unproductive_ratio'  => $loggedInMinutes > 0 ? round($unproductiveMins / $loggedInMinutes * 100, 1) : null,
            ];
        });

        // Status Time — team-wide total time spent in each status during the
        // range (explicit request, 2026-08-19), walked from TsaStatusLog: for
        // each TSA, find whatever status was active AT $from (the most recent
        // log at or before it, or their current status if they have no
        // history yet), then walk every log within the range attributing the
        // time BETWEEN consecutive changes to whichever status was active
        // during that stretch, clipped to [$from, $to]. 'Others' folds in
        // Break/Logout/Lock — only Login/Coaching/DNA Huddle/Huddle get their
        // own bucket, matching what the KPI cards and this section actually
        // surface.
        $statusSeconds  = array_fill_keys(array_keys(TsaShift::STATUSES), 0);
        $statusRangeEnd = $to->isFuture() ? now() : $to;

        foreach ($rows->pluck('tsa') as $statusTsa) {
            $priorLog     = TsaStatusLog::where('tsa_id', $statusTsa->id)
                ->where('created_at', '<=', $from)
                ->orderByDesc('created_at')
                ->first();
            $cursorStatus = $priorLog->status ?? $statusTsa->status;
            $cursor       = $from->copy();

            $logsInRange = TsaStatusLog::where('tsa_id', $statusTsa->id)
                ->whereBetween('created_at', [$from, $to])
                ->orderBy('created_at')
                ->get();

            foreach ($logsInRange as $log) {
                $statusSeconds[$cursorStatus] = ($statusSeconds[$cursorStatus] ?? 0) + $cursor->diffInSeconds($log->created_at);
                $cursorStatus = $log->status;
                $cursor       = $log->created_at;
            }
            if ($cursor->lt($statusRangeEnd)) {
                $statusSeconds[$cursorStatus] = ($statusSeconds[$cursorStatus] ?? 0) + $cursor->diffInSeconds($statusRangeEnd);
            }
        }

        $othersSeconds = ($statusSeconds[TsaShift::STATUS_BREAK] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_LOGOUT] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_LOCKED] ?? 0);

        $formatHm = fn (int $totalSeconds): string => intdiv(intdiv($totalSeconds, 60), 60) . 'h ' . (intdiv($totalSeconds, 60) % 60) . 'm';

        $statusTime = [
            'coaching'  => $formatHm($statusSeconds[TsaShift::STATUS_COACHING] ?? 0),
            'dnaHuddle' => $formatHm($statusSeconds[TsaShift::STATUS_DNA_HUDDLE] ?? 0),
            'huddle'    => $formatHm($statusSeconds[TsaShift::STATUS_HUDDLE] ?? 0),
            'others'    => $formatHm($othersSeconds),
        ];
        $loginTimeDisplay = $formatHm($statusSeconds[TsaShift::STATUS_LOGIN] ?? 0);

        // Aggregate KPI cards (explicit request, 2026-08-19) — Total Leads/
        // Catered sum the same $rows every table row already shows (never a
        // separate source of truth); AHT is the true pooled average across
        // every logged call in range (not an average-of-per-TSA-averages,
        // same reasoning Dashboard's own team-wide AHT card uses); Unproductive
        // is the average of each TSA's own unproductive_minutes above.
        $totalLeadsSum   = $rows->sum('total');
        $totalCateredSum = $rows->sum('called');

        $overallAhtSeconds = $callEvents->isNotEmpty() ? (int) round($callEvents->avg('duration_seconds')) : null;
        $overallAhtDisplay = $overallAhtSeconds !== null
            ? sprintf('%dm %ds', intdiv($overallAhtSeconds, 60), $overallAhtSeconds % 60)
            : '—';

        $overallUnproductiveDisplay = $rows->isNotEmpty()
            ? $formatHm((int) round($rows->avg('unproductive_minutes') * 60))
            : '0h 0m';

        // Team-wide AHT trend, one point per calendar day in range (Asia/Manila,
        // matching every other date boundary in this controller) — a per-TSA
        // trend would be unreadable with more than 2-3 TSAs on one line chart,
        // so this answers "is handle time getting better or worse overall",
        // while the per-TSA bar chart above already covers "who's fastest".
        $ahtTrend = $callEvents
            ->groupBy(fn (CallEvent $e) => $e->occurred_at->timezone('Asia/Manila')->toDateString())
            ->map(fn ($dayEvents) => (int) round($dayEvents->avg('duration_seconds')))
            ->sortKeys();

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
            'ahtSeconds'      => $rows->pluck('aht_seconds')->values(),
            'ahtTrendLabels'  => $ahtTrend->keys()->values(),
            'ahtTrendSeconds' => $ahtTrend->values()->values(),
            'hasAnyAht'       => $callEvents->isNotEmpty(),
        ];

        return view('calls.analytics', [
            'rows'                       => $rows,
            'dateFrom'                   => $dateFrom,
            'dateTo'                     => $dateTo,
            'from'                       => $from,
            'to'                         => $to,
            'chartData'                  => $chartData,
            'statusTime'                 => $statusTime,
            'loginTimeDisplay'           => $loginTimeDisplay,
            'totalLeadsSum'              => $totalLeadsSum,
            'totalCateredSum'            => $totalCateredSum,
            'overallAhtDisplay'          => $overallAhtDisplay,
            'overallUnproductiveDisplay' => $overallUnproductiveDisplay,
        ]);
    }
}
