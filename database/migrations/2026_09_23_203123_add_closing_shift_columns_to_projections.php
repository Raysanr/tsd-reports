<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * TSD Data Management — Projections (explicit request, 2026-09-23: "okay
 * now in the downpart is the closing team").
 *
 * The source sheet has a full SECOND shift block — "Closing Shift" — with
 * its own independently different numbers from Opening Shift (confirmed
 * during the earlier cross-card formula investigation: Opening Shift's own
 * NET INCOME 623,609.67 vs. Closing Shift's own 622,568.00 — different
 * enough that Telesales Department = Opening × 2 was only ever a
 * shortcut, not a true rollup). This migration:
 *
 *  1. Renames the existing 'individual_tsa_monthly'/'individual_tsa_daily'
 *     rows to 'opening_individual_tsa_monthly'/'opening_individual_tsa_daily'
 *     — they were always OPENING Shift's own derived pair, but the key
 *     didn't say so because there was no Closing pair to disambiguate
 *     from yet.
 *  2. Inserts 'closing_shift' (seeded with the sheet's own real numbers:
 *     Net Income Target 600,000, AOV 800, Current TSAs 6 — same target as
 *     Opening, independently editable from here on) and its own derived
 *     pair, 'closing_individual_tsa_monthly'/'closing_individual_tsa_daily'.
 *
 * ProjectionCalculator's own doc comment covers the updated formula chain
 * (Telesales Department = Opening + Closing, not Opening × 2, as of this
 * change — explicit decision, 2026-09-23, asked directly once Closing
 * Shift became independently editable).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('projection_columns')
            ->where('key', 'individual_tsa_monthly')
            ->update(['key' => 'opening_individual_tsa_monthly', 'label' => 'Opening — Individual TSA: Monthly Target']);

        DB::table('projection_columns')
            ->where('key', 'individual_tsa_daily')
            ->update(['key' => 'opening_individual_tsa_daily', 'label' => 'Opening — Individual TSA: Daily Target']);

        $now = now();
        DB::table('projection_columns')->insert([
            ['key' => 'closing_shift', 'label' => 'Closing Shift: Monthly Target', 'net_income_target' => 600000, 'average_order_value' => 800, 'tsa_count' => 6, 'sort_order' => 4, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'closing_individual_tsa_monthly', 'label' => 'Closing — Individual TSA: Monthly Target', 'net_income_target' => 100000, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 5, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'closing_individual_tsa_daily', 'label' => 'Closing — Individual TSA: Daily Target', 'net_income_target' => 4200, 'average_order_value' => 800, 'tsa_count' => 1, 'sort_order' => 6, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('projection_columns')->whereIn('key', [
            'closing_shift', 'closing_individual_tsa_monthly', 'closing_individual_tsa_daily',
        ])->delete();

        DB::table('projection_columns')
            ->where('key', 'opening_individual_tsa_monthly')
            ->update(['key' => 'individual_tsa_monthly', 'label' => 'Individual TSA: Monthly Target']);

        DB::table('projection_columns')
            ->where('key', 'opening_individual_tsa_daily')
            ->update(['key' => 'individual_tsa_daily', 'label' => 'Individual TSA: Daily Target']);
    }
};
