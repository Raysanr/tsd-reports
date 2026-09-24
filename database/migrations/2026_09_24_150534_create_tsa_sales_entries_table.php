<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Summary Sales Report (explicit request, 2026-09-24:
 * "add page summary sales report ... i want to make it like auto based on
 * the current team and tsa" — reverses the first version of this feature,
 * which had admin-managed free-text TSA rows via now-removed
 * tsa_sales_groups/tsa_sales_rows tables; that version shipped minutes
 * earlier with zero real usage/data, so it's replaced outright rather than
 * migrated forward).
 *
 * One real TsaShift's raw numbers for one day. Grouping on the report
 * page itself is by TsaShift.team (the app's real 2 teams — SH Naturals /
 * Eyecare), NOT the sheet's own Opening Shift / Closing Shift split —
 * TsaShift has no real shift-assignment data (shift_start/shift_end are
 * null for every current TSA), so that distinction can't be reproduced
 * from real data yet (explicit decision, 2026-09-24, after confirming the
 * gap live in tinker).
 *
 * NI% (Net Income ÷ Gross Sales) and AOV (Gross Sales ÷ Total Orders)
 * are derived fresh by TsaSalesCalculator, never stored — same
 * "store inputs only" convention as dsppr_entries. Pick-up Rate and
 * Upselling Rate stay raw manual-entry fields (see TsaSalesCalculator's
 * own doc comment for the evidence neither can be derived on this sheet
 * or DSPPR's separate one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tsa_sales_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_shift_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->decimal('gross_sales', 14, 2)->default(0);
            $table->decimal('net_income', 14, 2)->default(0);
            $table->decimal('ads_spent', 14, 2)->default(0);
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
        Schema::dropIfExists('tsa_sales_entries');
    }
};
