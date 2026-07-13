<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * cancelled_upsell_amount defaulted to 0 for two very different situations that
 * looked identical: "we captured the real pre-cancellation price" and "we never
 * saw this order while it was still a live upsell, so there was nothing to
 * capture" (confirmed against the live Pancake API: histories entries carry only
 * tags/payment fields, never an items snapshot — once the add-on is removed, its
 * price is gone from Pancake's side for good, not just missing from our sync).
 * NULL now means the latter, so the Dashboard can show "unknown" instead of a
 * misleading ₱0.00. Existing rows can't be told apart after the fact, but every
 * currently-zero row matches the "never captured" pattern exactly, so they're
 * backfilled to NULL here rather than left as a false "confirmed zero".
 */
return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE orders ALTER COLUMN cancelled_upsell_amount DROP NOT NULL');
            DB::statement('ALTER TABLE orders ALTER COLUMN cancelled_upsell_amount DROP DEFAULT');
        } elseif ($driver === 'sqlite') {
            // sqlite (the test suite's driver) has no ALTER TABLE ... MODIFY/ALTER
            // COLUMN support at all — changing an existing column's nullability
            // requires the add/copy/drop/rename rebuild below instead.
            DB::statement('ALTER TABLE orders ADD COLUMN cancelled_upsell_amount_new NUMERIC');
            DB::statement('UPDATE orders SET cancelled_upsell_amount_new = cancelled_upsell_amount');
            DB::statement('ALTER TABLE orders DROP COLUMN cancelled_upsell_amount');
            DB::statement('ALTER TABLE orders RENAME COLUMN cancelled_upsell_amount_new TO cancelled_upsell_amount');
        } else {
            DB::statement('ALTER TABLE orders MODIFY cancelled_upsell_amount DECIMAL(10,2) NULL DEFAULT NULL');
        }

        DB::table('orders')
            ->where('is_cancelled_upsell', true)
            ->where('cancelled_upsell_amount', 0)
            ->update(['cancelled_upsell_amount' => null]);
    }

    public function down(): void
    {
        // Not scoped to is_cancelled_upsell — once this migration's up() ships,
        // SyncTodayOrders also writes NULL here for perfectly ordinary (non-cancelled)
        // orders, since the column no longer means anything for those rows. A
        // rollback must clear every NULL, not just the ones on cancelled rows, or
        // restoring the NOT NULL constraint below fails on those.
        DB::table('orders')
            ->whereNull('cancelled_upsell_amount')
            ->update(['cancelled_upsell_amount' => 0]);

        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE orders ALTER COLUMN cancelled_upsell_amount SET DEFAULT 0');
            DB::statement('ALTER TABLE orders ALTER COLUMN cancelled_upsell_amount SET NOT NULL');
        } elseif ($driver === 'sqlite') {
            DB::statement('ALTER TABLE orders ADD COLUMN cancelled_upsell_amount_new NUMERIC NOT NULL DEFAULT 0');
            DB::statement('UPDATE orders SET cancelled_upsell_amount_new = cancelled_upsell_amount');
            DB::statement('ALTER TABLE orders DROP COLUMN cancelled_upsell_amount');
            DB::statement('ALTER TABLE orders RENAME COLUMN cancelled_upsell_amount_new TO cancelled_upsell_amount');
        } else {
            DB::statement('ALTER TABLE orders MODIFY cancelled_upsell_amount DECIMAL(10,2) NOT NULL DEFAULT 0');
        }
    }
};
