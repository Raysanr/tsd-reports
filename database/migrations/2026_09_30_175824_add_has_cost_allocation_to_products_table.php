<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cost Breakdown's "Cost Allocation Per TSA" product columns (explicit
 * request, 2026-09-30: "is it possible that can be select which product
 * will be divided? ... user only can identify what product that has
 * cost"). Defaults to false, not true — same "start empty, admin opts
 * each one in" state as the real sheet's own template, which only ever
 * had 7 of its product columns actually filled in (the rest left blank
 * rather than every product dividing the cost by default).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('has_cost_allocation')->default(false)->after('is_hidden');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('has_cost_allocation');
        });
    }
};
