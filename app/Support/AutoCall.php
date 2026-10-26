<?php

namespace App\Support;

use App\Http\Controllers\CallTracker\LeadController;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\TsaShift;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Auto-call (explicit request, 2026-10-26). One global switch in TSA
 * Management (Setting auto_call_enabled, off by default); while it's on,
 * every TSA with a Dialer address gets their next lead dialed by itself
 * whenever they're "Ready to Call" — no clicking. TSAs without a Dialer
 * address keep round-robin + click-to-call. The TSA's own
 * browser does the dialing (it's the one on the phone's Wi-Fi), polling
 * next() and then running the normal click-to-call flow.
 *
 * Who gets a new lead: a Ready auto-call TSA with nothing waiting, the one
 * who has been Ready the longest first (RoundRobinAssigner::next()). A busy
 * auto-call TSA (calling / wrap-up / callbacks / break) gets nothing pushed;
 * if nobody can take it, the lead waits unassigned and the next TSA to
 * become Ready claims it here — oldest waiting lead first.
 */
class AutoCall
{
    public static function enabled(): bool
    {
        return (bool) \App\Models\Setting::get('auto_call_enabled', false);
    }

    /** Auto-call applies to this TSA: switch on and they have a phone to dial with. */
    public static function isOn(TsaShift $tsa): bool
    {
        return self::enabled() && (bool) $tsa->dialer_host;
    }

    /** Today's oldest lead already assigned to $tsa and not yet dialed. */
    public static function pendingLead(TsaShift $tsa): ?Lead
    {
        return self::callable(Lead::query())
            ->where('tsa_id', $tsa->id)
            ->where('status', 'assigned')
            ->orderBy('assigned_at')
            ->first();
    }

    /** Ready, switched on, has a phone to dial with, and nothing waiting. */
    public static function canTakeNewLead(TsaShift $tsa): bool
    {
        return self::isOn($tsa)
            && $tsa->status === TsaShift::STATUS_READY_TO_CALL
            && self::pendingLead($tsa) === null;
    }

    /** The lead $tsa should dial now: their own waiting lead, else the oldest
     *  unassigned lead today for a product they handle (claimed atomically,
     *  so two browsers can never take the same lead). */
    public static function next(TsaShift $tsa): ?Lead
    {
        if (! self::isOn($tsa) || $tsa->status !== TsaShift::STATUS_READY_TO_CALL) {
            return null;
        }
        if ($own = self::pendingLead($tsa)) {
            return $own;
        }

        $productIds = $tsa->products()->pluck('products.id');
        if ($productIds->isEmpty() || $tsa->hasReachedDailyCap()) {
            return null;
        }
        $candidates = self::callable(Lead::query())
            ->where('status', 'unassigned')
            ->whereIn('product_id', $productIds)
            ->orderBy('pancake_created_at')
            ->limit(5)
            ->get();

        foreach ($candidates as $lead) {
            $claimed = DB::table('leads')->where('id', $lead->id)->where('status', 'unassigned')
                ->update(['tsa_id' => $tsa->id, 'status' => 'assigned', 'assigned_at' => now(), 'updated_at' => now()]);
            if (! $claimed) {
                continue; // someone else took it a moment ago
            }
            $lead->refresh();
            LeadActivity::log($lead, 'assigned', "Auto-call assigned to {$tsa->display_name} (waiting since "
                . ($lead->pancake_created_at?->timezone('Asia/Manila')->format('g:i A') ?? 'earlier') . ').');
            LeadController::tagTsaOnPancakeOrder($lead, app(PancakeOrderTagApi::class), [], true);
            LeadAutoText::sendForNewLead($lead);

            return $lead;
        }

        return null;
    }

    /** Leads that came in today, aren't a scheduled callback, haven't been
     *  dialed, and whose order hasn't already resolved in Pancake. */
    private static function callable($query)
    {
        return $query->with('product')
            ->where('pancake_created_at', '>=', Carbon::now('Asia/Manila')->startOfDay())
            ->whereNull('dialed_at')
            ->whereNull('callback_at')
            ->whereNotExists(fn ($sub) => $sub->selectRaw('1')->from('orders')
                ->whereColumn('orders.pancake_order_id', 'leads.pancake_order_id')
                ->whereIn('orders.status_code', Order::RESOLVED_STATUSES));
    }
}
