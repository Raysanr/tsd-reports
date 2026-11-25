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
    /** Statuses auto-call dials for. Login counts too (explicit request,
     *  2026-10-26: calls start right after logging in, no extra click). */
    public const AVAILABLE_STATUSES = [TsaShift::STATUS_LOGIN, TsaShift::STATUS_READY_TO_CALL];

    /** How long one dial keeps a phone reserved (see claimDialSlot()). */
    public const DIAL_HOLD_SECONDS = 20;

    /**
     * One dial at a time per phone (TSA feedback 2026-11-20, Aguinaldo:
     * "while I'm calling, a new dial starts and my call is cut"). Production
     * showed two dials one second apart (14:48:03 and 14:48:04) — Call
     * Tracker open in two tabs/devices, each running Auto-call, both seeing
     * "Ready to Call" before either dial set Calling. The calling screen only
     * guards its own tab, so the server now reserves the phone: the first
     * request in a 20-second window gets the lead, any other gets nothing.
     * A click-to-call reserves it too (holdPhone()).
     */
    public static function claimDialSlot(TsaShift $tsa): bool
    {
        return (bool) $tsa->dialer_host && \Illuminate\Support\Facades\Cache::add(self::slotKey($tsa), 1, self::DIAL_HOLD_SECONDS);
    }

    public static function releaseDialSlot(TsaShift $tsa): void
    {
        if ($tsa->dialer_host) {
            \Illuminate\Support\Facades\Cache::forget(self::slotKey($tsa));
        }
    }

    /** A call was just placed on this phone by hand — no auto-dial on top of it. */
    public static function holdPhone(?TsaShift $tsa): void
    {
        if ($tsa?->dialer_host) {
            \Illuminate\Support\Facades\Cache::put(self::slotKey($tsa), 1, self::DIAL_HOLD_SECONDS);
        }
    }

    /** Another TSA sharing this phone (same Dialer address) is on a call on it. */
    public static function phoneInUseByOther(TsaShift $tsa): bool
    {
        return (bool) $tsa->dialer_host && TsaShift::where('dialer_host', $tsa->dialer_host)
            ->whereKeyNot($tsa->id)->where('status', TsaShift::STATUS_CALLING)->exists();
    }

    private static function slotKey(TsaShift $tsa): string
    {
        return 'auto-call-phone:' . mb_strtolower(trim($tsa->dialer_host));
    }

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
            && in_array($tsa->status, self::AVAILABLE_STATUSES, true)
            && self::pendingLead($tsa) === null;
    }

    /** The lead $tsa should dial now: their own waiting lead, else the oldest
     *  unassigned lead today for a product they handle (claimed atomically,
     *  so two browsers can never take the same lead). */
    public static function next(TsaShift $tsa): ?Lead
    {
        if (! self::isOn($tsa) || ! in_array($tsa->status, self::AVAILABLE_STATUSES, true)) {
            return null;
        }
        if ($own = self::pendingLead($tsa)) {
            return $own;
        }

        $productIds = SmartDistribution::productIdsFor($tsa);
        if ($productIds->isEmpty() || $tsa->hasReachedDailyCap()) {
            return null;
        }
        $waiting = fn () => self::callable(Lead::query())
            ->where('status', 'unassigned')
            ->where('high_rts', false) // HIGH RTS waits for a supervisor (2026-10-26)
            ->whereIn('product_id', $productIds);
        $query = $waiting();
        if (SmartDistribution::enabled()) {
            // Smart distribution (2026-11-25): this shift's leads still come
            // first; within them, stale leads, then the biggest backlog.
            $counts = $waiting()->setEagerLoads([])->reorder()
                ->selectRaw('product_id, COUNT(*) AS waiting')->groupBy('product_id')
                ->pluck('waiting', 'product_id');
            $query->orderByRaw('CASE WHEN pancake_created_at >= ? THEN 0 ELSE 1 END', [self::hotSince()->toDateTimeString()]);
            SmartDistribution::orderWaiting($query, $counts);
        } else {
            self::hotLeadsFirst($query);
        }
        $candidates = $query->limit(5)->get();

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

    /** When this shift's leads started: 3:00 PM during the Closing shift,
     *  midnight during Opening (TeamShiftWindow, same split as the reports). */
    public static function hotSince(): Carbon
    {
        $now = Carbon::now('Asia/Manila');

        return $now->copy()->startOfDay()->setHour(TeamShiftWindow::startHourFor(TeamShiftWindow::forHour((int) $now->format('G'))));
    }

    /** Waiting-lead order (2026-11-12: "hot leads will go first to the
     *  closing, and when there's no hot leads the excess leads from opening"):
     *  leads that came in this shift first, then older leftovers from an
     *  earlier shift (e.g. Opening's excess after they log out), each oldest
     *  first. During Opening every lead today is "this shift", so nothing
     *  changes there. */
    public static function hotLeadsFirst($query)
    {
        return $query
            ->orderByRaw('CASE WHEN pancake_created_at >= ? THEN 0 ELSE 1 END', [self::hotSince()->toDateTimeString()])
            ->orderBy('pancake_created_at');
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
