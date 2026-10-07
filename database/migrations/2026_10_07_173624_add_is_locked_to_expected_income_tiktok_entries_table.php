<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A TikTok card lock toggle (explicit request, 2026-10-07: "i want to
 * have like lock icon too") — reverses the original table migration's own
 * "no lock toggle (manual-only card)" decision, now that Salaries is a
 * computed (not manual) figure on these cards. Same column/semantics as
 * ExpectedIncomeEntry.is_locked — freezes every editable field on ONE
 * card (one lock per card, same granularity as a real product card's
 * lock, not per-TSA).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('expected_income_tiktok_entries', function (Blueprint $table) {
            $table->boolean('is_locked')->default(false)->after('entry_date');
        });
    }

    public function down(): void
    {
        Schema::table('expected_income_tiktok_entries', function (Blueprint $table) {
            $table->dropColumn('is_locked');
        });
    }
};
