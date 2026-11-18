<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Don't count in # of TSA" (explicit request, 2026-11-18): someone on the
 * roster who isn't a selling TSA (e.g. Jhun) stays on every report but is
 * left out of Overall Sales Summary's # of TSA (working and total).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tsa_shifts', 'exclude_from_tsa_count')) {
            Schema::table('tsa_shifts', function (Blueprint $table) {
                $table->boolean('exclude_from_tsa_count')->default(false)->after('is_hidden');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tsa_shifts', 'exclude_from_tsa_count')) {
            Schema::table('tsa_shifts', fn (Blueprint $table) => $table->dropColumn('exclude_from_tsa_count'));
        }
    }
};
