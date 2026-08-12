<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ported from call-tracker (merged into one app 2026-08-12), renamed from
 * that app's own `sync_runs`/`SyncRun` — tsd-reports already has an
 * unrelated `sync_runs` table (Order-sync stats: total_synced/new_orders/
 * upsell_count/upsell_sales) with genuinely different columns, so this is
 * kept as a distinct table for the Lead-sync job (SyncPancakeLeads) rather
 * than merged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_sync_runs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('ran_at');
            $table->unsignedInteger('total_fetched')->default(0);
            $table->unsignedInteger('new_leads')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->boolean('success')->default(true);
            // text(), not string() — a real cURL/HTTP error message
            // regularly exceeds varchar(255).
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('ran_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_sync_runs');
    }
};
