<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income (explicit request, 2026-09-28: a
 * real template screenshot showing a new "Standard Cost Per Message" row,
 * 42.50 in the example, alongside ROAS/Actual Cost Per Message). A plain
 * manual input, same convention as roas/actual_cost_per_lead — not derived
 * from anything else on this page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->decimal('standard_cost_per_message', 10, 2)->default(0)->after('roas');
        });
    }

    public function down(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->dropColumn('standard_cost_per_message');
        });
    }
};
