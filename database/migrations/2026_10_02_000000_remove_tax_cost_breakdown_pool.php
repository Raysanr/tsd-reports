<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the "Tax" pool added 2026-10-01 (explicit follow-up, 2026-10-02:
 * "can you remove the tax?") — CostBreakdownPool::ensureSeeded() only ever
 * ADDS/re-syncs rows from its own SEED_POOLS list, it never deletes one
 * that's been taken out of that list, so the already-deployed "tax" row
 * (and whatever amount was typed into it) needs this explicit cleanup
 * rather than just removing the seed entry. Safe no-op wherever the row
 * was never created (a fresh DB that never ran yesterday's seed).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('cost_breakdown_pools')->where('key', 'tax')->delete();
    }

    public function down(): void
    {
        // Not restoring the row on rollback — its own amount (if anyone
        // had typed one in) is gone the moment up() runs, same as deleting
        // any other manually-entered figure; recreating a blank row here
        // would be misleading.
    }
};
