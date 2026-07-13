<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Marks an order that carried the TSA's upsell tag but whose status is
            // Returning/Returned (Order::VOID_STATUSES forces is_upsell false for these,
            // same as any other void status, so it can't be recovered from is_upsell
            // alone) — same reasoning as is_cancelled_upsell above, different trigger.
            $table->boolean('is_returned_upsell')->default(false)->after('cancelled_upsell_amount');

            // The isolated upsell add-on amount (not the whole shipment's price),
            // computed the same way as a live upsell would be — preserved here because
            // once is_upsell is forced false by the void status, extractUpsellAmount()
            // is never called for this order again and amount holds the full order
            // total instead.
            $table->decimal('returned_upsell_amount', 10, 2)->default(0)->after('is_returned_upsell');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['is_returned_upsell', 'returned_upsell_amount']);
        });
    }
};
