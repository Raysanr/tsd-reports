<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sync_runs.error_message was created as string() (varchar 255) — a real
 * cURL/HTTP error message (the full request URL, byte counts, etc.) regularly
 * exceeds that, which throws its OWN "Data too long for column" exception on
 * the INSERT meant to record the original failure, masking it entirely
 * (confirmed live 2026-08-18: a Pancake API timeout during Sync Health's "Fix
 * Now" reconciliation surfaced as this truncation error instead of the real
 * "Reconciliation failed" message, leaving the button stuck with no
 * feedback). lead_sync_runs already learned this lesson at table-creation
 * time (see that migration's own comment) — this widens sync_runs to match,
 * same text() column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->text('error_message')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->string('error_message')->nullable()->change();
        });
    }
};
