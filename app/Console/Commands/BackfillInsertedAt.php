<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

class BackfillInsertedAt extends Command
{
    protected $signature   = 'pancake:backfill-inserted-at
        {--from= : Earliest date to backfill (Y-m-d, Philippine time)}
        {--to= : Latest date to backfill (Y-m-d, Philippine time), defaults to yesterday}
        {--days=14 : Used instead of --from when --from is omitted — how many days back from --to}';
    protected $description = 'Re-syncs a historical date range so existing orders get pancake_inserted_at populated (added after those orders were first synced)';

    public function handle(): int
    {
        $to = $this->option('to')
            ? Carbon::parse($this->option('to'), 'Asia/Manila')
            : Carbon::now('Asia/Manila')->subDay();

        $from = $this->option('from')
            ? Carbon::parse($this->option('from'), 'Asia/Manila')
            : $to->copy()->subDays((int) $this->option('days') - 1);

        if ($from->gt($to)) {
            $this->error('--from must be on or before --to.');
            return self::FAILURE;
        }

        $days = $from->diffInDays($to) + 1;
        $this->info("Backfilling pancake_inserted_at for {$from->toDateString()} → {$to->toDateString()} ({$days} day(s))...");

        // Re-syncing an already-synced date is safe: SyncTodayOrders upserts on
        // pancake_order_id and re-fetches Pancake's own inserted_at fresh each
        // time — every other stored field just gets overwritten with the same
        // value it already had.
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
