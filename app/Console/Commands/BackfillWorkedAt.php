<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/**
 * Re-syncs a historical date range so already-synced orders get
 * pancake_created_at recomputed from the fixed SyncTodayOrders::resolveWorkedAt()
 * logic (prefers the disposition tag's own history timestamp over the
 * TSA-assignment tag's — see that method's docblock). Orders synced before
 * that fix can have pancake_created_at anchored to an early-morning automated
 * assignment tag instead of when the call actually happened, putting real
 * activity in the wrong hour bucket on TSA Performance/Leads Report/Charts.
 *
 * Mirrors BackfillInsertedAt's exact mechanism: pancake:sync-today upserts on
 * pancake_order_id and recomputes every derived field fresh on every run, so
 * simply re-syncing an already-synced day is safe and idempotent.
 */
class BackfillWorkedAt extends Command
{
    protected $signature   = 'pancake:backfill-worked-at
        {--from= : Earliest date to backfill (Y-m-d, Philippine time)}
        {--to= : Latest date to backfill (Y-m-d, Philippine time), defaults to today}
        {--days=30 : Used instead of --from when --from is omitted — how many days back from --to}';
    protected $description = 'Re-syncs a historical date range so existing orders get pancake_created_at recomputed from the disposition tag instead of the (possibly much earlier) TSA-assignment tag';

    public function handle(): int
    {
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'), 'Asia/Manila')
            : Carbon::now('Asia/Manila');

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'), 'Asia/Manila')
            : $to->copy()->subDays((int) $this->option('days') - 1);

        if ($from->gt($to)) {
            $this->error('--from must be on or before --to.');
            return self::FAILURE;
        }

        $days = $from->diffInDays($to) + 1;
        $this->info("Backfilling pancake_created_at for {$from->toDateString()} → {$to->toDateString()} ({$days} day(s))...");

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $this->line("  {$date->toDateString()}...");
            $exitCode = Artisan::call('pancake:sync-today', ['--date' => $date->toDateString()]);
            if ($exitCode !== self::SUCCESS) {
                $this->warn("  Sync failed for {$date->toDateString()} — see log, continuing with remaining days.");
            }
        }

        $this->info('Backfill complete.');
        return self::SUCCESS;
    }
}
