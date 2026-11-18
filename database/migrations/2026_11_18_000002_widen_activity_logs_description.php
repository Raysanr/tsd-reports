<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saving Page Access 500'd in production (2026-11-18): its Activity Log line
 * lists every page switched off, which ran past the 255-character limit of
 * activity_logs.description. Any long description fits now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->text('description')->change();
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('description')->change();
        });
    }
};
