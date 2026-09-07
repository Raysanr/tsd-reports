<?php

namespace App\Console\Commands;

use App\Models\Order;
use App\Support\TeamShiftWindow;
use Illuminate\Console\Command;

/**
 * One-time command (2026-09-07) — recomputes `team` for every order from
 * 2026-09-05 onward using the new time-based rule (TeamShiftWindow),
 * since SyncTodayOrders.php only applies the new rule to NEWLY synced
 * orders going forward; an order already stored under the old "who
 * handled it" rule needs this explicit backfill to match. Orders before
 * 2026-09-05 are deliberately never touched — explicit scope decision,
 * not a bug: only orders from the date the team-transition actually
 * happened get recomputed, older history keeps its original attribution.
 *
 * Safe to re-run (idempotent) — recomputing the same order twice always
 * produces the same team from the same stored pancake_created_at.
 */
class BackfillTimeBasedTeams extends Command
{
    protected $signature   = 'orders:backfill-time-based-teams {--dry-run : Report what would change without writing}';
    protected $description = 'One-time backfill: recompute team from pancake_created_at hour for orders from 2026-09-05 onward';

    private const SCOPE_START = '2026-09-05 00:00:00';

    public function handle(): int
    {
        $dryRun  = (bool) $this->option('dry-run');
        $updated = 0;
        $byTeam  = [];

        Order::where('pancake_created_at', '>=', self::SCOPE_START)
            ->whereNotNull('pancake_created_at')
            ->chunkById(500, function ($orders) use ($dryRun, &$updated, &$byTeam) {
                foreach ($orders as $order) {
                    $team = TeamShiftWindow::forHour((int) $order->pancake_created_at->format('G'));

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
