<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income (explicit request, 2026-09-28: "in
 * the projection + icon when user add and the added should be reflect too
 * to the expected income right like in product cards there's Selling And
 * Marketing and Operating Costs in that right?").
 *
 * A row ADDED via the + icon on Projections (projection_custom_rows) is a
 * shared DEFINITION table, not scoped to Projections — Expected Income
 * reads the same table (see ExpectedIncomeCalculator::sellingCostRows()/
 * operatingCostRows()) so a row created there shows up here too, same
 * label/section, as a new blank manual-entry field (explicit decision:
 * just the row NAME reflects over, not a computed value — Projections'
 * custom rows store a %-of-Gross-Sales RATE shared across columns, while
 * Expected Income's own rows are plain manual dollar amounts typed in per
 * PRODUCT per DAY, a fundamentally different value model that can't be
 * auto-computed from the other).
 *
 * expected_income_entries itself can't grow a new column every time a row
 * is added on Projections — its schema is fixed, one real column per
 * built-in row (see that migration's own doc comment). This table is the
 * flexible side-store for exactly that: one row per (product, day,
 * custom_row_key), keyed by projection_custom_rows.key (never its
 * autoincrement id — same "stable slug, not a renamable label" reasoning
 * as that table's own key column), holding just that one entry's typed-in
 * dollar value for that custom row. Absence of a row here means "0.00,
 * never entered," same default-to-zero convention as every built-in
 * column on expected_income_entries.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expected_income_custom_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->date('entry_date');
            $table->string('custom_row_key');
            $table->decimal('value', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['product_id', 'entry_date', 'custom_row_key'], 'ei_custom_values_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expected_income_custom_values');
    }
};
