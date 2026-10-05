<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TikTok Upsell section's own raw per-TSA-per-day numbers (explicit
 * request, 2026-10-05: a manually-run section on the Summary Sales
 * Report's own sheet, distinct from the real team tables above it). Same
 * column shape as tsa_sales_entries, but EVERY field here is manual entry
 * — unlike tsa_sales_entries, total_orders/catered_leads/pickup_rate/
 * upselling_rate are NOT automated from TSA Performance data for this
 * section (no real backing Pancake/order data for "TikTok Upsell" as its
 * own tracked channel yet), so they stay plain editable columns here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_tiktok_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_shift_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('net_income', 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('catered_leads')->default(0);
            $table->decimal('pickup_rate', 7, 4)->default(0);
            $table->decimal('upselling_rate', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['tsa_shift_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_tiktok_entries');
    }
};
