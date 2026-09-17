<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\CallRecordingHour;
use App\Models\Lead;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use App\Support\ProductPerformance;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/** Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift. */
class AnalyticsController extends Controller
{
    use PersistsCallTrackerFilters;

    // Fixed shift length (minutes/working day) used as "Total Logged-in
    // Hours" — a flat per-TSA constant, not derived from TsaShift::
    // shift_start/shift_end (those currently run ~540min/9hr for most
    // TSAs, a different number than the 440 actually used here). No longer
    // part of the Unproductive Time NUMERATOR itself (that's now Break +
    // Lunch + DNA Huddle + Huddle + Coaching + Others real status-log time
    // — see TsaShift::UNPRODUCTIVE_STATUSES' own doc comment, explicit
    // request 2026-09-17) — still the denominator for unproductive_ratio
    // (% of a scheduled shift spent unproductive) below.
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

        // Scoped to pancake_created_at (when the order actually came in),
        // NOT assigned_at (explicit fix, 2026-09-16: yesterday's "Total
        // Leads" showed 1000+ for several TSAs — Kathleen 1302, Katherine
        // 1997 — confirmed live against Leads Report/Dashboard showing far
        // fewer for the same TSA/date, i.e. genuinely inflated, not real
        // volume). Root cause: assigned_at gets re-stamped to now() by
        // SyncPancakeLeads::catchUpUnassignedLeads() whenever it finally
        // works through a backlog of old unassigned leads (any product that
        // went a while without an active TSA roster, a sync gap, etc.) — a
        // lead created weeks ago but only just assigned YESTERDAY by that
        // sweep counted as yesterday's lead here, even though it isn't one.
        // LeadController hit this exact regression on 2026-09-14 (see that
        // file's own long doc comment above its date filter, "i want only to
        // make it only today leads in every day... that is working before")
        // and fixed it the same way: pancake_created_at answers "when did
        // this lead genuinely come in", independent of whenever it happened
        // to get (re-)assigned. Falls open on a null pancake_created_at
        // (same "unknown, not excluded" convention LeadController's own
        // fix uses) rather than silently dropping older rows with no
        // creation date on record at all.
        $leads = Lead::with('tsa')->whereNotNull('tsa_id')
            ->where(function ($q) use ($from, $to) {
                $q->whereBetween('pancake_created_at', [$from, $to])
                    ->orWhereNull('pancake_created_at');
            })
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

