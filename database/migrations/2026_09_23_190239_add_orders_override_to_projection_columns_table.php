<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-23: "the
 * Gross Sales is editable and the number of orders").
 *
 * # of Orders was always fully DERIVED (Net Income Target ÷ (AOV × Target
 * Margin %)) — there was no way to type a number directly into that cell.
 * This adds a nullable override: null means "keep deriving it from the
 * target formula, same as always"; a set value means the user typed a
 * direct # of Orders (or an equivalent Gross Sales, back-solved to
 * orders ÷ AOV client-side before saving — see projections.blade.php's own
 * saveRate() dollar-mode branch) and that number now drives Gross Sales
 * and every cost line below it instead. Nullable, not defaulted to 0, so
 * "never overridden" stays distinguishable from "overridden to zero
 * orders" — ProjectionCalculator::forColumn() checks for null, not falsy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->decimal('orders_override', 14, 4)->nullable()->after('average_order_value');
        });
    }

    public function down(): void
    {
        Schema::table('projection_columns', function (Blueprint $table) {
            $table->dropColumn('orders_override');
        });
    }
};
