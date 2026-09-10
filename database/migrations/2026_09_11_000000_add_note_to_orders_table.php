<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persists Pancake's own order-level Note/Print Note text — previously read
 * transiently at sync time only for Order::isDuplicatedByLogistics(), never
 * stored (SyncHealthController's own comment: "orders only keeps
 * raw_tags"). Root-caused 2026-09-11, real production order #1366186: a TSA
 * cancelling an upsell by typing "cancelled upsell" into the Note field
 * (rather than the add-on ever being removed as a line item) is invisible
 * to every structural is_cancelled_upsell check, since none of them read
 * note text — see Order::noteSaysCancelledUpsell()'s own doc comment.
 * Persisting it here is what lets that new check run at reconcile time
 * against an order synced earlier, not just at the moment it's first synced.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('note')->nullable()->after('excluded_upsell_seller');
            $table->text('note_print')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['note', 'note_print']);
        });
    }
};
