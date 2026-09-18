<?php

namespace App\Console\Commands;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Order;
use App\Support\PancakeOrderTagApi;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;

/**
 * One-off backfill for real Call Tracker upsells whose "UPSELL TSD - ..."
 * tag silently never attached in Pancake — root-caused 2026-09-18:
 * addUpsellItem()'s combined item+tag PUT could report success (and the
 * line item genuinely landed) while the tag write itself was flaky and
 * silently no-opped, with zero detection until that day's fix added a
 * verify-refetch (see PancakeOrderTagApi::addUpsellItem()'s own doc
 * comment). Confirmed live: 8 of 57 real upsells logged that same day
 * (14%) were missing their tag, and since upsells added via the Upsell
 * button have no OTHER signal SyncTodayOrders can detect them by (no
 * assigning_seller is ever set on the new line item — see
 * hasUpsellBySeller()'s own scope, items[1:] only — unlike a tag added
 * through Log Outcome), a lost tag meant the upsell was completely
 * invisible to the Dashboard/Leaderboard/TSA Performance, not merely
 * mis-tagged in Pancake's own UI.
 *
 * Candidate set: every LeadActivity of type upsell_added logged as a
 * success (not "Pancake write failed") whose linked Order is still
 * is_upsell/is_returned_upsell/is_upsell_on_voided_order all false — the
 * exact structural signature of a lost tag, cross-checked against local
 * DB state only (cheap, no live Pancake call needed to find candidates).
 *
 * Fix, per candidate: re-add "UPSELL TSD - <name>" (parsed back out of
 * the activity's own description — the same string addUpsell() itself
 * built) via PancakeOrderTagApi::addTagsToOrder(), which — after this
 * exact incident — verifies the write actually landed before reporting
 * success, so this backfill can trust its own result instead of risking
 * writing the same silent-failure a second time. Then re-syncs every
 * distinct affected date via `pancake:sync-today --date=X` (not a
 * hand-rolled local is_upsell write) so is_upsell/amount/product get
 * recomputed by the exact same logic that computes them for every other
 * order, with zero risk of this command's own copy drifting out of sync
 * with SyncTodayOrders' real rules.
 */
class BackfillLostUpsellTags extends Command
{
    protected $signature   = 'calls:backfill-lost-upsell-tags
        {--days=30 : How many past days to check, from today backward}
        {--dry-run : List affected orders without changing anything}';
    protected $description = 'Re-add upsell tags that silently never landed in Pancake for real Call Tracker upsells, then resync the affected dates';

    public function handle(PancakeOrderTagApi $api): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $days   = max(1, (int) $this->option('days'));
        $since  = now()->subDays($days)->startOfDay();

        $activities = LeadActivity::where('type', 'upsell_added')
            ->where('created_at', '>=', $since)
            ->where('description', 'not like', '%Pancake write failed%')
            ->orderBy('id')
            ->get(['id', 'lead_id', 'description', 'created_at']);

        if ($activities->isEmpty()) {
            $this->info('No upsell_added activities in this window.');
            return self::SUCCESS;
        }

        $leadsById = Lead::whereIn('id', $activities->pluck('lead_id')->unique())
            ->get(['id', 'pancake_order_id'])
            ->keyBy('id');

        $ordersByPancakeId = Order::whereIn('pancake_order_id', $leadsById->pluck('pancake_order_id')->filter()->unique())
            ->get(['id', 'pancake_order_id', 'is_upsell', 'is_returned_upsell', 'is_upsell_on_voided_order', 'raw_tags', 'pancake_created_at'])
            ->keyBy('pancake_order_id');

        $checked   = 0;
        $fixed     = 0;
        $skipped   = 0;
        $affectedDates = collect();

        foreach ($activities as $activity) {
            $lead = $leadsById->get($activity->lead_id);
            if (!$lead || !$lead->pancake_order_id) continue;

            $order = $ordersByPancakeId->get($lead->pancake_order_id);
            if (!$order) continue;

            // Already correctly counted — a prior run, or a normal sync
            // since, may have already caught this one.
            if ($order->is_upsell || $order->is_returned_upsell || $order->is_upsell_on_voided_order) {
                continue;
            }

            // Parse the exact product name back out of addUpsell()'s own
            // description format: Added upsell "NAME" (₱PRICE × QTY) by USER.
            if (!preg_match('/^Added upsell "(.+?)" \(/', $activity->description, $m)) {
                continue;
            }
            $productName = $m[1];
            $tagName     = 'UPSELL TSD - ' . $productName;

            $checked++;
            $this->line("Checking order #{$lead->pancake_order_id} ({$productName})...");

            if ($dryRun) {
                $this->warn("  [dry-run] would re-add tag \"{$tagName}\"");
                continue;
            }

            $result = $api->addTagsToOrder($lead->pancake_order_id, [$tagName]);

            if (!($result[$tagName] ?? false)) {
                $this->error("  Could not re-add tag \"{$tagName}\" — skipping (verify manually in POS).");
                Log::warning('BackfillLostUpsellTags: could not re-add tag', [
                    'order_id' => $lead->pancake_order_id,
                    'tag_name' => $tagName,
                ]);
                $skipped++;
                continue;
            }

            $this->info("  Re-added tag \"{$tagName}\" — confirmed live in Pancake.");
            $fixed++;

            if ($order->pancake_created_at) {
                $affectedDates->push($order->pancake_created_at->copy()->timezone('Asia/Manila')->toDateString());
            }
        }

        if ($dryRun) {
            $this->info("Dry run: {$checked} order(s) would have their upsell tag re-added.");
            return self::SUCCESS;
        }

        $this->info("Checked {$checked} candidate(s): {$fixed} tag(s) re-added, {$skipped} skipped (verify manually in POS).");

        // Resync every distinct affected date (not each order individually)
        // via the exact same command/logic that computes is_upsell/amount/
        // product for every other order — see this class's own doc comment
        // for why a hand-rolled local write here would be riskier.
        foreach ($affectedDates->unique()->sort() as $date) {
            $this->line("Resyncing {$date}...");
            Artisan::call('pancake:sync-today', ['--date' => $date]);
            $this->line(Artisan::output());
        }

        return self::SUCCESS;
    }
}
