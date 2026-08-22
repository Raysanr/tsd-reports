<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirmed live (2026-08-22): when the warehouse/logistics side creates a
 * second, duplicate order for the same real lead, Pancake staff flag it by
 * writing "DUPLICATED BY LOGISTICS" into the order's note_print ("For
 * printing") field — 215 such orders existed in the shop's recent history at
 * the time this was found, all counted as real leads in Leads Report/TSA
 * Performance despite being duplicates of an already-counted order, not a
 * second real lead. Same "computed flag persisted at sync time, checked
 * everywhere a real lead is counted" treatment as excluded_upsell_seller
 * (see that column's own migration) — see SyncTodayOrders::
 * isDuplicatedByLogistics() for where this gets set and
 * ProductPerformance::tally()/ordersForColumn() for where it's excluded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_duplicated_by_logistics')->default(false)->after('excluded_upsell_seller');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_duplicated_by_logistics');
        });
    }
};
