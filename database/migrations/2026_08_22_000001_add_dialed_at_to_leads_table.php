<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Explicit request (2026-08-22): the Leads table had no way to see whether a
 * TSA had actually dialed a customer yet, only whether an outcome had been
 * logged for the call (status='called', set once a disposition is chosen —
 * see LeadController::updateDisposition()). Deliberately a SEPARATE column
 * from called_at, not a repurposing of it: called_at means "a disposition
 * was recorded", dialed_at means "the phone number was clicked" — a TSA can
 * dial and still be mid-call (or the call can drop) well before either a
 * disposition or called_at exists. Set in LeadController::logCallClick(),
 * the same click handler that already fires on every tel: link click.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->timestamp('dialed_at')->nullable()->after('called_at');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn('dialed_at');
        });
    }
};
