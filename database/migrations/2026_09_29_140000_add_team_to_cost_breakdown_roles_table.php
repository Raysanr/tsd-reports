<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown (explicit follow-up, 2026-09-29,
 * after a closer side-by-side screenshot comparison: "the supervisor of
 * opening and closing is in the rows of their TSA's" — the real sheet
 * nests each shift Supervisor directly above her own team's real TSAs in
 * ONE continuous section, not as a flat standalone role list with the TSAs
 * shown separately elsewhere on the page).
 *
 * team is nullable and only ever set on the two Supervisor roles (matches
 * TsaShift's own `team` column values exactly — 'Eyecare Team'/
 * 'SH Naturals', confirmed against TeamShiftWindow's own OPENING_TEAM/
 * CLOSING_TEAM constants as the single source of truth for which literal
 * string means which shift) — the controller uses it to interleave that
 * team's own real TsaShift rows directly beneath her when building the
 * page's render list. Every other role (CEO, Sales Director, Telesales
 * Manager, QA Specialist, Junior AI Engineer) has no team at all, since
 * none of them has any TSAs nested under them in the real sheet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->string('team')->nullable()->after('person_name');
        });
    }

    public function down(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->dropColumn('team');
        });
    }
};
