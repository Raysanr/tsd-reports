<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Deletes already-expired rows from the database cache table (Railway cost
 * audit, 2026-11-11). Laravel's database cache driver only removes an expired
 * row when that exact key is read again, so one-off keys (e.g. a report range
 * that's never requested twice) stay forever: measured 22,640 of 22,694 rows
 * expired, 234 MB, 46% of the whole production DB.
 *
 * No behavior change: DatabaseStore already treats a row with
 * expiration <= now as missing, so deleting rows with expiration < now
 * removes nothing the app could still read. Batched so one run never holds
 * a long lock on the table the live app reads and writes.
 */
class PruneExpiredCache extends Command
{
    protected $signature   = 'cache:prune-expired {--batch=5000}';
    protected $description = 'Delete expired rows from the database cache table';

    public function handle(): int
    {
        if (config('cache.default') !== 'database') {
            $this->info('Cache store is not database; nothing to prune.');
            return self::SUCCESS;
        }

        $store = config('cache.stores.database');
        $table = DB::connection($store['connection'] ?? null)->table($store['table'] ?? 'cache');
        $now   = time();
        $batch = max(1, (int) $this->option('batch'));
        $total = 0;

        do {
            $deleted = (clone $table)->where('expiration', '<', $now)->limit($batch)->delete();
            $total += $deleted;
        } while ($deleted === $batch);

        $this->info("Pruned {$total} expired cache row(s).");
        return self::SUCCESS;
    }
}
