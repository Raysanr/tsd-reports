<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A merged-away duplicate's roster link (TSA Management row or partner TSA
 *  key), kept on the person it was merged into so the syncs don't bring
 *  the duplicate back (2026-11-21, PeopleMergeController). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_merged_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->cascadeOnDelete();
            $table->string('kind', 20); // tsa_shift | partner_tsa
            $table->string('value', 120);
            $table->timestamps();
            $table->unique(['kind', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_merged_links');
    }
};
