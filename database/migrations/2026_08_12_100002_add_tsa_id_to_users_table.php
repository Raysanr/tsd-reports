<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merge phase 4 (2026-08-12): ported from call-tracker's own
 * add_tsa_id_to_users_table migration. Links a user account to the TSA row
 * they are (for TSA-role users using the Call Tracker section) — nullable,
 * since most tsd-reports users (admins, normal reporting-only users) aren't
 * a TSA at all. Explicitly ->constrained('tsa_shifts'), since the default
 * FK-target inference from column name `tsa_id` would otherwise look for a
 * `tsas` table, which doesn't exist in the merged app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tsa_id')->nullable()->after('role')->constrained('tsa_shifts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tsa_id');
        });
    }
};
