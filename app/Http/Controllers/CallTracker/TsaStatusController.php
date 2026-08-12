<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\LeadActivity;
use App\Models\TsaShift;
use App\Models\TsaStatusLog;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

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

        $tsa->update([
            'status'            => $data['status'],
            'status_changed_at' => now(),
            'status_locked_by'  => $data['status'] === TsaShift::STATUS_LOCKED ? $user->id : null,
        ]);
        TsaStatusLog::log($tsa, $data['status']);

        if ($request->wantsJson()) {
            return response()->json(['success' => true, 'status' => $data['status'], 'tsa_id' => $tsa->id]);
        }

        return back();
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
        $tsaId    = $request->filled('tsa') ? $request->integer('tsa') : null;
        $dateFrom = $request->string('date_from')->toString();
        $dateTo   = $request->string('date_to')->toString();
        $range    = ($dateFrom && $dateTo)
            ? [Carbon::parse($dateFrom)->startOfDay(), Carbon::parse($dateTo)->endOfDay()]
            : null;

        $statusQuery = TsaStatusLog::with('tsa');
        if ($tsaId) {
            $statusQuery->where('tsa_id', $tsaId);
        }
        if ($range) {
            $statusQuery->whereBetween('created_at', $range);
        }
        // stdClass, not a plain array — ->status/->tsa_id/->created_at
        // property access (not ['status']/['tsa_id']) matches how a real
        // TsaStatusLog model already reads everywhere else this page's
        // data is used, even though these rows are now a synthetic merge
        // instead of one model's rows.
        $statusRows = $statusQuery->get()->map(fn (TsaStatusLog $log) => (object) [
            'created_at' => $log->created_at,
            'id'         => $log->id,
            'tsa'        => $log->tsa,
            'tsa_id'     => $log->tsa_id,
            'kind'       => 'status',
            'status'     => $log->status,
            'detail'     => null,
        ]);

        $callQuery = LeadActivity::where('type', 'call_clicked')->with('lead.tsa');
        if ($tsaId) {
            $callQuery->whereHas('lead', fn ($q) => $q->where('tsa_id', $tsaId));
        }
        if ($range) {
            $callQuery->whereBetween('created_at', $range);
        }
        $callRows = $callQuery->get()->map(fn (LeadActivity $activity) => (object) [
            'created_at' => $activity->created_at,
            'id'         => $activity->id,
            'tsa'        => $activity->lead->tsa ?? null,
            'tsa_id'     => $activity->lead->tsa_id ?? null,
            'kind'       => 'call',
            'status'     => null,
            'detail'     => $activity->description,
        ]);

        // Sort key mixes both sources' ids into one lexicographic string —
        // not a meaningful cross-table identity, just a stable tiebreak so
        // two rows in the same second never swap order between page loads.
        $merged = $statusRows->concat($callRows)
            ->sortByDesc(fn ($row) => $row->created_at->format('Y-m-d H:i:s') . '-' . sprintf('%010d', $row->id))
            ->values();

        $perPage = 30;
        $page    = Paginator::resolveCurrentPage() ?: 1;
        $logs    = new LengthAwarePaginator(
            $merged->slice(($page - 1) * $perPage, $perPage)->values(),
            $merged->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()]
        );
        $logs->withQueryString();

        return view('calls.tsa-logs', [
            'logs'        => $logs,
            'tsas'        => TsaShift::orderBy('sort_order')->get(),
            'selectedTsa' => $request->integer('tsa'),
            'dateFrom'    => $dateFrom,
            'dateTo'      => $dateTo,
            'statuses'    => TsaShift::STATUSES,
        ]);
    }
}
