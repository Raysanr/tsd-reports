<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown's own per-TSA payroll numbers
 * (explicit request, 2026-09-29 — see create_cost_breakdown_roles_table's
 * own doc comment for the page's full context). One row per REAL TsaShift
 * — always in sync with TSA Management's own roster, unlike the real
 * sheet's own static 12-name snapshot (this app's live roster is smaller
 * right now; explicit decision to use the real roster rather than an
 * independent name list, so a TSA added later automatically gets her own
 * row here with zero extra step, same "one roster, every page reads it
 * fresh" convention as Projections/Expected Income/DSPPR's own product
 * lists).
 *
 * base_salary/tsa_bonus: manual inputs (no formula derives a TSA's real
 * salary) — confirmed against the real sheet every TSA gets the exact
 * same flat tsa_bonus (20,888.75 there) added on top of her own base to
 * reach her monthly total, regardless of shift or base amount; kept
 * per-TSA here (not a single shared Setting) so it stays independently
 * editable per person going forward even though today's seed value is
 * identical for everyone.
 *
 * days: THE only field this app also uses in a real formula chain — see
 * CostBreakdownCalculator's own doc comment for how Days drives each
 * TSA's own % share of the shared cost pools below. Defaults to 30 (a
 * full month), matching the real sheet's own uniform starting value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_breakdown_tsa_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tsa_id')->constrained('tsa_shifts')->cascadeOnDelete();
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('tsa_bonus', 12, 2)->default(0);
            $table->unsignedInteger('days')->default(30);
            $table->timestamps();

            $table->unique('tsa_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_breakdown_tsa_entries');
    }
};
