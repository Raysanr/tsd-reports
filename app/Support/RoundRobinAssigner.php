<?php

namespace App\Support;

use App\Models\Product;
use App\Models\RoundRobinState;
use App\Models\TsaShift;

/**
 * Ported from call-tracker (merged into one app 2026-08-12): Tsa -> TsaShift.
 * No tsd-reports equivalent existed — this is a genuinely new capability.
 *
 * Picks the next TSA in a product's rotation and advances the pointer.
 * Rotation order is product_tsa.position (see the seed in
 * create_product_tsa_table); state is remembered per product in
 * round_robin_states so the "next" pick survives across requests/syncs.
 *
 * NOTE: TsaShift::STATUS_LOGIN doesn't exist on TsaShift until Phase 4's
 * schema-extending migration lands — next() errors at runtime until then.
 */
class RoundRobinAssigner
{
    /** Returns the TsaShift to assign next, or null if this product has no
     *  active, currently-logged-in TSAs configured (a real, surfaceable gap
     *  — see SyncPancakeLeads, which leaves the lead 'unassigned' rather
     *  than guessing). `active` alone isn't enough here anymore: a TSA on
     *  Break/DNA Huddle/Coaching/Logout (TsaShift::status, set via the
     *  topbar dropdown) is still `active` in the long-term admin sense but
     *  isn't actually available for a live call right now. */
    public static function next(Product $product): ?TsaShift
    {
        $roster = $product->tsas()->where('active', true)->where('status', TsaShift::STATUS_LOGIN)->get()
            // A TSA who's hit their daily_lead_cap (Leads Setup page)
            // is logged in and otherwise eligible, but shouldn't receive any
            // more today — same "leave it unassigned rather than guess"
            // fallback as an empty roster if everyone left is capped.
            ->reject(fn (TsaShift $tsa) => $tsa->hasReachedDailyCap())
            ->values();
        if ($roster->isEmpty()) return null;

        $state = RoundRobinState::firstOrCreate(['product_id' => $product->id]);

        $currentIndex = $state->last_tsa_id
            ? $roster->search(fn ($tsa) => $tsa->id === $state->last_tsa_id)
            : false;

        // Either this is the product's first-ever assignment (no state yet),
        // or the last-picked TSA fell out of the roster (deactivated/removed)
        // since — either way, wrap to the start rather than erroring.
        $nextIndex = $currentIndex === false ? 0 : ($currentIndex + 1) % $roster->count();
        $next      = $roster[$nextIndex];

        $state->update(['last_tsa_id' => $next->id]);

        return $next;
    }
}
