<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\TeamShiftWindow;
use Illuminate\Console\Command;

/**
 * One-time command (2026-09-07, revised same day — second time) —
 * recomputes `team` for every order from 2026-09-05 onward using the
 * time-based rule (TeamShiftWindow), since SyncTodayOrders.php only
 * applies the current rule to NEWLY synced orders going forward; an order
 * already stored under an earlier rule needs this explicit backfill to
 * match. Orders before 2026-09-05 are deliberately never touched —
 * explicit scope decision, not a bug: only orders from the date the
 * team-transition actually happened get recomputed, older history keeps
 * its original attribution.
 *
 * Uses effective_created_at (pancake_inserted_at, falling back to
 * pancake_created_at for older rows with no insertion timestamp) — NOT
 * pancake_created_at alone. This was originally pancake_created_at (the
 * "worked-at" time), but that disagreed with Leads Report's own hour
 * bucketing (which always used effective_created_at, to match Pancake
 * POS's own "Created At" filter) whenever a lead sat untouched before
 * being tagged: a lead truly created 8:56am but tagged/worked at 3:20pm
 * got team='SH Naturals' (from the 3:20pm tag) while Leads Report's
 * hourly table bucketed it under 8am — the same report disagreeing with
 * itself. Team now matches "when was this lead actually created," the
 * same question Leads Report's own grouping already answers everywhere.
 *
 * Safe to re-run (idempotent) — recomputing the same order twice always
 * produces the same team from the same stored effective_created_at.
 */
class BackfillTimeBasedTeams extends Command
{
    protected $signature   = 'orders:backfill-time-based-teams {--dry-run : Report what would change without writing}';
    protected $description = 'One-time backfill: recompute team from effective_created_at hour for orders from 2026-09-05 onward';

    private const SCOPE_START = '2026-09-05 00:00:00';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $updated = 0;
        $byTeam  = [];

        Order::whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) >= ?', [self::SCOPE_START])
            ->whereRaw('COALESCE(pancake_inserted_at, pancake_created_at) IS NOT NULL')
            ->chunkById(500, function ($orders) use ($dryRun, &$updated, &$byTeam) {
                foreach ($orders as $order) {
                    $team = TeamShiftWindow::forHour((int) $order->effective_created_at->format('G'));

                    if ($order->team === $team) continue;

                    if (!$dryRun) {
                        $order->update(['team' => $team]);
                    }
                    $updated++;
                    $byTeam[$team] = ($byTeam[$team] ?? 0) + 1;
                }
            });

        $verb = $dryRun ? 'would update' : 'updated';
        $this->info("Backfill complete: {$verb} {$updated} order(s) from " . self::SCOPE_START . ' onward.');
        foreach ($byTeam as $team => $count) {
            $this->line("  {$team}: {$count}");
        }

        return self::SUCCESS;
    }
}
