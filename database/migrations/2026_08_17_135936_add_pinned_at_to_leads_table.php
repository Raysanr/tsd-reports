<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Null = not pinned. A TSA pinning a lead sorts it to the top of
            // their list (see LeadController::index()'s ordering and the new
            // togglePin() action) — timestamp rather than a bare boolean so
            // multiple pinned leads still order most-recently-pinned-first.
            $table->timestamp('pinned_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('pinned_at');
        });
    }
};
