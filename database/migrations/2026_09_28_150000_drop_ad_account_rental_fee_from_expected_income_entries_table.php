<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Expected Income (explicit request, 2026-09-28: a
 * full row-by-row scan against the real template found Ad Account Rental
 * Fee doesn't appear anywhere in the template's own Selling And Marketing
 * section — confirmed with the user it should be removed entirely, not
 * just moved). Every OTHER Selling And Marketing/Operating Costs row
 * matches the template exactly, in the same order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->dropColumn('ad_account_rental_fee');
        });
    }

    public function down(): void
    {
        Schema::table('expected_income_entries', function (Blueprint $table) {
            $table->decimal('ad_account_rental_fee', 14, 2)->default(0)->after('ai_expense');
        });
    }
};
