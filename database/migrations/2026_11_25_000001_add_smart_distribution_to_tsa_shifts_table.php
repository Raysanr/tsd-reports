<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Per-TSA opt-in for Smart distribution (2026-11-25) — only applies while the
 *  global switch is on. Default true: everyone is in until unticked. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->boolean('smart_distribution')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('smart_distribution');
        });
    }
};
