<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12). One row per
 * product — remembers whose turn is next in that product's rotation (see
 * product_tsa's 'position' column for the order itself). last_tsa_id is
 * null before that product's queue has ever been used at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('round_robin_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('last_tsa_id')->nullable()->constrained('tsa_shifts')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('round_robin_states');
    }
};
