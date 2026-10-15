<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Display-only "hide this TSA" flag (explicit request, 2026-10-15: "add eye
 * icon like if that is clicked it will all hide that tsa to all pages even
 * she has data"). Hides her ROW on report pages only — her orders still
 * count in every team/product total, and lead assignment/login are
 * unaffected (that's what the separate `active` flag is for).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->boolean('is_hidden')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('is_hidden');
        });
    }
};
