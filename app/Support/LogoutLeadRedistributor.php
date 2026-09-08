<?php

namespace App\Support;

use App\Http\Controllers\CallTracker\LeadController;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Models\TsaShift;
use App\Support\PancakeOrderTagApi;

/**
 * Explicit request (2026-08-25, from a "smart rotation" round-robin
 * follow-up): "gemma is logout already and she only catered 50 of [75
 * assigned] ... the remaining 25 will be distribute to her other team
 * automatically and equally" — confirmed the "25" means her own
 * uncalled backlog (Lead::status still 'assigned', never reached
 * 'called'), not a future-capacity concept. When a TSA logs out, hand
 * those leads off to her currently-working teammates instead of leaving
 * them stalled in her queue until she's back.
 *
 * Cross-team fallback added 2026-09-08 (explicit follow-up: "even not
 * same team?"): same-team stays the first choice always; only when
 * NOBODY on her own team is currently available does this fall back to
 * an active, logged-in TSA on the OTHER team — no product_tsa
 * eligibility check for that cross-team candidate (explicit answer: "any
 * online TSA on the other team, regardless of product setup"), since
 * this is a last-resort "someone answers the phone" handoff rather than
 * a claim she's trained on that exact product.
 *
 * Deliberately stateless — no new table, no persistent "IOU". Every
 * logout event is its own independent snapshot: whoever's backlog exists
 * AND whoever's an eligible teammate AT THAT MOMENT is what gets split,
 * nothing carried forward or reconciled if she logs back in later that
 * same day.
 */
class LogoutLeadRedistributor
{
    /**
     * Splits $tsa's own uncalled backlog evenly across her other active,
     * currently-working teammates (same TsaShift.team, not $tsa herself,
     * not also logged out right now) — round-robin one-by-one through the
     * backlog so an uneven split lands the extra lead on the first
     * teammates rather than all piling onto one. No-ops silently (same
     * "nothing to do" convention as everywhere else in this app) when
     * there's no backlog, or nobody eligible to hand it to — she keeps
     * it, it's not left orphaned.
     *
     * Returns how many leads actually moved — purely for the caller's own
     * logging/testing convenience, not something callers need to act on.
     */
    public static function redistribute(TsaShift $tsa, ?PancakeOrderTagApi $api = null): int
    {
        $api ??= app(PancakeOrderTagApi::class);

        // Root-caused 2026-08-26 (real examples #1347621, #1347619, and
        // others): a lead whose real Pancake order already resolved on its
        // own — Received/Returned/Returning/Partial return/Canceled/
        // Collected money — has nothing left for anyone to call about.
        // Redistributing it anyway just resets assigned_at to now() for a
        // dead lead, which made it reappear at the top of the receiving
        // TSA's Overdue queue looking urgent. whereNotExists (not a status
        // join) so a lead whose order hasn't synced locally yet still
        // redistributes normally — same fail-open convention used
        // elsewhere in this app.
        $backlog = Lead::where('tsa_id', $tsa->id)
            ->where('status', 'assigned')
            ->whereNotExists(function ($sub) {
                $sub->selectRaw('1')
                    ->from('orders')
                    ->whereColumn('orders.pancake_order_id', 'leads.pancake_order_id')
                    ->whereIn('orders.status_code', Order::RESOLVED_STATUSES);
            })
            ->get();
        if ($backlog->isEmpty()) {
            return 0;
        }

        $teammates = TsaShift::where('team', $tsa->team)
            ->where('id', '!=', $tsa->id)
            ->where('active', true)
            ->where('status', '!=', TsaShift::STATUS_LOGOUT)
            ->get();

        // Cross-team fallback (explicit follow-up request, 2026-09-08:
        // "even not same team?") — only kicks in when literally nobody on
        // her OWN team is currently available; same-team stays the first
        // choice every time. No product_tsa eligibility check for the
        // cross-team candidate (explicit answer to the same follow-up:
        // "any online TSA on the other team, regardless of product
        // setup") — this is a last-resort "someone answers the phone"
        // handoff, not a claim that the receiving TSA is trained on this
        // exact product the way normal round-robin assignment requires.
        if ($teammates->isEmpty()) {
            $teammates = TsaShift::where('team', '!=', $tsa->team)
                ->where('active', true)
                ->where('status', '!=', TsaShift::STATUS_LOGOUT)
                ->get();
        }

        if ($teammates->isEmpty()) {
            return 0;
        }

        $moved = 0;
        foreach ($backlog->values() as $i => $lead) {
            $newTsa = $teammates[$i % $teammates->count()];
            // Worth surfacing in the audit trail when this specific move
            // used the cross-team fallback above, not the normal same-team
            // path — makes it obvious later why a TSA on a different team
            // ended up with this lead.
            $crossTeamNote = $newTsa->team !== $tsa->team ? ' (no teammate of her own was available — cross-team fallback)' : '';

            // Resets assigned_at to now(), same convention LeadController::
            // transfer() already uses — the new TSA's overdue-threshold
            // clock starts fresh rather than inheriting however long the
            // lead already sat with the TSA who just logged out.
            $lead->update(['tsa_id' => $newTsa->id, 'assigned_at' => now()]);

            LeadActivity::log(
                $lead, 'transferred',
                "Auto-reassigned from {$tsa->display_name} to {$newTsa->display_name} — {$tsa->display_name} logged out with this lead still uncalled.{$crossTeamNote}",
                null
            );

            // New owner's own POS name tag, same as any other assignment
            // path — see LeadController::tagTsaOnPancakeOrder()'s own doc
            // comment.
            LeadController::tagTsaOnPancakeOrder($lead, $api);

            $moved++;
        }

        return $moved;
    }
}
