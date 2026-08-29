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

        $dateFrom = $request->filled('date_from')
            ? Carbon::parse($request->query('date_from'))->startOfDay()
            : today();
        $dateTo = $request->filled('date_to')
            ? Carbon::parse($request->query('date_to'))->endOfDay()
            : today()->copy()->endOfDay();
        if ($dateTo->lt($dateFrom)) {
            $dateTo = $dateFrom->copy()->endOfDay();
        }

        $assignedQuery   = Lead::where('status', 'assigned')->whereBetween('assigned_at', [$dateFrom, $dateTo]);
        $callbackQuery   = Lead::whereNotNull('callback_at')->whereBetween('callback_at', [$dateFrom, $dateTo]);
        // A lead with no pancake_created_at at all (Pancake order missing/
        // malformed inserted_at — see SyncPancakeLeads) still counts, same
        // fail-open convention as LeadController::index()'s own COALESCE
        // date filter (root-caused 2026-08-15) — otherwise it's excluded by
        // whereBetween forever and never surfaces in the unassigned badge.
        $unassignedQuery = Lead::where('status', 'unassigned')
            ->where(function ($q) use ($dateFrom, $dateTo) {
                $q->whereBetween('pancake_created_at', [$dateFrom, $dateTo])
                    ->orWhereNull('pancake_created_at');
            });

        if (!$user->isAtLeastAdmin()) {
            $assignedQuery->where('tsa_id', $user->tsa_id);
            $callbackQuery->where('tsa_id', $user->tsa_id);
        } elseif ($request->filled('tsa')) {
            // Unassigned leads have no tsa_id by definition — the TSA filter
            // only makes sense against assigned/callback leads.
            $assignedQuery->where('tsa_id', $request->integer('tsa'));
            $callbackQuery->where('tsa_id', $request->integer('tsa'));
        }

        $overdueQuery = (clone $assignedQuery)
            ->where('assigned_at', '<=', now()->subHours(\App\Http\Controllers\CallTracker\LeadController::overdueThresholdHours()));

        return response()->json([
            'assigned'    => $assignedQuery->count(),
            'overdue'     => $overdueQuery->count(),
            'callbacks'   => $callbackQuery->count(),
            'unassigned'  => $user->isAtLeastAdmin() ? $unassignedQuery->count() : 0,
        ]);
    }
}
