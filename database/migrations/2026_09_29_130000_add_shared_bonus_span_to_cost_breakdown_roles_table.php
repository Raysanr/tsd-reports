<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown (explicit follow-up, 2026-09-29,
 * after a closer screenshot review: "there's no bonus on the sheets so it
 * should no bonus in that"). Confirmed the CEO/Sales Director/Telesales
 * Manager's own 10,341.13 figure and QA Specialist/Junior AI Engineer's
 * own 4,833.33 figure are each a SINGLE cell visually merged/centered
 * across multiple rows in the real sheet, not a per-person bonus added
 * into anyone's own salary total — TOTAL SALARY OF TSD only ever sums the
 * real TSAs' own totals regardless (see CostBreakdownController's own doc
 * comment), so these two figures were never part of any real total to
 * begin with.
 *
 * shared_bonus stays on the role it's typed on (the FIRST row of its own
 * visual span, matching the real sheet's own merged-cell anchor); this
 * span field says how many rows starting from THIS one it visually
 * covers, purely for rendering the same merged-cell look — 3 for CEO
 * (CEO + Sales Director + Telesales Manager), 2 for QA Specialist (QA
 * Specialist + Junior AI Engineer), null/1 for everyone else. Never
 * added into base_salary or any total anywhere.
 *
 * The Supervisor/TSA rows' OWN shared_bonus concept is removed entirely
 * (confirmed the real numbers there — e.g. Julie Francisco's 40,388.75 —
 * are simply typed as base_salary directly now, no separate bonus field
 * at all, see the corresponding CostBreakdownTsaEntry change).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->unsignedInteger('shared_bonus_span')->default(1)->after('shared_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->dropColumn('shared_bonus_span');
        });
    }
};
