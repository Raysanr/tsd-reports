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
        Schema::table('tsa_shifts', function (Blueprint $table) {
            // Max leads round-robin may assign to this TSA today (resets
            // automatically at midnight — computed live off leads.assigned_at
            // rather than a counter that would need a daily reset job). Null
            // means unlimited, the default so existing TSAs are unaffected.
            $table->unsignedInteger('daily_lead_cap')->nullable()->after('active');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tsa_shifts', function (Blueprint $table) {
            $table->dropColumn('daily_lead_cap');
        });
    }
};
