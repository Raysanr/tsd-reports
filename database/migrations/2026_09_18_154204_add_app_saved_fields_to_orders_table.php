<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the tag-loss self-healing from the add_app_added_tags_to_orders_table
 * migration to Notes and Delivery/Address (explicit request, 2026-09-18:
 * "even notes and the address when they edit or add it should be
 * reflect to the pos") — same underlying risk: updateNotes()/
 * updateShippingAddress() use the identical GET-then-PUT-whole-order
 * pattern as the tag writes that were confirmed live to lose data to a
 * stale external Pancake edit. Unlike tags (a merge — app_added_tags
 * just tracks WHICH names to ensure are present), Notes/Delivery are
 * whole-value OVERWRITE fields, so tracking here is simpler: this app's
 * own last-saved value, restored verbatim if a live check shows Pancake
 * no longer matches it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->text('app_note')->nullable()->after('app_added_tags');
            $table->text('app_note_print')->nullable()->after('app_note');
            $table->json('app_shipping_address')->nullable()->after('app_note_print');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['app_note', 'app_note_print', 'app_shipping_address']);
        });
    }
};
