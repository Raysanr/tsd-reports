<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\CallRecordingHour;
use App\Models\Lead;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift. */
class AnalyticsController extends Controller
{
    use PersistsCallTrackerFilters;

    // Fixed shift length (minutes/working day) used as "Total Logged-in Hours"
    // in the Unproductive Time formula — a flat per-TSA constant, not derived
    // from TsaShift::shift_start/shift_end (those currently run ~540min/9hr
    // for most TSAs, a different number than the 440 actually used here).
    private const SHIFT_MINUTES_PER_DAY = 440;

    public function index(Request $request)
    {
        // Remembered across a tab-away-and-back navigation (explicit
        // request, 2026-08-24) — see PersistsCallTrackerFilters's own doc
        // comment.
        $dateFrom = $this->rememberedFilter($request, 'analytics', 'date_from', now('Asia/Manila')->format('Y-m-d'));
        $dateTo   = $this->rememberedFilter($request, 'analytics', 'date_to', $dateFrom);
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        // Scoped to when a lead entered a TSA's queue (assigned_at), not
        // when the underlying Pancake order was created — this answers "how
        // did TSAs perform on what they were actually given this window",
        // the same anchor Overdue already uses.
        $leads = Lead::with('tsa')->whereNotNull('tsa_id')
            ->whereBetween('assigned_at', [$from, $to])
            ->get();

        // AHT (Average Handle Time) — real per-hour call-duration totals
        // synced from Google Drive (CallRecordingHour, see
        // SyncCallRecordings), not CallEvent. Switched 2026-09-05 for the
        // same reason DashboardController's own AHT/Unproductive Time cards
        // were switched on 2026-08-24 (see that controller's own doc
        // comment): CallEvent needs each TSA's phone to hit the app via
        // MacroDroid, which only 3 of 7 TSAs have ever had configured, so
        // this page's AHT was blank or badly stale for most TSAs. Keyed by
        // tsa_key, not tsa_id — CallRecordingHour has no tsa_id column.
        $recordingHours = CallRecordingHour::whereIn('tsa_key', TsaShift::pluck('tsa_key'))
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->get();

        $rows = TsaShift::with('restDays')->orderBy('sort_order')->get()->map(function (TsaShift $tsa) use ($leads, $recordingHours, $from, $to) {
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

            $myRecordingHours = $recordingHours->where('tsa_key', $tsa->tsa_key);
            $myCallCount      = $myRecordingHours->sum('call_count');
            $ahtSeconds       = $myCallCount > 0 ? (int) round($myRecordingHours->sum('total_seconds') / $myCallCount) : null;
            $thtSeconds       = (int) $myRecordingHours->sum('total_seconds');

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
                'aht_call_count'      => $myCallCount,
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
        $statusSeconds = array_fill_keys(array_keys(TsaShift::STATUSES), 0);

        foreach ($rows->pluck('tsa') as $statusTsa) {
            foreach (TsaStatusLog::secondsByStatus($statusTsa, $from, $to) as $status => $seconds) {
                $statusSeconds[$status] = ($statusSeconds[$status] ?? 0) + $seconds;
            }
        }

        // Break/Logout/Lock plus the 4 statuses Monitor TSA introduced
        // (2026-08-20) — Calling/Wrap Up/Lunch/Others all fold in here too,
        // same "everything that isn't Login/Coaching/DNA Huddle/Huddle"
        // definition this bucket already had, just extended so time TSAs
        // now log via Monitor TSA doesn't silently vanish from this total.
        $othersSeconds = ($statusSeconds[TsaShift::STATUS_BREAK] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_LOGOUT] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_LOCKED] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_CALLING] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_WRAP_UP] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_LUNCH] ?? 0)
            + ($statusSeconds[TsaShift::STATUS_OTHERS] ?? 0);

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

        $overallCallCount  = $recordingHours->sum('call_count');
        $overallAhtSeconds = $overallCallCount > 0 ? (int) round($recordingHours->sum('total_seconds') / $overallCallCount) : null;
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
        $ahtTrend = $recordingHours
            ->groupBy(fn (CallRecordingHour $h) => $h->date->toDateString())
            ->map(function ($dayHours) {
                $dayCallCount = $dayHours->sum('call_count');
                return $dayCallCount > 0 ? (int) round($dayHours->sum('total_seconds') / $dayCallCount) : 0;
            })
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
            'hasAnyAht'       => $recordingHours->isNotEmpty(),
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
