<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — DSPPR - TSM Report (explicit request, 2026-09-24:
 * "create this page like DSPPR - TSM REPORT ... exactly like in the
 * sheets"). One row per (product, day) — the source sheet's own "Daily
 * Sales per Product Report" block, one line per product per day.
 *
 * Only the 6 raw numbers a person actually types into the sheet are
 * stored here — gross_sales, net_income, ads_spent, total_orders,
 * total_leads, catered_leads — same "store inputs, derive everything
 * else fresh" convention as projection_columns (see that migration's own
 * doc comment). Every other sheet column (NI%, AOV, Actual Cost Per
 * Lead, Excess Leads, Pick-up Rate, Conversion Rate) is confirmed-exact
 * arithmetic on these 6 (verified against the screenshot's real Sep 1
 * numbers) and computed by DsPprCalculator, never persisted.
 *
 * net_income is manual entry here, NOT derived from gross_sales/ads_spent
 * — every formula guess tested against the real sheet's Sep 1 numbers
 * (a shared-rate P&L chain like ProjectionCalculator's, gross minus ads
 * minus a flat cost rate, etc.) failed to reproduce the sheet's actual
 * Net Income to the cent, so it's captured as typed, exactly like the
 * other 5 raw fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dsppr_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('net_income', 14, 2)->default(0);
            $table->decimal('ads_spent', 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('total_leads')->default(0);
            $table->unsignedInteger('catered_leads')->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dsppr_entries');
    }
};
