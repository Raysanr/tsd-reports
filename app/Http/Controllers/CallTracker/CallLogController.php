<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\CallEvent;
use App\Models\TsaShift;
use App\Support\Teams;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift.
 * The load-reimbursement report — per-TSA call count/duration for a date
 * range, built from real call events (see CallEventController's own
 * webhook, and LeadController::logCallClick()'s in-app click-to-dial
 * counterpart — both feed the same CallEvent table). This is the
 * practical stand-in for "deduct their SIM load": no telco exposes a way
 * to read a personal prepaid balance, so this is what an admin uses to
 * work out how much load to pay back each TSA instead.
 *
 * Counts a click-to-call the moment it happens (2026-09-08, see index()'s
 * own query comment for the full history) — not only once MacroDroid's
 * own phone-side automation separately confirms a real duration, since
 * that automation not firing reliably was making genuinely worked calls
 * disappear from this report entirely.
 */
class CallLogController extends Controller
{
    use PersistsCallTrackerFilters;

    public function index(Request $request)
    {
        $user = Auth::user();

        // Remembered across a tab-away-and-back navigation (explicit
        // request, 2026-08-24) — see PersistsCallTrackerFilters's own doc
        // comment.
        $dateFrom = $this->rememberedFilter($request, 'call-log', 'date_from', now('Asia/Manila')->format('Y-m-d'));
        $dateTo   = $this->rememberedFilter($request, 'call-log', 'date_to', $dateFrom);
        $from     = Carbon::parse($dateFrom, 'Asia/Manila')->startOfDay();
        $to       = Carbon::parse($dateTo, 'Asia/Manila')->endOfDay();

        // Team filter (explicit request, 2026-08-24) — same "ALL" + config('teams')
        // convention Monitor TSA's own resolveTeam()/filteredTsas() already use.
        $teamsConfig  = Teams::config();
        $teams        = ['all' => 'ALL'] + array_map(fn ($t) => $t['name'], $teamsConfig);
        $selectedTeam = $this->rememberedFilter($request, 'call-log', 'team', 'all');
        if ($selectedTeam !== 'all' && !array_key_exists($selectedTeam, $teamsConfig)) {
            $selectedTeam = 'all';
        }
        $orderTeam = $selectedTeam !== 'all' ? $teamsConfig[$selectedTeam]['order_team'] : null;

        // TSA filter (explicit request, 2026-08-25: "make this table has
        // filter of TSA'S") — same rememberedFilter()/has()-based-empty-
        // clear convention as every other filter on this page, narrowing
        // down FROM whatever team's already picked (options list built off
        // $rows below, which is already team-scoped) rather than a
        // separate, independent TSA universe.
        $tsaFilterInput = $this->rememberedFilter($request, 'call-log', 'tsa');
        $selectedTsa    = $tsaFilterInput ? (int) $tsaFilterInput : null;

        // Opened to a TSA too (explicit follow-up, 2026-09-02: "i want tsa
        // can see access this tabs — dashboard, leads, call log") — a
        // non-admin always gets forced to their own tsa_id, same as
        // Dashboard/Leads already do, regardless of whatever team/tsa
        // params happen to be in the URL/remembered filter (this report
        // otherwise showed every TSA's call activity, real cross-visibility
        // into colleagues' data that was never scoped down before).
        if (!$user->isAtLeastAdmin()) {
            $selectedTeam = 'all';
            $orderTeam    = null;
            $selectedTsa  = $user->tsa_id;
        }

        // Reversed 2026-09-08 (explicit follow-up request: "i want when
        // they click in number the tsa it should be reflect to the call
        // log right?") — logCallClick()'s duration-less "call was
        // attempted" rows (direction='outgoing', duration_seconds=null)
        // now count as real calls immediately, the moment a TSA clicks to
        // dial, rather than waiting for MacroDroid's own separate webhook
        // to confirm a real duration. Confirmed live the same day: several
        // TSAs' real dialing activity (clicked, then manually pressed "End
        // Call" in the app) never showed up here at all, since MacroDroid
        // never fired its own confirming event for any of those calls —
        // this page read as "no calls today" for TSAs who were genuinely
        // working the whole time.
        //
        // Was excluded 2026-09-05 for the opposite reason (a click alone
        // doesn't prove a call connected, and treating its occurred_at —
        // the CLICK/dial time — as an instant 0-second call understated
        // idle gaps) — explicitly accepted now as a tradeoff: a TSA who
        // clicks without the call actually connecting still counts here,
        // in exchange for real dialing activity no longer silently
        // disappearing when the phone-side automation doesn't report back.
        // The gap-timing loop below already treats a null-duration row's
        // occurred_at as both its own start AND end (duration_seconds ?? 0),
        // which happens to be exactly correct for a click row too —
        // occurred_at IS the dial/start moment for these, not a call's end
        // the way it is for every other row.
        $events = CallEvent::with(['tsa', 'lead'])
            ->whereBetween('occurred_at', [$from, $to])
            ->when($orderTeam, fn ($q) => $q->whereHas('tsa', fn ($t) => $t->where('team', $orderTeam)))
            ->when($selectedTsa, fn ($q) => $q->where('tsa_id', $selectedTsa))
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
                // ?? 0 here covers TWO different null-duration shapes now
                // (2026-09-08, see this method's own query comment above):
                // a real MISSED call (nobody answered, genuinely no length,
                // start=end) and a logCallClick() row (duration never
                // confirmed by MacroDroid) — for the latter, occurred_at
                // IS already the dial/start moment, not an end time the
                // way it is for every confirmed row, so treating it as
                // "starts and ends at itself" is exactly correct, not a
                // fallback approximation.
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

        // Every TSA in scope shows up here now (explicit request, 2026-08-24)
        // — a TSA with zero calls in the picked range used to just vanish
        // from the table entirely, which read as "not tracked" rather than
        // "tracked, made no calls". Team-scoped explicitly here (not just
        // relying on $events already being team-filtered above) since a
        // zero-call TSA has no events to filter through in the first place.
        // $teamTsas itself stays TSA-filter-agnostic — it's also the TSA
        // dropdown's own option list, which should keep listing every TSA
        // on the picked team regardless of which one (if any) is currently
        // selected, not narrow down to just the one already picked.
        $teamTsas = TsaShift::where('active', true)
            ->when($orderTeam, fn ($q) => $q->where('team', $orderTeam))
            ->orderBy('sort_order')->get();

        $rows = $teamTsas
            ->when($selectedTsa, fn ($tsas) => $tsas->where('id', $selectedTsa))
            ->map(function (TsaShift $tsa) use ($events, $tsaGapStats) {
                $mine = $events->where('tsa_id', $tsa->id);

                return [
                    'tsa'                 => $tsa,
                    'total_calls'         => $mine->count(),
                    'avg_gap_seconds'     => $tsaGapStats[$tsa->id]['avg_gap_seconds'] ?? null,
                    'longest_gap_seconds' => $tsaGapStats[$tsa->id]['longest_gap_seconds'] ?? null,
                ];
            })->values();

        return view('calls.call-log', [
            'rows'             => $rows,
            'teamTsas'         => $teamTsas,
            'selectedTsa'      => $selectedTsa,
            'events'           => $events->take(200), // recent-first raw list, capped same reasoning as other reports
            'gapBeforeSeconds' => $gapBeforeSeconds,
            'dateFrom'         => $dateFrom,
            'dateTo'           => $dateTo,
            'teams'            => $teams,
            'selectedTeam'     => $selectedTeam,
        ]);
    }
}
