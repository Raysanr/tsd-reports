<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists SyncTodayOrders::isExcludedUpsellSeller()'s check so every other
 * "was this a real upsell" path (ProductPerformance::tally()/
 * ordersForColumn(), TsaPerformanceController's per-TSA breakdown) can also
 * respect it — those all fall back to a bare Order::hasUpsellTag($raw_tags)
 * tag-text scan for a known, separate edge case (Restocking-status orders),
 * which bypasses is_upsell entirely and would otherwise still count an
 * excluded-seller order as a real upsell everywhere except the Dashboard
 * card/Leaderboard, which read is_upsell directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('excluded_upsell_seller')->default(false)->after('is_restocking_upsell');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('excluded_upsell_seller');
        });
    }
};
