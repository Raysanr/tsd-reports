<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Actual Income's typed overrides (2026-11-17) — Number of Orders, Product
 *  Cost and Fulfillment Fee are automated there but editable; a value typed
 *  on Actual Income lives here, never on Expected Income's own entries. Null
 *  = use the automated value. See App\Support\ActualIncomeOverrides. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('actual_income_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tsa_id')->nullable();
            $table->date('entry_date');
            $table->decimal('number_of_orders', 12, 2)->nullable();
            $table->decimal('product_cost', 14, 2)->nullable();
            $table->decimal('fulfillment_fee', 14, 2)->nullable();
            $table->timestamps();
            $table->unique(['product_id', 'tsa_id', 'entry_date']);
            $table->index('entry_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('actual_income_overrides');
    }
};
