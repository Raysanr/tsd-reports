<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Ported from call-tracker (merged into one app 2026-08-12): isAdmin() ->
 *  isAtLeastAdmin(). Polled every 30s by the sidebar (see calls.js) — no
 *  websockets/queue infra in this stack, so a cheap periodic count is the
 *  "free" version of a live badge rather than true push notifications.
 *
 * Date/TSA scoped (2026-08-15, explicit request) — this used to count every
 * matching lead ever, with no date bound at all, which is why the badge sat
 * at a permanent "99+" and never "reset" the way the Dashboard's own "Today"
 * cards do. Now defaults to today (same as Dashboard) and, when calls.js
 * finds a date_from/date_to/tsa in the current page's own URL (e.g. the
 * Leads page's persisted range or its TSA dropdown), follows that instead —
 * so the badge always matches whatever the admin is actually looking at.
 */
class NotificationController extends Controller
{
    public function counts(Request $request)
    {
        $user = Auth::user();

        // Hardened against an unparseable date_from/date_to, 2026-09-21 —
        // real production incident: a client-side bug (initLiveLeadsSearch()'s
        // own URL construction, since fixed) briefly let a malformed value
        // like "2026-09-21?tsa=" reach this endpoint, and Carbon::parse()
        // throwing on it 500'd every poll of this sidebar-badge count until
        // fixed. This is polled every 30s on every page from whatever the
        // browser's own JS/localStorage state happens to be — a value this
        // endpoint can never fully control from the server side alone — so
        // the same "fail open" convention every OTHER edge case in this
        // method already follows (see this class's own doc comment on a
        // null pancake_created_at) now applies here too: an unparseable date
        // falls back to today() instead of throwing, same as no date being
        // sent at all.
        try {
            $dateFrom = $request->filled('date_from')
                ? Carbon::parse($request->query('date_from'))->startOfDay()
                : today();
            $dateTo = $request->filled('date_to')
                ? Carbon::parse($request->query('date_to'))->endOfDay()
                : today()->copy()->endOfDay();
        } catch (\Throwable $e) {
            $dateFrom = today();
            $dateTo   = today()->copy()->endOfDay();
        }
        if ($dateTo->lt($dateFrom)) {
            $dateTo = $dateFrom->copy()->endOfDay();
        }

        // Every count here also requires pancake_created_at to actually be
        // today, not just assigned_at/callback_at (explicit report,
        // 2026-09-14: "why the overdue is not today? it should be today
        // only") — without this, a weeks-old order that only got
        // assigned_at/callback_at stamped today (via SyncPancakeLeads'
        // catch-up sweep or backfillCallbackFromTags()) inflated this badge
        // the same way it inflated the Leads/Overdue/Callbacks pages
        // themselves before LeadController::index()'s own matching fixes
        // (3059cdc, d8c9def, and this same day's Overdue fix) — this badge
        // must count the same set those pages actually show, or it'd
        // silently disagree with them again. Fails open for a lead with no
        // pancake_created_at at all (Pancake order missing/malformed
        // inserted_at — see SyncPancakeLeads), same convention originally
        // established for $unassignedQuery (root-caused 2026-08-15) and now
        // shared by every count below.
        $createdTodayFilter = function ($q) use ($dateFrom, $dateTo) {
            $q->whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                ->orWhereNull('pancake_created_at');
        };
        $assignedQuery   = Lead::where('status', 'assigned')->whereBetween('assigned_at', [$dateFrom, $dateTo])
            ->where($createdTodayFilter);
        // callback_at <= now() (regression fix, 2026-09-17: "the callbacks
        // is 65 but in the monitor tsa it is only 23") — root-caused: this
        // was missing the same "due now or already past due, not someday
        // in the future" clause LeadController::index()'s own Callbacks
        // view and Monitor's own per-TSA callback count both already have,
        // so a lead with a callback scheduled for LATER today (e.g. 6pm)
        // counted here even though it doesn't actually show on the
        // Callbacks page yet.
        $callbackQuery   = Lead::whereNotNull('callback_at')->whereBetween('callback_at', [$dateFrom, $dateTo])
            ->where('callback_at', '<=', now())
            ->where($createdTodayFilter);
        $unassignedQuery = Lead::where('status', 'unassigned')->where($createdTodayFilter);

        // Callbacks is shared across every TSA now (explicit request,
        // 2026-09-08, same reasoning as LeadController::index()'s own
        // matching comment: a promised follow-up is team knowledge, not one
        // TSA's private queue) — this badge must count everyone's due
        // callbacks for a non-admin too, not just their own, or the number
        // shown here would silently disagree with what the Callbacks page
        // itself now displays.
        if (!$user->isAtLeastAdmin()) {
            $assignedQuery->where('tsa_id', $user->tsa_id);
        } elseif ($request->filled('tsa')) {
            // Unassigned leads have no tsa_id by definition — the TSA filter
            // only makes sense against assigned/callback leads.
            $assignedQuery->where('tsa_id', $request->integer('tsa'));
        }
        if ($request->filled('tsa')) {
            $callbackQuery->where('tsa_id', $request->integer('tsa'));
        }

        // dialed_at exclusion (explicit request, 2026-09-17 — see
        // LeadController::overdueThresholdMinutes()'s own doc comment) —
        // only on this clone, not $assignedQuery itself: the "assigned"
        // badge counts every currently-assigned lead regardless of
        // dial state, but "overdue" must match LeadController::index()'s
        // own overdue view exactly, which now excludes a lead that's
        // already been dialed (the green checkmark) even if not yet
        // dispositioned.
        $overdueQuery = (clone $assignedQuery)->whereNull('dialed_at')
            ->where('assigned_at', '<=', now()->subMinutes(\App\Http\Controllers\CallTracker\LeadController::overdueThresholdMinutes()));

        return response()->json([
            'assigned'    => $assignedQuery->count(),
            'overdue'     => $overdueQuery->count(),
            'callbacks'   => $callbackQuery->count(),
            'unassigned'  => $user->isAtLeastAdmin() ? $unassignedQuery->count() : 0,
        ]);
    }
}
