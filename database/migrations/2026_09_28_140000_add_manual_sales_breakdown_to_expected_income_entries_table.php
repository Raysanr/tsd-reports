<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income (explicit request, 2026-09-28:
 * "in the expected i want you to make all is manually input" for Gross
 * Sales, Cancelled, Projected Returns, and Projected Delivered). These 4
 * used to be derived — Gross Sales = Orders × AOV, then Cancelled/Returns/
 * Delivered as fixed 5%/25%/70% rates of that — see
 * ExpectedIncomeCalculator::derive()'s own doc comment for the confirmed-
 * exact rates this replaces. Now plain typed-in dollar amounts, same
 * convention as tax_allocation/product_cost (manual, not a computed rate).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->decimal('gross_sales', 12, 2)->default(0)->after('average_order_value');
            $table->decimal('cancelled', 12, 2)->default(0)->after('gross_sales');
            $table->decimal('returns', 12, 2)->default(0)->after('cancelled');
            $table->decimal('delivered', 12, 2)->default(0)->after('returns');
        });
    }

    public function down(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->dropColumn(['gross_sales', 'cancelled', 'returns', 'delivered']);
        });
    }
};
