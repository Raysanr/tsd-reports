<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown (explicit follow-up, 2026-09-29,
 * after the user clicked into the real sheet's own cell and shared its
 * formula bar: "look at this formula ... it is all divided of all 12
 * tsa"). What this app previously modeled as a manually-typed "shared
 * bonus" (10,341.13 / 4,833.33 / 5,714.29) is actually a LIVE FORMULA in
 * the real sheet:
 *
 *   - CEO's own cell:          =(C5+C6+C7)/12   → (44,095.59+19,998.00+60,000.00)/12 = 10,341.13
 *   - QA Specialist's own cell: =(C9+C10)/12     → (28,000.00+30,000.00)/12 = 4,833.33
 *   - Each Supervisor's own cell: =C12/6 (or /C-whatever-her-own-row-is) → 34,285.71/6 = 5,714.285
 *
 * i.e. each leadership group's own combined base_salary, divided by a TSA
 * headcount — the executive/support groups divide by the TOTAL real TSA
 * count, a Supervisor divides by just HER OWN team's real TSA count. This
 * was never a stored bonus figure at all — it's computed fresh from
 * base_salary + the live TsaShift roster, confirmed exact against every
 * one of the sheet's 3 real numbers (see CostBreakdownRoleTest and
 * CostBreakdownRole::overheadPerTsa()'s own doc comment).
 *
 * overhead_group: which OTHER roles this role's own base_salary sums with
 * before dividing ('executive' = CEO+Sales Director+Telesales Manager,
 * 'support' = QA Specialist+Junior AI Engineer, 'supervisor' = each
 * Supervisor alone, divided by her own team instead of the total).
 * overhead_divisor: 'total' (every real TSA, company-wide) or 'team' (just
 * this role's own team's real TSAs — Supervisor rows only, see `team`
 * above). Replaces shared_bonus (a manually-typed, always-drifts-from-
 * reality number) and shared_bonus_span (a pure rendering hint derivable
 * from overhead_group's own member count instead).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->string('overhead_group')->nullable()->after('team');
            $table->string('overhead_divisor')->nullable()->after('overhead_group');
            $table->dropColumn(['shared_bonus', 'shared_bonus_span']);
        });
    }

    public function down(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->decimal('shared_bonus', 12, 2)->nullable()->after('overhead_divisor');
            $table->unsignedInteger('shared_bonus_span')->default(1)->after('shared_bonus');
            $table->dropColumn(['overhead_group', 'overhead_divisor']);
        });
    }
};
