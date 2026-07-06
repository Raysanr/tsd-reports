<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Pancake's numeric order status (see api-docs.pancake.vn/openapi.json,
            // components.schemas.Order.properties.status) — stored so the dashboard
            // can show *why* an order isn't counted (e.g. "Restocking"), instead of
            // it just silently disappearing from the total.
            $table->smallInteger('status_code')->nullable()->after('is_upsell');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('status_code');
        });
    }
};
