<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DSPPR - TSM Report / Expected Income 2026 — product combining (explicit
 * request, 2026-09-26: "i can drag the TO-01 to TO-02 ... it can have pop
 * up like new name ... it is only combine and also ... it will reflect it
 * to the expected income"). A DISPLAY-ONLY grouping, not a real merge of
 * Product records (explicit decision, 2026-09-26) — the real Product table
 * (used for Pancake order matching everywhere else in the app) is
 * untouched; this only tells DsPprReportController/ExpectedIncomeController
 * to show one combined row/card summing the member products' own already-
 * stored entries instead of listing them separately.
 *
 * A combined row is READ-ONLY on both report pages (explicit decision,
 * 2026-09-26) — to change the real numbers behind it you still have to
 * ungroup first (see product_group_members' own doc comment for the
 * ungroup mechanism), never type directly into the summed figure, so
 * there's never an ambiguous "which member product does this number
 * belong to" question.
 *
 * Grouping works across any two products regardless of team (explicit
 * decision, 2026-09-26) — created by dragging one product row onto
 * another on DSPPR's own table (Expected Income has no drag gesture of
 * its own; it only ever reflects a group DSPPR already created).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_groups', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('product_group_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_group_id')->constrained()->cascadeOnDelete();
            // No cascadeOnDelete here on purpose — deleting a real Product
            // is a separate, rarer admin action (TSA Management) that
            // shouldn't silently rewrite a group's own membership; if a
            // member product is ever deleted, the group is left with a
            // dangling row rather than corrupting itself, same
            // conservative failure mode as a real accounting record.
            $table->foreignId('product_id')->constrained();
            $table->timestamps();

            $table->unique(['product_group_id', 'product_id']);
            // A product can only ever belong to ONE group at a time — two
            // overlapping groups both claiming TO-01 would make "which
            // group's combined row owns this product's entries" undefined.
            $table->unique('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_group_members');
        Schema::dropIfExists('product_groups');
    }
};
