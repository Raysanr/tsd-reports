<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TIKTOK ORDERS' own 4 rate columns — Excess Leads, Pick-up Rate,
 * Conversion Rate, Upselling Rate — are pure formulas everywhere else on
 * this page (DsPprCalculator::derive(), computed from total_leads/
 * catered_leads/total_orders), but explicit request 2026-10-07 wants them
 * directly editable on the manual-only TIKTOK ORDERS row specifically.
 * Nullable OVERRIDE columns, not a replacement for the formula — null
 * (the default, and what every row has until someone types a value) means
 * "keep using the computed figure", same "blank falls back to the
 * formula" convention as Cost Breakdown's "Daily Rate / Product"/ Expected
 * Income's Tax Allocation overrides elsewhere in this app. Percent
 * columns store the same fraction convention as DsPprCalculator::derive()'s
 * own output (0.25 = 25%), not a raw percentage number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dsppr_tiktok_entries', function (Blueprint $table) {
            $table->unsignedInteger('excess_leads_override')->nullable()->after('catered_leads');
            $table->decimal('pickup_rate_override', 8, 4)->nullable()->after('excess_leads_override');
            $table->decimal('conversion_rate_override', 8, 4)->nullable()->after('pickup_rate_override');
            $table->decimal('upselling_rate_override', 8, 4)->nullable()->after('conversion_rate_override');
        });
    }

    public function down(): void
    {
        Schema::table('dsppr_tiktok_entries', function (Blueprint $table) {
            $table->dropColumn(['excess_leads_override', 'pickup_rate_override', 'conversion_rate_override', 'upselling_rate_override']);
        });
    }
};
