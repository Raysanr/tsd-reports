<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role')->default('normal')->after('avatar');
            $table->boolean('is_active')->default(true)->after('role');
        });

        // One-time backfill: both accounts that exist before this migration ever
        // ran (raysanred0@gmail.com and the seeded lizzie28@example.com row) become
        // Super Admin — see the design spec's "existing accounts become Super
        // Admin" decision. This UPDATE is a no-op on a fresh test database, since
        // RefreshDatabase migrates against an empty users table before any test
        // creates its own rows.
        DB::table('users')->update(['role' => 'super_admin', 'is_active' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['role', 'is_active']);
        });
    }
};
