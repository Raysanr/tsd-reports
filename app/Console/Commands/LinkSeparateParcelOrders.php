<?php

namespace App\Console\Commands;

use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Explicit request (2026-08-13): a TSA sale sometimes has to ship as two
 * separate Pancake orders (a base product + an upsell add-on split into
 * their own "parcels") rather than two line items on one order. The
 * add-on's own order can come back from sync with no TSA attribution of its
 * own (no matching tag/account signal) — this command finds those orphaned
 * siblings and fills in the SAME tsa_name/team as whichever order in the
 * group DOES have one, whenever the group is marked with a real Pancake tag
 * (see Order::hasSeparateParcelTag()).
 *
 * Deliberately conservative in two ways: it only ever fills in a NULL
 * tsa_name (never overwrites an order that already has its own attribution,
 * even a wrong-looking one — that's a different problem to fix separately),
 * and it refuses to guess when a group's non-null tsa_names disagree with
 * each other, rather than picking one arbitrarily.
 */
class LinkSeparateParcelOrders extends Command
{
    protected $signature   = 'pancake:link-parcels {--days=3 : How many past days to check}';
    protected $description = "Fill in a missing TSA/team on an order's separate-parcel siblings (same customer, same day) from whichever sibling has one";

    public function handle(): int
    {
        $days  = max(1, (int) $this->option('days'));
        $since = Carbon::now('Asia/Manila')->subDays($days)->startOfDay();

        // Every order in the window with a phone number to group by — nothing to
        // link without one. Not pre-filtered to trigger-tagged orders alone: a
        // group's tag can sit on either sibling, so every candidate needs to be in
        // the pool before grouping.
        $candidates = Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) >= ?', [$since])
            ->whereNotNull('customer_phone')
            ->get();

        // Same customer_phone AND same calendar day (by effective_created_at, the
        // same POS-accurate date every report already uses) — a repeat customer
        // weeks apart must never link to an old TSA's attribution.
        $groups = $candidates->groupBy(function (Order $o) {
            $date = optional($o->effective_created_at)->toDateString();
            return $o->customer_phone . '|' . $date;
        });

        $linked            = 0;
        $groupsWithTrigger = 0;
        $skippedConflict   = 0;

        foreach ($groups as $siblings) {
            $hasTrigger = $siblings->contains(fn (Order $o) => Order::hasSeparateParcelTag($o->raw_tags ?? []));
            if (!$hasTrigger) continue;
            $groupsWithTrigger++;

            $distinctTsas = $siblings->pluck('tsa_name')->filter()->unique();

            // Zero signals (nothing to inherit from) or 2+ conflicting TSA names
            // (don't guess which one is right) — leave the whole group untouched.
            if ($distinctTsas->count() !== 1) {
                if ($distinctTsas->count() > 1) $skippedConflict++;
                continue;
            }

            $sourceOrder = $siblings->first(fn (Order $o) => $o->tsa_name === $distinctTsas->first());

            foreach ($siblings as $sibling) {
                if ($sibling->tsa_name !== null) continue;
                $sibling->update(['tsa_name' => $sourceOrder->tsa_name, 'team' => $sourceOrder->team]);
                $linked++;
            }
        }

        $this->info(
            "Checked {$groups->count()} customer/day group(s), {$groupsWithTrigger} carried the separate-parcel tag. "
            . "Linked {$linked} sibling order(s). Skipped {$skippedConflict} group(s) with conflicting TSA signals."
        );

        return self::SUCCESS;
    }
}
