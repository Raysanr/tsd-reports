<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Summary Sales Report. One TSA row's raw numbers
 * for one day — confirmed-exact NI% (Net Income ÷ Gross Sales) and AOV
 * (Gross Sales ÷ Total Orders) are derived fresh, never stored, same
 * "store inputs only" convention as dsppr_entries.
 *
 * pickup_rate and upselling_rate are RAW manual-entry fields here, not
 * derived (explicit decision, 2026-09-24, after two independent sheets —
 * DSPPR and this one — both failed every formula reconstruction attempt
 * against Gross Sales/Net Income/Ads Spent/Orders/AOV/Catered Leads; see
 * DsPprCalculator's own doc comment for the full evidence trail). No
 * Total Leads/Excess Leads/Conversion Rate columns at all on this sheet
 * — a smaller column set than DSPPR's own, confirmed against the real
 * screenshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_sales_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_sales_row_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('net_income', 14, 2)->default(0);
            $table->decimal('ads_spent', 14, 2)->default(0);
            $table->unsignedInteger('total_orders')->default(0);
            $table->unsignedInteger('catered_leads')->default(0);
            $table->decimal('pickup_rate', 7, 4)->default(0);
            $table->decimal('upselling_rate', 7, 4)->default(0);
            $table->timestamps();

            $table->unique(['tsa_sales_row_id', 'entry_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tsa_sales_entries');
    }
};
