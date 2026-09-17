<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shift-handover fairness fix (explicit request, 2026-09-17: "it should
 * not be bulk all redistribute to the one tsa that got first login") —
 * roster_available_since stamps the moment a product's eligible TSA
 * roster went from empty to non-empty (see RoundRobinAssigner::next()'s
 * own comment on ELIGIBLE_STATUSES). SyncPancakeLeads::catchUpUnassignedLeads()
 * holds off assigning that product's backlog until GAP_BUFFER_MINUTES
 * have passed since this timestamp, giving the rest of a closing/opening
 * team a chance to log in too instead of the whole backlog landing on
 * whoever happened to log in first. Null means either the roster has
 * never had anyone (untouched product) or is currently empty again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('round_robin_states', function (Blueprint $table) {
            $table->timestamp('roster_available_since')->nullable()->after('last_tsa_id');
        });
    }

    public function down(): void
    {
        Schema::table('round_robin_states', function (Blueprint $table) {
            $table->dropColumn('roster_available_since');
        });
    }
};
