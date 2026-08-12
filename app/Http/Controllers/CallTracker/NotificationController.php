<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Support\Facades\Auth;

/** Ported from call-tracker (merged into one app 2026-08-12): isAdmin() ->
 *  isAtLeastAdmin(). Polled every 30s by the sidebar (see calls.js) — no
 *  websockets/queue infra in this stack, so a cheap periodic count is the
 *  "free" version of a live badge rather than true push notifications. */
class NotificationController extends Controller
{
    public function counts()
    {
        $user = Auth::user();

        $assignedQuery = Lead::where('status', 'assigned');
        $callbackQuery = Lead::whereNotNull('callback_at')->where('callback_at', '<=', now());

        if (!$user->isAtLeastAdmin()) {
            $assignedQuery->where('tsa_id', $user->tsa_id);
            $callbackQuery->where('tsa_id', $user->tsa_id);
        }

        $overdueQuery = (clone $assignedQuery)
            ->where('assigned_at', '<=', now()->subHours(\App\Http\Controllers\CallTracker\LeadController::overdueThresholdHours()));

        return response()->json([
            'assigned' => $assignedQuery->count(),
            'overdue'  => $overdueQuery->count(),
            'callbacks' => $callbackQuery->count(),
            'unassigned' => $user->isAtLeastAdmin() ? Lead::where('status', 'unassigned')->count() : 0,
        ]);
    }
}
