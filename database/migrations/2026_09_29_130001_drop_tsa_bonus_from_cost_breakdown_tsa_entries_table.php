<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown (explicit follow-up, 2026-09-29:
 * "there's no bonus on the sheets so it should no bonus in that" — see
 * add_shared_bonus_span_to_cost_breakdown_roles_table's own doc comment
 * for the full context). Each TSA's own base_salary now IS her real full
 * monthly total (e.g. Julie Francisco's own base_salary is typed as
 * 40,388.75 directly) — no separate bonus field anywhere on this page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_breakdown_tsa_entries', function (Blueprint $table) {
            $table->dropColumn('tsa_bonus');
        });
    }

    public function down(): void
    {
        Schema::table('cost_breakdown_tsa_entries', function (Blueprint $table) {
            $table->decimal('tsa_bonus', 12, 2)->default(0)->after('base_salary');
        });
    }
};
