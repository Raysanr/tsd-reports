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
    /** The statuses that make a TSA eligible to receive a new round-robin
     *  lead — shared with DashboardController's "at risk" warning so the
     *  two can never disagree about who's actually available (a product
     *  flagged "no TSA logged in" here while next() below would still
     *  happily assign to a Calling/Wrap Up TSA would be a confusing, wrong
     *  warning). See next()'s own doc comment for why Calling/Wrap Up count
     *  as available alongside Login. */
    public const ELIGIBLE_STATUSES = [TsaShift::STATUS_LOGIN, TsaShift::STATUS_CALLING, TsaShift::STATUS_WRAP_UP];

    /** How long a product's eligible roster must stay continuously
     *  non-empty before catchUpUnassignedLeads() will hand out that
     *  product's backlog — see trackRosterAvailability()'s own doc
     *  comment. Explicit request, 2026-09-17: "3 minutes." */
    public const GAP_BUFFER_MINUTES = 3;

    /** Returns the TsaShift to assign next, or null if this product has no
     *  active, currently-available TSAs configured (a real, surfaceable gap
     *  — see SyncPancakeLeads, which leaves the lead 'unassigned' rather
     *  than guessing). `active` alone isn't enough here anymore: a TSA on
     *  Break/DNA Huddle/Coaching/Logout (TsaShift::status, set via the
     *  topbar dropdown) is still `active` in the long-term admin sense but
     *  isn't actually available for a live call right now.
     *
     *  Calling and Wrap Up both count as available too (explicit request,
     *  2026-08-20) — a round-robin assignment just queues the lead for
     *  whenever the TSA gets to it, it doesn't require them to be idle this
     *  exact second, so being on another call already (or in after-call
     *  wrap-up right after one) shouldn't leave leads piling up unassigned.
     *  Worth knowing since Wrap Up's own auto-expiry back to Login was
     *  removed (2026-09-01, explicit request): a TSA who stays in Wrap Up
     *  indefinitely (rather than manually moving to Login/Break/etc.) keeps
     *  counting as eligible here for exactly as long as they do — this was
     *  already true while Wrap Up was capped at ~60s, just no longer bounded
     *  by that timer. */
    public static function next(Product $product): ?TsaShift
    {
        $roster = self::eligibleRoster($product);
        if ($roster->isEmpty()) return null;

        $state = self::state($product);

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

    /** Active TSAs on an eligible status, minus anyone who's hit their
     *  daily_lead_cap (Leads Setup page) — logged in and otherwise
     *  eligible, but shouldn't receive any more today, same "leave it
     *  unassigned rather than guess" fallback as an empty roster if
     *  everyone left is capped.
     *
     *  A same-day fix (b44cfc9, REVERTED here) briefly bypassed this cap
     *  entirely when a capped TSA was the ONLY one online, reasoning the
     *  cap shouldn't strand leads with nobody else to give them to. That
     *  bypass had a severe, unforeseen interaction with
     *  catchUpUnassignedLeads() (SyncPancakeLeads): that method sweeps the
     *  ENTIRE backlog of unassigned leads - no batch limit - in one run,
     *  calling next() once per lead. With the cap bypassed, nothing ever
     *  stopped that loop from giving a sole online TSA lead after lead
     *  after lead - real production incident, 2026-09-14: Gemma alone
     *  online absorbed 1336 leads in one sweep (cap 75), Katherine 359,
     *  Lika 268, Mariel 148, out of 10,257 leads backlogged since August.
     *  Explicit follow-up confirmed: "remove the bypass - cap is a hard
     *  limit again" - a capped TSA now simply gets no more leads today no
     *  matter what, same as before b44cfc9; a lead with nobody eligible
     *  (everyone capped or offline) stays unassigned until another TSA
     *  logs in or the cap naturally resets at midnight (leadsAssignedToday()
     *  is a rolling whereDate('assigned_at', today()) count, already 0
     *  again on a new day with no separate reset job needed). */
    public static function eligibleRoster(Product $product)
    {
        return $product->tsas()->where('active', true)
            ->whereIn('status', self::ELIGIBLE_STATUSES)
            ->get()
            ->reject(fn (TsaShift $tsa) => $tsa->hasReachedDailyCap())
            ->values();
    }

    /**
     * Shift-handover fairness (explicit request, 2026-09-17: "it should not
     * be bulk all redistribute to the one tsa that got first login") —
     * stamps round_robin_states.roster_available_since the moment a
     * product's eligible roster goes from empty to non-empty, and clears it
     * again once the roster goes back to empty. Called on every sync tick
     * (SyncPancakeLeads::doSync(), ahead of both the per-order assignment
     * loop and catchUpUnassignedLeads()) so the timestamp reflects real
     * login timing, not just whenever a lead happened to need assigning.
     *
     * catchUpUnassignedLeads() reads this back to hold off handing out a
     * gap's backlog until GAP_BUFFER_MINUTES have passed since the first
     * TSA showed up — giving the rest of a closing/opening team a few
     * minutes to log in too, so the backlog splits across whoever's online
     * by then instead of 100% landing on whoever logged in first. A brand
     * new lead assigned via next() (not the catch-up path) is NOT held back
     * by this — only already-stuck backlog waits; a lead arriving fresh
     * while at least one TSA is already past the buffer assigns immediately
     * same as today.
     */
    public static function trackRosterAvailability(Product $product): void
    {
        $state      = self::state($product);
        $hasRoster  = self::eligibleRoster($product)->isNotEmpty();

        if ($hasRoster && !$state->roster_available_since) {
            $state->update(['roster_available_since' => now()]);
        } elseif (!$hasRoster && $state->roster_available_since) {
            $state->update(['roster_available_since' => null]);
        }
    }

    /** True once a product's roster has been continuously non-empty for at
     *  least GAP_BUFFER_MINUTES — i.e. it's safe to hand out THIS product's
     *  stuck backlog now, per trackRosterAvailability()'s own doc comment.
     *  A product whose round_robin_states row doesn't exist yet, or whose
     *  roster_available_since is null (currently empty, or never tracked —
     *  e.g. before this migration ran), is NOT past the buffer: no known
     *  "first login" moment to measure from, so catchUpUnassignedLeads()
     *  simply tries again next run once tracking has a timestamp to check. */
    public static function isPastHandoverBuffer(Product $product): bool
    {
        $since = self::state($product)->roster_available_since;
        return $since !== null && $since->lte(now()->subMinutes(self::GAP_BUFFER_MINUTES));
    }

    private static function state(Product $product): RoundRobinState
    {
        return RoundRobinState::firstOrCreate(['product_id' => $product->id]);
    }
}
