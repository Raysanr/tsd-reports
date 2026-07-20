<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Pancake's raw `inserted_at` (order/lead first created), converted to
            // Manila time but otherwise untouched — unlike pancake_created_at (which
            // is business-adjusted to "when a TSA actually worked this," see
            // SyncTodayOrders::resolveWorkedAt()). Every report in this app
            // intentionally keeps reading pancake_created_at for its own date
            // bucketing; this column exists so a report that specifically needs
            // "the calendar day POS would file this order under" (e.g. reconciling
            // against POS's own Created-At filter) has something to read instead of
            // approximating with pancake_created_at, which can land on a different
            // day for a backlog lead worked after its creation date.
            $table->timestamp('pancake_inserted_at')->nullable()->after('pancake_created_at');
            $table->index('pancake_inserted_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['pancake_inserted_at']);
            $table->dropColumn('pancake_inserted_at');
        });
    }
};
