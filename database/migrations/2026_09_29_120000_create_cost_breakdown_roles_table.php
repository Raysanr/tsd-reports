<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown (explicit request, 2026-09-29: "add
 * new page in data management (COST BREAKDOWN) ... like this sheets", a
 * real "TSD Payroll" spreadsheet). Its own top section (CEO, Sales
 * Director, Telesales Manager, QA Specialist, Junior AI Engineer, and each
 * shift's own Supervisor) is bespoke HR data with no derivable formula
 * anywhere else in this app — no CEO/Sales Director/Manager model exists,
 * and the real sheet's own numbers don't follow one universal rule (e.g.
 * the CEO carries her own 10,341.13 bonus, confirmed against the sheet's
 * own raw CSV export rather than assumed from the rendered layout — it's
 * easy to misread as the Sales Director's own bonus from a screenshot
 * alone, since the two rows sit visually close together). Every field
 * here is a plain manual input, seeded from the real sheet's own starting
 * values — same "can't be derived, so it's typed" convention as Tax
 * Allocation/Product Cost on Expected Income.
 *
 * Distinct from cost_breakdown_tsa_entries (below) — that table is the
 * REAL TsaShift roster (tsa_id-keyed, always in sync with TSA Management);
 * this one is for roles that have no TsaShift row at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cost_breakdown_roles', function (Blueprint $table) {
            $table->id();
            $table->string('label'); // e.g. "CEO", "Sales Director", "Telesales Supervisor (Opening Shift)"
            $table->string('person_name')->nullable();
            $table->decimal('base_salary', 12, 2)->default(0);
            $table->decimal('shared_bonus', 12, 2)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_breakdown_roles');
    }
};
