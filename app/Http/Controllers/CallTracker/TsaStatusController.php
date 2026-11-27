<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Concerns\PersistsCallTrackerFilters;
use App\Http\Controllers\Controller;
use App\Models\LeadActivity;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift,
 * isAdmin() -> isAtLeastAdmin(). NOTE: this whole controller depends on
 * TsaShift::STATUS_... / STATUSES constants and the status/status_changed_at/
 * status_locked_by columns, none of which exist on TsaShift until Phase 4 —
 * errors at runtime until then (flagged in the Phase 2 report). Also depends
 * on User::tsa() (added in Phase 4) for a non-admin's own row.
 */
class TsaStatusController extends Controller
{
    use PersistsCallTrackerFilters;

    /**
     * The topbar status panel (a TSA switching their OWN real-time
     * availability) AND TSA Management's per-row panel (an admin switching
     * ANY TSA's, including Lock) — same endpoint, same effect either way.
     * Only 'login' makes a TSA eligible for round-robin assignment
     * (RoundRobinAssigner::next()); everything else pulls them out without
     * touching `active` or any lead already assigned to them.
     *
     * A non-admin TSA can only ever affect their own row: `tsa_id` in the
     * request body is honored for an admin, ignored (falls back to the
     * caller's own tsa) for anyone else — so a TSA can't switch a
     * teammate's status by hand-crafting a request with someone else's id.
     *
     * Lock (TsaShift::STATUS_LOCKED) mirrors Pancake's own admin-only
     * conversation-receive-mode option: a non-admin can neither set it
     * (blocked below) nor change AWAY from it once an admin has (blocked
     * below too, and the topbar panel itself never renders as clickable
     * for a locked TSA in the first place — see
     * partials/tsa-status-panel.blade.php's $readonly). status_locked_by
     * records which admin locked them, cleared the moment anyone (admin)
     * sets a different status — so it's never stale once a TSA is genuinely
     * unlocked again.
     */
    public function update(Request $request)
    {
        $user = Auth::user();

        $data = $request->validate([
            'status' => ['required', 'string', 'in:' . implode(',', array_keys(TsaShift::STATUSES))],
            'tsa_id' => ['nullable', 'integer', 'exists:tsa_shifts,id'],
        ]);

        $tsa = $user->isAtLeastAdmin() && !empty($data['tsa_id'])
            ? TsaShift::find($data['tsa_id'])
            : $user->tsa;

        if (!$tsa) {
            abort(403);
        }

        if (!$user->isAtLeastAdmin()) {
            if ($data['status'] === TsaShift::STATUS_LOCKED) {
                abort(403, 'Only an admin can lock a status.');
            }
            if ($tsa->status === TsaShift::STATUS_LOCKED) {
                abort(403, 'Your status is locked by an admin — ask them to change it.');
            }
        }

        // Wrap Up is system-only to ENTER (Monitor TSA, explicit request
        // 2026-08-20) — it's set automatically when a call ends (see
        // CallEventController::store()), never a button anyone clicks. This
        // is the one path every manual status change funnels through
        // (topbar dropdown + Call Rotation + Monitor TSA's button grid all
        // POST here), so rejecting it here closes the gap even though no UI
        // exposes a Wrap Up button today. Its auto-expiry back to Login was
        // removed 2026-09-01, then reinstated 2026-09-24 pointed at Ready to
        // Call instead (1 minute, see ExpireWrapUpStatuses) — a TSA can
        // still leave Wrap Up early by picking a real next status by hand,
        // which this same endpoint (with a different $data['status']) already
        // allows freely.
        if ($data['status'] === TsaShift::STATUS_WRAP_UP) {
            abort(422, 'Wrap Up is set automatically when a call ends — it can\'t be picked manually.');
        }

        // Calling is also system-only now (explicit request, 2026-08-20) —
        // it's set automatically the moment a lead's phone number is
        // clicked (LeadController::logCallClick()), so a manual button for
        // it was removed from Monitor TSA's grid the same day. Same
        // reasoning as Wrap Up above: reject it here too so a stale client
        // (or a direct API call) can't fake "on a call" without actually
        // being on one.
        if ($data['status'] === TsaShift::STATUS_CALLING) {
            abort(422, 'Calling is set automatically when a lead\'s number is clicked — it can\'t be picked manually.');
        }

        $tsa->applyStatusChange($data['status'], $data['status'] === TsaShift::STATUS_LOCKED ? $user->id : null);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $data['status'], 'tsa_id' => $tsa->id]);
        }

        return back();
    }

    /**
     * Polled by the topbar's own status badge (calls.js pollOwnStatus(),
     * explicit request 2026-08-22) — Wrap Up and Calling are BOTH
     * system-only (see the two guards in update() above), set automatically
     * the instant a call starts/ends, with no button a TSA ever clicks to
     * confirm either one. Without this, the topbar badge (layouts/
     * calls.blade.php) was a one-time server-rendered snapshot from
     * whenever the current page happened to load — a TSA could go
     * Calling -> Wrap Up -> back to Login without their own badge ever
     * moving unless they reloaded.
     *
     * dot_class mirrors partials/tsa-status-panel.blade.php's own
     * $dotColor match() exactly — a third copy (Monitor TSA's own
     * _content.blade.php and monitor.blade.php each have one too, four
     * total); keep all four in sync if a status ever gets a new color
     * (confirmed still true adding Call Backs, 2026-09-22 — teal in every
     * one of the four).
     */
    public function own(Request $request)
    {
        $tsa = Auth::user()->tsa;
        if (!$tsa) {
            abort(403);
        }

        $dotClass = match (true) {
            $tsa->status === TsaShift::STATUS_LOGIN      => 'bg-emerald-500',
            $tsa->status === TsaShift::STATUS_READY_TO_CALL => 'bg-emerald-500',
            $tsa->status === TsaShift::STATUS_CALLING    => 'bg-red-500',
            $tsa->status === TsaShift::STATUS_WRAP_UP    => 'bg-orange-500',
            $tsa->status === TsaShift::STATUS_CALL_BACKS => 'bg-teal-500',
            $tsa->status === TsaShift::STATUS_BREAK      => 'bg-yellow-500',
            $tsa->status === TsaShift::STATUS_LUNCH      => 'bg-amber-800',
            $tsa->status === TsaShift::STATUS_COACHING   => 'bg-blue-500',
            $tsa->status === TsaShift::STATUS_DNA_HUDDLE => 'bg-purple-500',
            $tsa->status === TsaShift::STATUS_HUDDLE     => 'bg-sky-500',
            $tsa->status === TsaShift::STATUS_OTHERS     => 'bg-slate-400',
            $tsa->status === TsaShift::STATUS_LOGOUT     => 'bg-slate-300 dark:bg-slate-600',
            $tsa->status === TsaShift::STATUS_LOCKED     => 'bg-red-700',
            default => 'bg-amber-500',
        };

        return response()->json([
            'status'    => $tsa->status,
            'label'     => TsaShift::STATUSES[$tsa->status]['label'] ?? $tsa->status,
            'dot_class' => $dotClass,
            'readonly'  => $tsa->status === TsaShift::STATUS_LOCKED,
            // Lets the Calling modal tell a fresh status change (the phone
            // ending the call) from the one it opened on (2026-11-18).
            'changed_at' => $tsa->status_changed_at?->toIso8601String(),
        ]);
    }

    /** The signed-in TSA's Daily Target bubble, see TsaDailyTarget. Cached 30s
     *  per TSA (the bubble asks on every page load across every module);
     *  addUpsell() overwrites the cache with fresh figures right away.
     *  {target: null} when no Daily Target is set for this month. */
    public function ownTarget()
    {
        $tsa = Auth::user()->tsa;
        if (!$tsa) {
            abort(403);
        }

        return response()->json(['target' => \App\Support\TsaDailyTarget::cachedFor($tsa)]);
    }

    /**
     * TSA Logs — the timestamped history behind the topbar dropdown, so an
     * admin can see when each TSA actually logged in/took a break/etc.
     * across a shift, not just their current status. Same filter/pagination
     * shape as Activity Log/Call Log.
     *
     * Also merges in call-click events (LeadActivity type 'call_clicked',
     * logged by LeadController::logCallClick() — see its own doc comment
     * for why those live in LeadActivity, not TsaStatusLog). Two genuinely
     * different tables, so this can't be one Eloquent ->paginate() call —
     * built as a real LengthAwarePaginator over the merged, filtered,
     * sorted collection instead.
     */
    public function index(Request $request)
    {
        // Remembered across a tab-away-and-back navigation (explicit
        // request, 2026-08-24) — see PersistsCallTrackerFilters's own doc
        // comment. Falls back to null/no-filter, matching this page's own
        // existing "unfiltered history" default (unlike most other Call
        // Tracker pages, which default to today).
        $tsaFilter = $this->rememberedFilter($request, 'tsa-logs', 'tsa');
        $tsaId     = $tsaFilter ? (int) $tsaFilter : null;
        $dateFrom  = $this->rememberedFilter($request, 'tsa-logs', 'date_from') ?? '';
        $dateTo    = $this->rememberedFilter($request, 'tsa-logs', 'date_to') ?? '';
        $range     = ($dateFrom && $dateTo)
            ? [Carbon::parse($dateFrom)->startOfDay(), Carbon::parse($dateTo)->endOfDay()]
            : null;

        // Multi-select Status filter (explicit request, 2026-09-22) — not
        // remembered across a tab-away-and-back navigation the way tsa/date
        // are (PersistsCallTrackerFilters's own rememberedFilter() only
        // stores one scalar string in session, not an array); this page's
        // own existing default is already "unfiltered history" with
        // nothing remembered, so a fresh visit showing every status again
        // matches that same convention rather than needing new session
        // plumbing just for this one filter. status[] with no values at
        // all in the request (a genuinely fresh page load, not an explicit
        // "uncheck everything" — the picker's own JS never submits with
        // zero boxes checked, see the view's own comment) means "no
        // filter", same as omitting the param entirely.
        //
        // 'call' is a THIRD state, reversed twice on the same day: first
        // corrected out of this filter entirely ("the statuses is only
        // this: Login, Calling, ... Logout" — 'call' and 'locked' both
        // excluded), then added back in as its own checkbox after a bug
        // report ("if i filter login the displaying too is has call in
        // the table... but you can add call in the filter too") — checking
        // ONLY Login was expected to hide Call rows too, not leave them
        // permanently unaffected by this filter. 'locked' stays excluded
        // (still no real TSA-settable status to filter by — see the
        // view's own $statusFilterOptions comment). Split out here since
        // it's not a real TsaStatusLog::status value to whereIn() against —
        // it instead gates whether $callRows (LeadActivity's
        // 'call_clicked' events, a completely different table) gets
        // queried at all.
        $selectedStatuses = $request->has('status') ? array_values(array_filter((array) $request->input('status'))) : [];
        $wantsCallRows     = empty($selectedStatuses) || in_array('call', $selectedStatuses, true);
        $statusOnlyFilters = array_values(array_diff($selectedStatuses, ['call']));

        // Status changes and call clicks merged, sorted and paginated IN THE
        // DATABASE (fix, 2026-11-16: loading every row of both tables into
        // memory to show 30 hit the 256 MB limit on production once the
        // history grew — HTTP 500 on every visit without a date range).
        // Eye-hidden TSAs (TSA Management, 2026-10-15) — display only.
        $hiddenTsaIds = TsaShift::hiddenTsaIds();

        $statusQuery = DB::table('tsa_status_logs')
            ->select(['id', 'tsa_id', 'created_at', 'status', DB::raw('CAST(NULL AS VARCHAR(255)) AS detail'), DB::raw("'status' AS kind")])
            ->when($tsaId, fn ($q) => $q->where('tsa_id', $tsaId))
            ->when($range, fn ($q) => $q->whereBetween('created_at', $range))
            // Selecting ONLY "Call" must show call rows and nothing else — an
            // empty $statusOnlyFilters here means "no real status survived the
            // 'call' diff above", not "no filter" (the ORIGINAL
            // $selectedStatuses decides "no filter at all").
            ->when(!empty($selectedStatuses), fn ($q) => $q->whereIn('status', $statusOnlyFilters))
            ->when($hiddenTsaIds !== [], fn ($q) => $q->whereNotIn('tsa_id', $hiddenTsaIds));

        if ($wantsCallRows) {
            $callQuery = DB::table('lead_activities')
                ->leftJoin('leads', 'leads.id', '=', 'lead_activities.lead_id')
                ->where('lead_activities.type', 'call_clicked')
                ->select(['lead_activities.id', 'leads.tsa_id', 'lead_activities.created_at', DB::raw('CAST(NULL AS VARCHAR(255)) AS status'),
                    'lead_activities.description AS detail', DB::raw("'call' AS kind")])
                ->when($tsaId, fn ($q) => $q->where('leads.tsa_id', $tsaId))
                ->when($range, fn ($q) => $q->whereBetween('lead_activities.created_at', $range))
                ->when($hiddenTsaIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('leads.tsa_id')->orWhereNotIn('leads.tsa_id', $hiddenTsaIds)));
            $statusQuery->unionAll($callQuery);
        }

        // Same second: higher id first, so rows never swap between loads.
        $logs = DB::query()->fromSub($statusQuery, 'tsa_log_rows')
            ->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(30)->withQueryString();

        // stdClass rows with ->tsa / ->created_at, as the view reads them.
        $tsasById = TsaShift::withTrashed()->whereIn('id', collect($logs->items())->pluck('tsa_id')->filter()->unique())->get()->keyBy('id');
        $logs->setCollection($logs->getCollection()->map(fn ($row) => (object) [
            'created_at' => Carbon::parse($row->created_at),
            'id'         => (int) $row->id,
            'tsa'        => $row->tsa_id ? $tsasById->get($row->tsa_id) : null,
            'tsa_id'     => $row->tsa_id,
            'kind'       => $row->kind,
            'status'     => $row->status,
            'detail'     => $row->detail,
        ]));

        $data = [
            'logs'             => $logs,
            'tsas'             => TsaShift::where('is_hidden', false)->orderBy('sort_order')->get(),
            'selectedTsa'      => $tsaId,
            'dateFrom'         => $dateFrom,
            'dateTo'           => $dateTo,
            'statuses'         => TsaShift::STATUSES,
            'selectedStatuses' => $selectedStatuses,
        ];

        // The filter bar's own AJAX submit (regression fix, 2026-09-22 —
        // see the view's own script doc comment for the full "every
        // filter reloaded the whole page, closing the Status dropdown on
        // every single box checked" story) fetches this same URL+filters
        // and swaps in just the table fragment — same X-Table-Refresh
        // convention LeadController::index() already uses for the Leads
        // page's own table.
        if ($request->header('X-Table-Refresh')) {
            return view('calls.tsa-logs._table', $data);
        }

        return view('calls.tsa-logs', $data);
    }
}
