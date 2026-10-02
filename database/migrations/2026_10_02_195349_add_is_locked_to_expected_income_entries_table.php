<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income 2026 (explicit request, 2026-10-02:
 * "can you create lock icon too in this, like in the projections" — same
 * "when it is lock it can't edit" behavior Projections' own Opening/Closing
 * Shift cards already have, see add_is_locked_to_projection_columns_table's
 * own doc comment). One per-day PRODUCT card gets its own lock (explicit
 * decision, same day: per-product-card, not per-TSA or page-wide) — every
 * field on a locked card becomes non-editable, without touching its stored
 * values. A grouped card (ProductGrouping::rows()) locks/unlocks via its
 * own first member product's row, same "writes land on the first member"
 * convention ExpectedIncomeController::update() already uses for saves.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('tsa_id');
        });
    }

    public function down(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
