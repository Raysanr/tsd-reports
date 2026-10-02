<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections / Expected Income (explicit request,
 * 2026-10-02: "can you make the row can be draggable and can change the
 * position by other row", confirmed to cover EVERY row — built-in
 * (Salaries, COD Fee, Advertising Cost, ...) AND custom (added via the +
 * icon) — and ONE shared order across both pages, since they already read
 * the exact same row list (ProjectionCalculator::sellingCostRows()/
 * operatingCostRows(), mirrored by ExpectedIncomeCalculator's own
 * identically-keyed copy).
 *
 * Deliberately a SEPARATE table from projection_custom_rows, not an
 * is_builtin flag bolted onto it — projection_custom_rows is read in 10
 * files that all assume "a row in this table = a real user-added custom
 * row" (is_fixed checks, the + icon's own CRUD, the delete-row confirm,
 * etc.); folding built-ins in there too would mean auditing and guarding
 * every one of those call sites against a flag they don't expect. This
 * table instead holds ONE sort_order per row key — built-in or custom,
 * 'selling' or 'operating' section — and is the only thing RowOrder::rows()
 * (see app/Support/RowOrder.php) reads to produce the final merged,
 * ordered row list both calculators' own sellingCostRows()/
 * operatingCostRows() delegate to. A built-in row gets its entry here
 * lazily (seeded from its current PHP-array position) the first time it's
 * ever read or dragged; a custom row gets its entry here the moment it's
 * created, replacing projection_custom_rows' own now-unused sort_order
 * column as the real ordering source (that column stays, just unread from
 * here on, to avoid touching the create/delete custom-row flow itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('row_sort_orders', function (Blueprint $table) {
            $table->id();
            $table->string('row_key');
            $table->enum('section', ['selling', 'operating']);
            $table->unsignedInteger('sort_order');
            $table->timestamps();

            $table->unique(['row_key', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('row_sort_orders');
    }
};
