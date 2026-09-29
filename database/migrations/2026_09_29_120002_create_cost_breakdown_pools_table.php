<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown's 21 shared monthly cost pools
 * (Communication Allowance, 13th Month Allowance, SIL, ... HMO Expense —
 * see create_cost_breakdown_roles_table's own doc comment for the page's
 * full context). One flat monthly PESO AMOUNT per pool, manually typed —
 * confirmed against the real sheet these are genuinely independent inputs
 * with no shared rate or formula behind them (unlike, say,
 * ProjectionCalculator's own %-of-Gross-Sales rows).
 *
 * Split across every TSA's own row by her % share of total days worked
 * (see CostBreakdownCalculator's own doc comment) — this table only ever
 * stores the ONE shared monthly total per pool, never a per-TSA value.
 * key is a stable slug (e.g. 'communication_allowance') the calculator/
 * view read by; label is the real display name, kept separate so a label
 * can be edited later without breaking every reference to the pool's key.
 *
 * Salaries is NOT a row here — it's computed fresh from cost_breakdown_
 * roles + cost_breakdown_tsa_entries' own totals (TOTAL SALARY OF TSD),
 * never a second, independently-typed number that could drift from the
 * real payroll total above it on the same page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_breakdown_pools', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_breakdown_pools');
    }
};
