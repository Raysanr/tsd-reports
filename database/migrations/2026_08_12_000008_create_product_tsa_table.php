<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). Defines each
 * product's own round-robin roster — which TSAs handle it, and their
 * rotation order (position). A product with no rows here has nobody to
 * assign its leads to (surfaced, not silently dropped — see
 * SyncPancakeLeads).
 *
 * Deliberately NOT seeded here: unlike call-tracker's original migration
 * (which inserted fresh `products`/`tsas` rows it had just created in
 * sibling migrations), this table now references tsd-reports' pre-existing
 * `products`/`tsa_shifts` rows, which must be matched by name/key rather
 * than assumed to exist at a predictable ID — see the reconciliation
 * seeder in Phase 4 of the merge plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_tsa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tsa_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['product_id', 'tsa_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_tsa');
    }
};
