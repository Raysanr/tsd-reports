<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real production case (2026-08-22), order #1353632, Angelica: a genuine
 * upsell (Ear Relief Balm, tagged "Upsell TSD (Ear Relief Balm)") on an order
 * whose Pancake status is Canceled (status_code 6) — Pancake still shows the
 * order and both line items intact, the upsell clearly happened, but
 * SyncTodayOrders forces is_upsell false for ANY VOID_STATUSES status,
 * Canceled included, and Canceled isn't one of the two exceptions (Returning/
 * Returned -> is_returned_upsell, Restocking -> is_restocking_upsell) that
 * already preserve a real upsell through a void status. Confirmed with the
 * user this specific case should still count — this column is the same
 * "preserve it separately" pattern as those two, scoped ONLY to status 6
 * (see Order::isRealUpsell()'s own doc comment for why the other void
 * statuses are deliberately left alone).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_upsell_on_voided_order')->default(false)->after('excluded_upsell_seller');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('is_upsell_on_voided_order');
        });
    }
};
