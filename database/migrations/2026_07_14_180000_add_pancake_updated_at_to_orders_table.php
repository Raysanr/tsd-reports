<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Pancake's raw `updated_at`, stored untouched — unlike pancake_created_at
            // (which is business-adjusted to "when a TSA actually worked this," see
            // SyncTodayOrders::resolveWorkedAt()), this column exists specifically so
            // pancake:reconcile's completeness check can compare against Pancake's own
            // updated_at-windowed counts without a semantic mismatch. No other report
            // in this app should read this column — they all intentionally use
            // pancake_created_at instead.
            $table->timestamp('pancake_updated_at')->nullable()->after('pancake_created_at');
            $table->index('pancake_updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['pancake_updated_at']);
            $table->dropColumn('pancake_updated_at');
        });
    }
};
