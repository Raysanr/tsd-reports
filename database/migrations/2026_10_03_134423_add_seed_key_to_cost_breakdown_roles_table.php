<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * TSD Data Management — Cost Breakdown (explicit request, 2026-10-03: "i
 * want to make it roles is editable like role and name" — CEO/Sales
 * Director/Telesales Manager/etc.'s own `label` and `person_name` become
 * real editable inputs, not plain text re-synced from
 * CostBreakdownRole::SEED_ROLES on every page load like before this
 * migration).
 *
 * `ensureSeeded()` used to match an existing row by `label` alone
 * (`firstOrCreate(['label' => $seed['label']], ...)`) — fine while label
 * was never editable, but renaming a role's own label would otherwise
 * orphan the renamed row (ensureSeeded() no longer finds it under the OLD
 * label) and silently firstOrCreate a brand-new duplicate under the
 * original seed label on the very next page load. `seed_key` is a stable
 * identity independent of what's actually displayed — ensureSeeded()
 * matches on THIS from here on, never `label`.
 *
 * Also doubles as the "is this one of the 7 fixed roles, or a custom one
 * added via the + button" flag the view/controller need: a row with a
 * real seed_key is seeded (its own structural fields re-sync every load,
 * same as before — just label/person_name/base_salary now excluded from
 * that, see ensureSeeded()'s own doc comment); a row with seed_key = null
 * is custom (admin-added, deletable, nothing to re-sync).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->string('seed_key')->nullable()->unique()->after('id');
        });
    }

    public function down(): void
    {
        Schema::table('cost_breakdown_roles', function (Blueprint $table) {
            $table->dropColumn('seed_key');
        });
    }
};
