<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Marks an order that carries the TSA's upsell tag but whose status is
            // Restocking (Order::VOID_STATUSES forces is_upsell false for this, same
            // as every other void status — deliberately, so it never counts toward
            // confirmed gross sales — so it can't be recovered from is_upsell alone).
            // Same reasoning as is_cancelled_upsell/is_returned_upsell above, different
            // trigger. Confirmed in production: the Dashboard's "Total Restocking" KPI
            // was querying status_code=11 AND is_upsell=true, a combination that can
            // structurally never exist — 0 of 3553 real Restocking orders ever have
            // is_upsell=true, even though 271 of them genuinely carry an upsell tag.
            $table->boolean('is_restocking_upsell')->default(false)->after('returned_upsell_amount');

            // The isolated upsell add-on amount (not the whole shipment's price),
            // computed the same way as a live upsell would be — preserved here for
            // the same reason returned_upsell_amount is: once is_upsell is forced
            // false by the void status, extractUpsellAmount() is never called for
            // this order again and amount holds the full order total instead.
            $table->decimal('restocking_upsell_amount', 10, 2)->default(0)->after('is_restocking_upsell');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_restocking_upsell', 'restocking_upsell_amount']);
        });
    }
};
