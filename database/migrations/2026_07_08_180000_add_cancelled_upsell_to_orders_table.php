<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Marks an order whose upsell add-on was removed while the customer's
            // primary/original order still proceeded (SyncTodayOrders' "Fix #8"
            // remainingItemIsJustTheBase case) — distinct from a fully void order
            // (VOID_STATUSES) and from Restocking (status_code 11); this is neither.
            $table->boolean('is_cancelled_upsell')->default(false)->after('is_upsell');

            // The upsell amount as last observed BEFORE the add-on was removed —
            // preserved here because the live Pancake order no longer carries that
            // line item (and therefore no price for it) once it's been cancelled.
            // Only populated when a prior sync had already captured the order as a
            // real upsell; cancellations that happen before we ever see the order
            // as an upsell have no amount to recover and stay at 0.
            $table->decimal('cancelled_upsell_amount', 10, 2)->default(0)->after('is_cancelled_upsell');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_cancelled_upsell', 'cancelled_upsell_amount']);
        });
    }
};
