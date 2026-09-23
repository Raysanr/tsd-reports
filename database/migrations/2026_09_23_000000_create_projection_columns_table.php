<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-23: "create
 * new module TSD DATA MANAGEMENT... i want you to make all is editable...
 * like this [Google Sheet link]... the blue highlight PROJECTIONS").
 *
 * One row per P&L "column" shown side by side on the Projections page
 * (Telesales Department, Opening Shift: Monthly Target, Individual TSA:
 * Monthly Target, Individual TSA: Daily Target, confirmed live against the
 * real source sheet's own 4 columns). Every column shares the exact same
 * formula chain (verified against real sheet numbers, not guessed):
 *   Total Orders Needed = Net Income Target / (Average Order Value × Target
 *   Margin %) — confirmed exact for all 4 real columns, including Telesales
 *   Department itself (1,200,000 / (800 × 31.25%) = 4,800, matching the
 *   sheet's own stated # of Orders exactly) — so this table only ever
 *   stores the INPUTS (target, AOV, TSA count), never the derived Orders/
 *   Gross Sales/Net Income figures themselves, which ProjectionCalculator
 *   computes fresh from these inputs plus the shared rate Settings every
 *   time the page renders — same "single source of truth, never two
 *   hand-kept-in-sync copies" convention as the rest of this app (e.g.
 *   ProductPerformance::DISPOSITION_KEYWORDS' own doc comment).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projection_columns', function (Blueprint $table) {
            $table->id();
            // Stable machine key (e.g. 'telesales_department') — the
            // column's own $label is separately editable display text, but
            // this key is what code/routes reference, so renaming a
            // column's label on screen never breaks anything.
            $table->string('key')->unique();
            $table->string('label');
            $table->decimal('net_income_target', 14, 2)->default(0);
            $table->decimal('average_order_value', 12, 2)->default(0);
            // How many TSAs this column represents — Telesales Department
            // (12), Opening Shift (6), an Individual TSA column (1) — shown
            // in the target card ("Current TSAs" / "TSA") and available for
            // future per-TSA breakdowns, even though it doesn't enter the
            // Orders-Needed formula itself.
            $table->unsignedInteger('tsa_count')->default(1);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seeded with the real source sheet's own current values (confirmed
        // live, not guessed) — a fresh install/deploy shows the same
        // numbers the sheet had, not a blank page nobody's ever seen.
        $now = now();
        DB::table('projection_columns')->insert([
            ['key' => 'telesales_department', 'label' => 'Telesales Department', 'net_income_target' => 1200000, 'average_order_value' => 800, 'tsa_count' => 12, 'sort_order' => 0, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'opening_shift', 'label' => 'Opening Shift: Monthly Target', 'net_income_target' => 600000, 'average_order_value' => 800, 'tsa_count' => 6, 'sort_order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'individual_tsa_monthly', 'label' => 'Individual TSA: Monthly Target', 'net_income_target' => 100000, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'individual_tsa_daily', 'label' => 'Individual TSA: Daily Target', 'net_income_target' => 4200, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 3, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('projection_columns');
    }
};
