<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-28: "make
 * editable this Number of Leads, Conversion Rate, Average Order Value" —
 * Average Order Value was already editable via the existing
 * average_order_value column; this adds overrides for the other two).
 *
 * Same nullable-override convention as orders_override/
 * upselling_rate_override: null means "keep deriving from Net Income
 * Target as always"; a set value means the user typed a direct number.
 * Confirmed with the user (2026-09-28): typing BOTH Number of Leads and
 * Conversion Rate recalculates Number of Orders as Leads × Conversion Rate,
 * taking precedence over the Net Income Target back-solve — same
 * override-beats-target precedence tier orders_override already has, just
 * one step earlier in the chain (Leads × Conversion → Orders, rather than
 * Orders typed directly). Nullable, not defaulted to 0, so "never
 * overridden" stays distinguishable from "overridden to zero" —
 * ProjectionCalculator checks for null on both, not falsy, and only
 * applies this override when BOTH are set (a lone leads_override with no
 * matching conversion rate has no way to produce an Orders number).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->decimal('leads_override', 12, 2)->nullable()->after('orders_override');
            $table->decimal('conversion_rate_override', 8, 6)->nullable()->after('leads_override');
        });
    }

    public function down(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->dropColumn(['leads_override', 'conversion_rate_override']);
        });
    }
};
