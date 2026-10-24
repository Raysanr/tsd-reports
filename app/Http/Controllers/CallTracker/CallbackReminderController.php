<?php

namespace App\Http\Controllers\CallTracker;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadActivity;
use Illuminate\Http\Request;

/**
 * Smart callback reminders (explicit request, 2026-10-24): the signed-in
 * TSA's own callbacks that are due now or within the next 5 minutes, polled
 * by calls.js to show a pop-up, play a sound and raise a desktop
 * notification. Snooze moves a callback a few minutes later.
 */
class CallbackReminderController extends Controller
{
    public const HEADS_UP_MINUTES = 5;

    public function index(Request $request)
    {
        $tsaId = $request->user()->tsa_id;
        if (! $tsaId) {
            return response()->json(['reminders' => []]);
        }

        $leads = Lead::with('product')
            ->where('tsa_id', $tsaId)
            ->whereNotNull('callback_at')
            ->whereBetween('callback_at', [now()->subHours(12), now()->addMinutes(self::HEADS_UP_MINUTES)])
            ->orderBy('callback_at')
            ->limit(10)
            ->get();

        return response()->json(['reminders' => $leads->map(fn (Lead $l) => [
            'id' => $l->id,
            'customer' => $l->customer_name ?: 'Customer',
            'product' => $l->product?->display_name,
            'phone' => $l->phone_number,
            'at' => $l->callback_at->toIso8601String(),
            'time' => $l->callback_at->timezone('Asia/Manila')->format('g:i A'),
            'due' => $l->callback_at->lte(now()),
        ])->values()]);
    }

    public function snooze(Request $request, Lead $lead)
    {
        $user = $request->user();
        abort_unless($user->isAtLeastAdmin() || ($user->tsa_id && $lead->tsa_id === $user->tsa_id), 403);
        abort_if($lead->callback_at === null, 422, 'This lead has no callback.');
        $minutes = (int) $request->validate(['minutes' => ['required', 'integer', 'in:5,10,15,30,60']])['minutes'];

        $lead->update(['callback_at' => now()->addMinutes($minutes)]);
        LeadActivity::log($lead, 'callback_snoozed', "Callback snoozed {$minutes} min by {$user->name}.", $user);

        return response()->json(['ok' => true, 'at' => $lead->callback_at->toIso8601String()]);
    }
}
