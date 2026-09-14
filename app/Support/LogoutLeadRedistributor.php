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
 * them stalled in her queue until she's back. Confirmed again,
 * 2026-09-14: "the only leads that if there's tsa that logout... will be
 * redistribute... will be like has no check because that is the
 * customer's that is not catered" — only an UNCATERED lead
 * (status still 'assigned') ever redistributes; a lead the TSA already
 * called stays exactly where it is, unaffected by her logging out.
 *
 * Cross-team fallback added 2026-09-08 (explicit follow-up: "even not
 * same team?"): same-team stays the first choice always; only when
 * NOBODY on her own team is currently available does this fall back to
 * an active, logged-in TSA on the OTHER team.
 *
 * Product-eligibility tiers added 2026-09-14 (explicit report: "i think
 * there's a leads that is removed like that, and there's slow in some
 * tsa... i want you to dig on that if there's a bug like that" —
 * root-caused live: Angel Margallo, checked in TSA Management for only
 * AudiCure/Ginseng Serum/Scar Cream/Scar Erase, had received 18 Pterylief
 * leads — a product she has never been checked for at all — because she
 * happened to be the only TSA online when Lika Trocio (who IS on
 * Pterylief's roster) logged out with them still uncalled, and this
 * class's original "any online TSA, regardless of product setup" rule
 * handed them to her anyway). Explicit confirmation on what to change:
 * "keep it, but only hand off to a teammate who handles that product" —
 * each lead in the backlog now picks its own candidate pool based on
 * ITS OWN product (not one shared teammate list for the whole backlog,
 * since different backlog leads can be different products), preferring
 * a teammate who's actually checked for that product and only falling
 * through to "any online teammate" as a last resort:
 *   1. Same team, online, checked for this lead's product
 *   2. Same team, online, ANY product (old same-team fallback)
 *   3. Cross-team, online, checked for this lead's product
 *   4. Cross-team, online, ANY product (old cross-team fallback,
 *      "someone answers the phone" as an absolute last resort)
 * A lead with no matched product (product_id null) skips straight to
 * tier 2 — there's no roster to check it against, same "no product,
 * no eligibility to check" convention RoundRobinAssigner::next() and
 * SyncPancakeLeads' catch-up sweep already use.
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
     * Splits $tsa's own uncalled backlog across her currently-working
     * teammates, preferring one who actually handles each lead's own
     * product (see the class-level doc comment's 4-tier fallback chain).
     * Round-robins independently within whichever tier a given lead
     * lands in, so an uneven split within that tier lands the extra lead
     * on the first-picked teammates rather than all piling onto one — but
     * two leads that land in DIFFERENT tiers (e.g. one has an eligible
     * teammate online, another doesn't) are never forced into the same
     * rotation together. No-ops silently (same "nothing to do" convention
     * as everywhere else in this app) when there's no backlog, or nobody
     * eligible at all (not even tier 4) to hand it to — she keeps it,
     * it's not left orphaned.
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
            ->with('product')
            ->get();
        if ($backlog->isEmpty()) {
            return 0;
        }

        $sameTeam = TsaShift::where('team', $tsa->team)
            ->where('id', '!=', $tsa->id)
            ->where('active', true)
            ->where('status', '!=', TsaShift::STATUS_LOGOUT)
            ->get();
        $crossTeam = TsaShift::where('team', '!=', $tsa->team)
            ->where('active', true)
            ->where('status', '!=', TsaShift::STATUS_LOGOUT)
            ->get();

        if ($sameTeam->isEmpty() && $crossTeam->isEmpty()) {
            return 0;
        }

        // Per-product roster lookup, cached across the whole backlog so a
        // product shared by many leads (the common case) only loads its
        // pivot rows once. Keyed by product_id; a lead with no product_id
        // never populates a key here and always falls through to the
        // "any product" tiers below.
        $productIds = $backlog->pluck('product_id')->filter()->unique();
        $rosterByProduct = \App\Models\Product::whereIn('id', $productIds)
            ->with('tsas')
            ->get()
            ->keyBy('id')
            ->map(fn ($product) => $product->tsas->pluck('id')->all());

        // Independent round-robin position per tier — see this method's
        // own doc comment on why leads landing in different tiers must
        // never share one rotation counter.
        $tierIndex = [1 => 0, 2 => 0, 3 => 0, 4 => 0];

        $moved = 0;
        foreach ($backlog as $lead) {
            $eligibleTsaIds = $lead->product_id ? ($rosterByProduct[$lead->product_id] ?? []) : [];

            $tier1 = $sameTeam->filter(fn ($t) => in_array($t->id, $eligibleTsaIds, true))->values();
            $tier3 = $crossTeam->filter(fn ($t) => in_array($t->id, $eligibleTsaIds, true))->values();

            if ($tier1->isNotEmpty()) {
                $tier = 1;
                $pool = $tier1;
            } elseif ($sameTeam->isNotEmpty()) {
                $tier = 2;
                $pool = $sameTeam;
            } elseif ($tier3->isNotEmpty()) {
                $tier = 3;
                $pool = $tier3;
            } else {
                $tier = 4;
                $pool = $crossTeam;
            }

            if ($pool->isEmpty()) {
                continue; // no eligible teammate at any tier — she keeps this one
            }

            $newTsa = $pool[$tierIndex[$tier] % $pool->count()];
            $tierIndex[$tier]++;

            // Worth surfacing in the audit trail exactly which tier this
            // move used — makes it obvious later why a TSA ended up with
            // a lead for a product she isn't checked for (tier 2 or 4), or
            // on a different team (tier 3 or 4) than the one who logged
            // out.
            $tierNote = match ($tier) {
                1 => '',
                2 => ' (no teammate checked for this product was online — same-team fallback)',
                3 => ' (no teammate of her own was checked for this product — cross-team match)',
                4 => ' (no teammate of her own was available — cross-team fallback)',
            };

            // Resets assigned_at to now(), same convention LeadController::
            // transfer() already uses — the new TSA's overdue-threshold
            // clock starts fresh rather than inheriting however long the
            // lead already sat with the TSA who just logged out.
            $lead->update(['tsa_id' => $newTsa->id, 'assigned_at' => now()]);

            LeadActivity::log(
                $lead, 'transferred',
                "Auto-reassigned from {$tsa->display_name} to {$newTsa->display_name} — {$tsa->display_name} logged out with this lead still uncalled.{$tierNote}",
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
