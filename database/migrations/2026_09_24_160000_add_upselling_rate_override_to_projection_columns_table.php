<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-24: make
 * Target Upselling Rate directly editable).
 *
 * Target Upselling Rate was always fully DERIVED (1:1 with Total Orders
 * Needed) — there was no way to type a number directly into that cell. This
 * adds a nullable override, same convention as orders_override: null means
 * "keep mirroring Total Orders Needed, same as always"; a set value means
 * the user typed a direct Target Upselling Rate and that number displays
 * instead. Nullable, not defaulted to 0, so "never overridden" stays
 * distinguishable from "overridden to zero" —
 * ProjectionCalculator::targetCard() checks for null, not falsy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->decimal('upselling_rate_override', 14, 4)->nullable()->after('orders_override');
        });
    }

    public function down(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->dropColumn('upselling_rate_override');
        });
    }
};
