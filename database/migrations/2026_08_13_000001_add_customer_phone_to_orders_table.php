<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Needed to group an order with its siblings from the same customer/same day
 * (see LinkSeparateParcelOrders) — a "separate parcel" upsell gets its own
 * Pancake order, with its own tsa_name resolution that can come back null if
 * that sibling order carries no TSA-identifying tag/account signal of its
 * own. Grouping by phone number is how we find it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('customer_phone')->nullable()->after('pancake_order_id');
            $table->index('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['customer_phone']);
            $table->dropColumn('customer_phone');
        });
    }
};
