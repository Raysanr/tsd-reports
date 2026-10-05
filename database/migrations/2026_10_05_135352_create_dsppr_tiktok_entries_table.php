<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSPPR - TSM Report's own "TIKTOK ORDERS" row (explicit request,
 * 2026-10-05) — a manually-run line at the bottom of the product table,
 * NOT a real Product (see app/Models/Product.php: a real product auto-
 * matches Pancake orders/tags, which TikTok Orders has no backing data
 * for). No product_id here — this is a single global row per day, not
 * per-product, so entry_date alone is the unique key. Same 6 raw fields
 * as dsppr_entries (gross_sales/net_income/ads_spent/total_orders/
 * total_leads/catered_leads), reusing DsPprCalculator::derive()/sum() as-
 * is since the math is identical — just every field is manual here too
 * (real products already have total_orders/total_leads/catered_leads
 * automated from Order data; this row has no such backing data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsppr_tiktok_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->unique();
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('net_income', 14, 2)->default(0);
            $table->decimal('ads_spent', 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('total_leads')->default(0);
            $table->unsignedInteger('catered_leads')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsppr_tiktok_entries');
    }
};