        // "Called" (explicit fix, 2026-09-16: this table/chart/KPI card read
        // as "no calls logged" — everyone's Called stuck at 0 — even on
        // days Call Log showed real dialing activity for the same TSAs/
        // range) — was Lead::status === 'called', which ONLY ever gets set
        // by updateDisposition() (LeadController) when a TSA explicitly
        // logs an outcome/disposition on a lead, so any TSA who called
        // leads all day without separately logging a disposition on each
        // one read as having made zero calls here.
        //
        // First fix attempt switched this to distinct leads with a matched
        // CallEvent (lead_id set) — still undercounted, confirmed live the
        // same day: the bottom table's own "Calls (with duration)" column
        // (CallRecordingHour, real phone recordings synced from Drive, see
        // this file's AHT comment below) showed Katherine Chua at 61 real
        // calls while the top chart's "Called" bar for her stayed near-zero.
        // Root cause: CallEvent.lead_id only gets set when CallEventController
        // ::matchLead() successfully matches the reported phone number
        // against one of that TSA's own leads by normalized digits — a
        // real call a TSA actually made can easily NOT match (lead's phone
        // number on file differs from what was dialed, lead got
        // reassigned/deleted since, etc.), silently dropping it from a
        // lead-linked count even though CallRecordingHour already has no
        // trouble counting the same call at the TSA level (no lead-matching
        // involved at all). Explicit decision, 2026-09-16: "Called" here
        // means real TSA call volume, so it now reads CallRecordingHour's
        // own call_count (same source/number the bottom table already
        // shows) instead of a lead count. Confirm-rate/no-answer-rate/avg-
        // response-time genuinely need a specific lead's disposition/
        // timestamps to mean anything, so those stay scoped to dispositioned
        // leads separately below (see that block's own comment) — "Called"
        // (the count) and "confirmed of those dispositioned" (the rate) are
        // deliberately not the same population.
        $rows = TsaShift::with('restDays')->orderBy('sort_order')->get()->map(function (TsaShift $tsa) use ($leads, $recordingHours, $from, $to) {
            $mine = $leads->where('tsa_id', $tsa->id);

            // Confirm-rate/No-Answer-rate/Avg-Response are scoped to leads
            // with a real logged disposition — i.e. actually dispositioned
            // by a TSA (LeadController::updateDisposition()), not merely
            // dialed — same "answered"/"unanswered" tag groups the Leads
            // Report's own "Unanswered Call Leads" section and
            // ProductPerformance::METRIC_COLUMNS already use (explicit
            // request, 2026-09-16: these 3 columns should read the real
            // disposition tag groups, not a loose substring guess/a
            // CallEvent-matched subset — see ProductPerformance's own
            // DISPOSITION_KEYWORDS/UNANSWERED_COLUMNS doc comment for why
            // that list, not a second hand-copied one, is the source of
            // truth here).
            $dispositioned = $mine->filter(fn (Lead $l) => filled($l->disposition));

            // No-Answer Rate — any of the 6 UNANSWERED_COLUMNS tags (DFR,
            // Double Order, FSD Uncleared, Not Answering, Unattended,
            // Invalid Number), not just "not answering" alone.
            $noAnswer = $dispositioned->filter(function (Lead $l) {
                $disposition = str_replace("'", '', $l->disposition);
                foreach (ProductPerformance::UNANSWERED_COLUMNS as $column) {
                    foreach (ProductPerformance::DISPOSITION_KEYWORDS[$column] as $kw) {
                        if (stripos($disposition, $kw) !== false) return true;
                    }
                }
                return false;
            })->count();

            // Confirm Rate — any real upsell/TSD confirmation tag. Lead has
            // no is_upsell/amount flags the way Order does (isBroadRealUpsell()
            // needs those), so this matches on the tag text itself — every
            // real upsell tag contains the word "upsell" (see
            // Order::hasUpsellTag()'s own "UPSELL TSD"/"TSD UPSELL" pattern),
            // which is exactly the signal available from disposition text
            // alone.
            $confirmed = $dispositioned->filter(fn (Lead $l) => stripos($l->disposition, 'upsell') !== false)->count();

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

            $loggedInMinutes = $workingDays * self::SHIFT_MINUTES_PER_DAY;

            // Unproductive Time formula (explicit request, 2026-09-17):
            // "Break + Lunch + DNA Huddle + Huddle + Coaching + Others" —
            // see TsaShift::UNPRODUCTIVE_STATUSES' own doc comment. Reused
            // (not recomputed) at $statusSeconds below, which aggregates
            // this same per-TSA secondsByStatus() call into the team-wide
            // Status Time section — same "call once, read twice" pattern
            // the rest of this method already follows for $mine/
            // $dispositioned.
            $tsaStatusSeconds = TsaStatusLog::secondsByStatus($tsa, $from, $to);
            $unproductiveMins = TsaStatusLog::unproductiveSecondsFromStatusSeconds($tsaStatusSeconds) / 60;

            return [
                'tsa'                 => $tsa,
                'total'               => $mine->count(),
                'called'              => $myCallCount,
                'confirmed'           => $confirmed,
                'no_answer'           => $noAnswer,
                'confirm_rate'        => $dispositioned->count() ? round($confirmed / $dispositioned->count() * 100, 1) : null,
                'no_answer_rate'      => $dispositioned->count() ? round($noAnswer / $dispositioned->count() * 100, 1) : null,
                'aht_seconds'         => $ahtSeconds,
                'aht_call_count'      => $myCallCount,
                'tht_seconds'         => $thtSeconds,
                'working_days'        => $workingDays,
                'logged_in_minutes'   => $loggedInMinutes,
                'unproductive_minutes'=> $unproductiveMins,
                'unproductive_ratio'  => $loggedInMinutes > 0 ? round($unproductiveMins / $loggedInMinutes * 100, 1) : null,
                'status_seconds'      => $tsaStatusSeconds,
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
        // Reuses each row's own 'status_seconds' (computed once above,
        // alongside that row's own Unproductive Time) rather than calling
        // secondsByStatus() a second time per TSA here.
        $statusSeconds = array_fill_keys(array_keys(TsaShift::STATUSES), 0);

        foreach ($rows->pluck('status_seconds') as $tsaStatusSeconds) {
            foreach ($tsaStatusSeconds as $status => $seconds) {
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
